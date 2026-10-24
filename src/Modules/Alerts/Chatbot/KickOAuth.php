<?php
/**
 * A creator adds the bot to the own Kick channel.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use Closure;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth 2.1 with PKCE at Kick (ADR-069 pkt. 7): the creator logs in with the
 * channel's Kick account and allows the app `channel:read chat:write
 * events:subscribe`. The state is random (128 bit), single-use, valid for
 * ten minutes and bound to the WordPress user and the creator; the PKCE
 * verifier stays on the server inside it. The creator's tokens are stored
 * encrypted (CreatorTokens), the channel's id and slug in the channel row; the
 * bot then subscribes to the channel's chat.
 */
final class KickOAuth {

	/**
	 * Transient prefix of a state.
	 */
	private const STATE_PREFIX = 'merchboard_kick_state_';

	/**
	 * Seconds a state is valid.
	 */
	public const STATE_TTL = 600;

	/**
	 * The dashboard URL of a path.
	 *
	 * @var Closure(string): string
	 */
	private Closure $dashboard_url;

	/**
	 * The step of complete() in progress, for the technical log.
	 *
	 * @var string
	 */
	private string $step = '';

	/**
	 * Constructor.
	 *
	 * @param KickAccount              $account       The Kick app.
	 * @param KickApi                  $api           Kick.
	 * @param CreatorTokens            $tokens        The creators' Kick tokens.
	 * @param ChatbotChannelRepository $channels      The creators' channels.
	 * @param ChatSubscriptions        $subscriptions Chat subscriptions.
	 * @param AuditTrail               $audit         Audit trail.
	 * @param Transaction              $transaction   Database transaction.
	 * @param Closure                  $dashboard_url The dashboard URL of a path.
	 * @param Logger                   $logger        Technical log (failures without tokens).
	 *
	 * @phpstan-param Closure(string): string $dashboard_url
	 */
	public function __construct( private KickAccount $account, private KickApi $api, private CreatorTokens $tokens, private ChatbotChannelRepository $channels, private ChatSubscriptions $subscriptions, private AuditTrail $audit, private Transaction $transaction, Closure $dashboard_url, private Logger $logger ) {
		$this->dashboard_url = $dashboard_url;
	}

	/**
	 * The URL that asks a creator to add the bot to the own channel.
	 *
	 * @param int $creator_id Creator (from CreatorContext).
	 * @throws KickException When the app is not set up.
	 */
	public function start_channel( int $creator_id ): string {
		if ( ! $this->account->has_app() ) {
			throw new KickException( KickException::NOT_CONFIGURED );
		}

		$state    = bin2hex( random_bytes( 16 ) );
		$verifier = KickApi::verifier();
		set_transient(
			self::STATE_PREFIX . $state,
			array(
				'user'     => get_current_user_id(),
				'creator'  => $creator_id,
				'verifier' => $verifier,
			),
			self::STATE_TTL
		);

		return KickApi::authorize_url( $this->account->client_id(), KickAccount::redirect_uri(), KickAccount::CHANNEL_SCOPES, $state, $verifier );
	}

	/**
	 * Finishes an authorization (Kick's redirect) and gives the URL to send
	 * the browser to.
	 *
	 * @param string $state State from Kick.
	 * @param string $code  Authorization code ('' when refused).
	 * @param string $error Kick's error ('' for none).
	 */
	public function finish( string $state, string $code, string $error ): string {
		$data = 1 === preg_match( '/^[0-9a-f]{32}$/', $state ) ? get_transient( self::STATE_PREFIX . $state ) : false;

		if ( ! is_array( $data ) || ! is_int( $data['user'] ?? null ) || ! is_int( $data['creator'] ?? null ) || ! is_string( $data['verifier'] ?? null ) ) {
			return home_url( '/' );
		}

		// Single use.
		delete_transient( self::STATE_PREFIX . $state );

		$previous = get_current_user_id();
		wp_set_current_user( $data['user'] );

		try {
			$result = '' !== $error || '' === $code || ! TwitchOAuth::same_login( $data['user'] ) ? KickException::DENIED : $this->complete( $data['creator'], $code, $data['verifier'] );
		} catch ( ChatbotException $e ) {
			$result = $e->reason();
			$this->log_failure( $result, $e->status() );
		} catch ( DatabaseException $e ) {
			$result = 'error';
			$this->log_failure( $result, 0, $e->getMessage() );
		} finally {
			wp_set_current_user( $previous );
		}

		return TwitchOAuth::dashboard_result( ( $this->dashboard_url )( 'alerts/chatbot' ), ChatPlatform::Kick, $result );
	}

	/**
	 * Exchanges the code, stores the tokens and the channel and subscribes
	 * the bot to the channel's chat.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $code       Authorization code.
	 * @param string $verifier   PKCE code verifier.
	 * @return string Result code.
	 * @throws KickException When Kick refuses it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function complete( int $creator_id, string $code, string $verifier ): string {
		$this->step = 'token';
		$tokens     = $this->api->exchange_code( $this->account->client_id(), $this->account->require_secret(), $code, KickAccount::redirect_uri(), $verifier );
		$this->step = 'channel';
		$channel    = $this->api->token_channel( $tokens['access'] );
		$this->step = 'store';
		$previous   = $this->channels->find( $creator_id, ChatPlatform::Kick );

		// Another channel before: its tokens are revoked and its subscription ends.
		if ( $previous->connected() && $previous->broadcaster_id !== $channel['id'] ) {
			$this->tokens->revoke( $creator_id );
			$this->subscriptions->unsubscribe( $previous );
		}

		$this->transaction->run(
			function () use ( $creator_id, $channel ): void {
				$this->audit->record(
					AuditAction::AlertChatbotChannelConnected,
					$creator_id,
					array(
						'login'    => $channel['slug'],
						'platform' => ChatPlatform::Kick->value,
					)
				);
				$this->channels->connect( $creator_id, ChatPlatform::Kick, $channel['id'], $channel['slug'] );
			}
		);
		$this->tokens->store( $creator_id, $tokens );
		$this->step = 'subscribe';
		$this->subscriptions->subscribe( $this->channels->find( $creator_id, ChatPlatform::Kick ) );

		return 'connected';
	}

	/**
	 * Logs a failed authorization: the step, the reason and Kick's HTTP
	 * status (WooCommerce → Status → Logs, source "merchboard"). Never a
	 * token, a code or a secret.
	 *
	 * @param string $reason Reason code.
	 * @param int    $status Kick's HTTP status (0: none).
	 * @param string $detail Technical detail (database errors).
	 */
	private function log_failure( string $reason, int $status, string $detail = '' ): void {
		$this->logger->warning(
			'Adding the bot to a Kick channel failed.',
			array(
				'step'   => $this->step,
				'reason' => $reason,
				'status' => $status,
				'detail' => $detail,
			)
		);
	}
}
