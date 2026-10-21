<?php
/**
 * Google logins of the YouTube chatbot.
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
use MerchBoard\Modules\Alerts\Admin\AlertsAdminPage;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth at Google with PKCE (ADR-070 pkt. 3 and 4), like KickOAuth:
 *
 * - **Bot:** an administrator logs in with the bot's Google account
 *   (`youtube.force-ssl`; Google always asks which account). The bot's
 *   channel and tokens are stored by YouTubeAccount.
 * - **Channel:** a creator logs in with the channel's Google account
 *   (`youtube.readonly`), so the current livestream can be found. The
 *   creator's tokens are stored encrypted (CreatorTokens); the bot's own
 *   channel is refused.
 *
 * The state is random (128 bit), single-use, valid for ten minutes and bound
 * to the WordPress user (and the creator); the PKCE verifier stays on the
 * server inside it. Failures are logged with the step, the reason and
 * Google's HTTP status, never a code or a token.
 */
final class YouTubeOAuth {

	/**
	 * Transient prefix of a state.
	 */
	private const STATE_PREFIX = 'merchboard_youtube_state_';

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
	 * The step in progress, for the technical log.
	 *
	 * @var string
	 */
	private string $step = '';

	/**
	 * Constructor.
	 *
	 * @param YouTubeAccount           $account       The Google app and the bot.
	 * @param YouTubeApi               $api           YouTube.
	 * @param CreatorTokens            $tokens        The creators' Google tokens.
	 * @param ChatbotChannelRepository $channels      The creators' channels.
	 * @param ChatSubscriptions        $subscriptions Chat subscriptions.
	 * @param AuditTrail               $audit         Audit trail.
	 * @param Transaction              $transaction   Database transaction.
	 * @param Closure                  $dashboard_url The dashboard URL of a path.
	 * @param Logger                   $logger        Technical log (failures without tokens).
	 *
	 * @phpstan-param Closure(string): string $dashboard_url
	 */
	public function __construct( private YouTubeAccount $account, private YouTubeApi $api, private CreatorTokens $tokens, private ChatbotChannelRepository $channels, private ChatSubscriptions $subscriptions, private AuditTrail $audit, private Transaction $transaction, Closure $dashboard_url, private Logger $logger ) {
		$this->dashboard_url = $dashboard_url;
	}

	/**
	 * The URL that logs the bot account in at Google.
	 *
	 * @throws ChatbotException When the app is not set up.
	 */
	public function start_bot(): string {
		return $this->start( 'bot', 0, YouTubeAccount::BOT_SCOPE, true );
	}

	/**
	 * The URL that asks a creator to add the bot to the own channel.
	 *
	 * @param int $creator_id Creator (from CreatorContext).
	 * @throws ChatbotException When the app or the bot is not set up.
	 */
	public function start_channel( int $creator_id ): string {
		if ( ! $this->account->ready() ) {
			throw new ChatbotException( ChatbotException::NOT_CONFIGURED );
		}

		return $this->start( 'channel', $creator_id, YouTubeAccount::CHANNEL_SCOPE, false );
	}

	/**
	 * Finishes an authorization (Google's redirect) and gives the URL to
	 * send the browser to.
	 *
	 * @param string $state State from Google.
	 * @param string $code  Authorization code ('' when refused).
	 * @param string $error Google's error ('' for none).
	 */
	public function finish( string $state, string $code, string $error ): string {
		$data = 1 === preg_match( '/^[0-9a-f]{32}$/', $state ) ? get_transient( self::STATE_PREFIX . $state ) : false;

		if ( ! is_array( $data ) || ! in_array( $data['purpose'] ?? null, array( 'bot', 'channel' ), true ) || ! is_int( $data['user'] ?? null ) || ! is_int( $data['creator'] ?? null ) || ! is_string( $data['verifier'] ?? null ) ) {
			return home_url( '/' );
		}

		// Single use.
		delete_transient( self::STATE_PREFIX . $state );

		$bot      = 'bot' === $data['purpose'];
		$previous = get_current_user_id();
		wp_set_current_user( $data['user'] );

		try {
			$result = '' !== $error || '' === $code ? ChatbotException::DENIED : $this->complete( $bot, $data['creator'], $code, $data['verifier'] );
		} catch ( ChatbotException $e ) {
			$result = $e->reason();
			$this->log_failure( $bot, $result, $e->status() );
		} catch ( DatabaseException $e ) {
			$result = 'error';
			$this->log_failure( $bot, $result, 0, $e->getMessage() );
		} finally {
			wp_set_current_user( $previous );
		}

		return $bot
			? AlertsAdminPage::url( 'chatbot', 'youtube_bot_' . $result )
			: TwitchOAuth::dashboard_result( ( $this->dashboard_url )( 'alerts/chatbot' ), ChatPlatform::YouTube, $result );
	}

	/**
	 * Exchanges the code and connects the bot or the channel.
	 *
	 * @param bool   $bot        Whether it is the bot account.
	 * @param int    $creator_id Creator (channel).
	 * @param string $code       Authorization code.
	 * @param string $verifier   PKCE code verifier.
	 * @return string Result code.
	 * @throws ChatbotException When Google refuses it or the channel is the bot's.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function complete( bool $bot, int $creator_id, string $code, string $verifier ): string {
		$this->step = 'token';
		$tokens     = $this->api->exchange_code( $this->account->client_id(), $this->account->require_secret(), $code, YouTubeAccount::redirect_uri(), $verifier );
		$this->step = 'channel';
		$channel    = $this->api->my_channel( $tokens['access'] );
		$this->step = 'store';

		if ( $bot ) {
			$this->account->connect_bot( $channel, $tokens );

			return 'connected';
		}

		$this->require_other_account( $channel['id'] );
		$previous = $this->channels->find( $creator_id, ChatPlatform::YouTube );

		// Another channel before: its tokens are revoked.
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
						'login'    => $channel['name'],
						'platform' => ChatPlatform::YouTube->value,
					)
				);
				$this->channels->connect( $creator_id, ChatPlatform::YouTube, $channel['id'], $channel['name'] );
			}
		);
		$this->tokens->store( $creator_id, $tokens );
		$this->subscriptions->subscribe( $this->channels->find( $creator_id, ChatPlatform::YouTube ) );

		return 'connected';
	}

	/**
	 * Requires a channel that is not the bot's own.
	 *
	 * @param string $channel_id The creator's channel.
	 * @throws ChatbotException When it is the bot's channel.
	 */
	private function require_other_account( string $channel_id ): void {
		if ( ( $this->account->bot()['id'] ?? '' ) === $channel_id ) {
			throw new ChatbotException( ChatbotException::BOT_IS_BROADCASTER );
		}
	}

	/**
	 * A new authorization URL with its state.
	 *
	 * @param string $purpose    'bot' or 'channel'.
	 * @param int    $creator_id Creator (channel), 0 for the bot.
	 * @param string $scope      Scope.
	 * @param bool   $choose     Whether Google always asks which account.
	 * @throws ChatbotException When the app is not set up.
	 */
	private function start( string $purpose, int $creator_id, string $scope, bool $choose ): string {
		if ( ! $this->account->has_app() ) {
			throw new ChatbotException( ChatbotException::NOT_CONFIGURED );
		}

		$state    = bin2hex( random_bytes( 16 ) );
		$verifier = KickApi::verifier();
		set_transient(
			self::STATE_PREFIX . $state,
			array(
				'purpose'  => $purpose,
				'user'     => get_current_user_id(),
				'creator'  => $creator_id,
				'verifier' => $verifier,
			),
			self::STATE_TTL
		);

		return YouTubeApi::authorize_url( $this->account->client_id(), YouTubeAccount::redirect_uri(), $scope, $state, $verifier, $choose );
	}

	/**
	 * Logs a failed authorization: the purpose, the step, the reason and
	 * Google's HTTP status (WooCommerce → Status → Logs, source
	 * "merchboard"). Never a token, a code or a secret.
	 *
	 * @param bool   $bot    Whether it was the bot account.
	 * @param string $reason Reason code.
	 * @param int    $status Google's HTTP status (0: none).
	 * @param string $detail Technical detail (database errors).
	 */
	private function log_failure( bool $bot, string $reason, int $status, string $detail = '' ): void {
		$this->logger->warning(
			'A YouTube login of the chatbot failed.',
			array(
				'purpose' => $bot ? 'bot' : 'channel',
				'step'    => $this->step,
				'reason'  => $reason,
				'status'  => $status,
				'detail'  => $detail,
			)
		);
	}
}
