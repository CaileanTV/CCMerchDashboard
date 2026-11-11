<?php
/**
 * Twitch authorizations: the bot account and the creators' channels.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use Closure;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Alerts\Admin\AlertsAdminPage;

defined( 'ABSPATH' ) || exit;

/**
 * The OAuth authorization code flow (ADR-066, status note 2026-10-20) for
 * two purposes:
 *
 * - **Bot:** an administrator logs in with the bot account and grants
 *   `user:read:chat user:write:chat user:bot` (Twitch always asks, so the
 *   right account can be chosen).
 * - **Channel:** a creator adds the bot to the own channel by granting
 *   `channel:bot`. The bot cannot be the channel's own account.
 *
 * `state` is single-use, random and bound to the user and (for a channel)
 * the creator who started; it expires after STATE_TTL. Twitch redirects to
 * one REST route, which has no cookie authentication, so the callback acts
 * as the user of the state. The user access token is only used to learn the
 * account's id and login and is not stored. Afterwards the browser goes back
 * to the admin page or the creator's dashboard with a result code.
 */
final class TwitchOAuth {

	/**
	 * Transient prefix of a state.
	 */
	private const STATE_PREFIX = 'merchboard_twitch_state_';

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
	 * Constructor.
	 *
	 * @param TwitchAccount            $account       App and bot.
	 * @param TwitchApi                $api           Twitch.
	 * @param ChatbotChannelRepository $channels      The creators' channels.
	 * @param ChatSubscriptions        $subscriptions Chat subscriptions.
	 * @param AuditTrail               $audit         Audit trail.
	 * @param Transaction              $transaction   Database transaction.
	 * @param Closure                  $dashboard_url The dashboard URL of a path.
	 * @param CreatorContext           $context       Current creator access.
	 * @param TwitchCustomerLogin|null $customer      Shop customers' Twitch login (ADR-106), which gets the states it started.
	 *
	 * @phpstan-param Closure(string): string $dashboard_url
	 */
	public function __construct( private TwitchAccount $account, private TwitchApi $api, private ChatbotChannelRepository $channels, private ChatSubscriptions $subscriptions, private AuditTrail $audit, private Transaction $transaction, Closure $dashboard_url, private CreatorContext $context, private ?TwitchCustomerLogin $customer = null ) {
		$this->dashboard_url = $dashboard_url;
	}

	/**
	 * The URL that logs the bot account in at Twitch.
	 *
	 * @throws TwitchException When the app is not set up.
	 */
	public function start_bot(): string {
		return $this->start( 'bot', 0, TwitchAccount::BOT_SCOPES, true );
	}

	/**
	 * The dashboard pages a creator can connect Twitch from and come back to
	 * (ADR-108): the chatbot and the community features sharing the channel.
	 */
	public const RETURN_PAGES = array( 'alerts/chatbot', 'community/twitch-discount', 'community/twitch-gifting' );

	/**
	 * The URL that asks a creator to add the bot to the own channel.
	 *
	 * @param int    $creator_id Creator (from CreatorContext).
	 * @param string $page       The dashboard page to come back to (one of RETURN_PAGES; others: the chatbot).
	 * @throws TwitchException When the bot is not set up.
	 */
	public function start_channel( int $creator_id, string $page = 'alerts/chatbot' ): string {
		if ( ! $this->account->ready() ) {
			throw new TwitchException( TwitchException::NOT_CONFIGURED );
		}

		return $this->start( 'channel', $creator_id, TwitchAccount::CHANNEL_SCOPES, false, self::return_page( $page ) );
	}

	/**
	 * A page to come back to: one of RETURN_PAGES, otherwise the chatbot.
	 *
	 * @param mixed $page Requested page.
	 */
	public static function return_page( mixed $page ): string {
		return is_string( $page ) && in_array( $page, self::RETURN_PAGES, true ) ? $page : 'alerts/chatbot';
	}

	/**
	 * Finishes an authorization (Twitch's redirect) and gives the URL to
	 * send the browser to.
	 *
	 * @param string $state State from Twitch.
	 * @param string $code  Authorization code ('' when refused).
	 * @param string $error Twitch's error ('' for none).
	 */
	public function finish( string $state, string $code, string $error ): string {
		// A shop customer's login (ADR-106) through the same callback URL.
		if ( null !== $this->customer && $this->customer->owns( $state ) ) {
			$binding = isset( $_COOKIE[ TwitchCustomerLogin::COOKIE ] ) && is_string( $_COOKIE[ TwitchCustomerLogin::COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ TwitchCustomerLogin::COOKIE ] ) ) : '';

			return $this->customer->finish( $state, $code, $error, $binding, (int) wp_validate_auth_cookie( '', 'logged_in' ) );
		}

		$data = 1 === preg_match( '/^[0-9a-f]{32}$/', $state ) ? get_transient( self::STATE_PREFIX . $state ) : false;

		if ( ! is_array( $data ) || ! in_array( $data['purpose'] ?? null, array( 'bot', 'channel' ), true ) || ! is_int( $data['user'] ?? null ) || ! is_int( $data['creator'] ?? null ) ) {
			return home_url( '/' );
		}

		// Single use.
		delete_transient( self::STATE_PREFIX . $state );

		$bot      = 'bot' === $data['purpose'];
		$previous = get_current_user_id();
		wp_set_current_user( $data['user'] );

		try {
			// A pending state does not preserve creator access after unlinking or reassignment.
			$creator = $bot ? null : $this->context->for_user( $data['user'] );
			$result  = '' !== $error || '' === $code || ! self::same_login( $data['user'] ) || ( ! $bot && $creator?->id !== $data['creator'] ) ? TwitchException::DENIED : $this->complete( $bot, $data['creator'], $code );
		} catch ( TwitchException $e ) {
			$result = $e->reason();
		} catch ( DatabaseException ) {
			$result = 'error';
		} finally {
			wp_set_current_user( $previous );
		}

		return $bot
			? AlertsAdminPage::url( 'chatbot', 'bot_' . $result )
			: self::dashboard_result( ( $this->dashboard_url )( self::return_page( $data['return'] ?? null ) ), ChatPlatform::Twitch, $result );
	}

	/**
	 * Whether the browser that comes back from the platform is logged in as
	 * the user who started the authorization (25.13 review). A link with
	 * someone else's state, e.g. sent to another streamer, cannot connect
	 * that streamer's channel to the sender's creator. Used by Twitch, Kick
	 * and YouTube.
	 *
	 * @param int $user_id The user who started it.
	 */
	public static function same_login( int $user_id ): bool {
		return $user_id > 0 && (int) wp_validate_auth_cookie( '', 'logged_in' ) === $user_id;
	}

	/**
	 * Exchanges the code and connects the bot or the channel.
	 *
	 * @param bool   $bot        Whether it is the bot account.
	 * @param int    $creator_id Creator (channel).
	 * @param string $code       Authorization code.
	 * @return string Result code.
	 * @throws TwitchException When Twitch refuses it, the user may not do it, or the bot is the channel.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function complete( bool $bot, int $creator_id, string $code ): string {
		$client = $this->account->client_id();
		$token  = $this->api->exchange_code( $client, $this->account->require_secret(), $code, TwitchAccount::redirect_uri() );
		$user   = $this->api->token_user( $client, $token );

		if ( $bot ) {
			$this->account->connect_bot( $user['id'], $user['login'] );
			$this->subscriptions->subscribe_all( ChatPlatform::Twitch );

			return 'connected';
		}

		$this->connect_channel( $creator_id, $user );

		return 'connected';
	}

	/**
	 * Stores the creator's channel and subscribes the bot to its chat.
	 *
	 * @param int                              $creator_id Creator.
	 * @param array{id: string, login: string} $user       The channel's account.
	 * @throws TwitchException When the channel is the bot's own account.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function connect_channel( int $creator_id, array $user ): void {
		$this->require_other_account( $user['id'] );

		$previous = $this->channels->find( $creator_id, ChatPlatform::Twitch );

		$this->transaction->run(
			function () use ( $creator_id, $user ): void {
				$this->audit->record(
					AuditAction::AlertChatbotChannelConnected,
					$creator_id,
					array(
						'login'    => $user['login'],
						'platform' => ChatPlatform::Twitch->value,
					)
				);
				$this->channels->connect( $creator_id, ChatPlatform::Twitch, $user['id'], $user['login'] );
			}
		);

		// Another channel before: its subscription ends.
		if ( $previous->connected() && $previous->broadcaster_id !== $user['id'] ) {
			$this->subscriptions->unsubscribe( $previous );
		}

		$this->subscriptions->subscribe( $this->channels->find( $creator_id, ChatPlatform::Twitch ) );
	}

	/**
	 * Requires a channel that is not the bot's own account (Twitch shows no
	 * badge there).
	 *
	 * @param string $user_id The channel's account.
	 * @throws TwitchException When it is the bot's account.
	 */
	private function require_other_account( string $user_id ): void {
		$bot = $this->account->bot();

		if ( null !== $bot && $bot['id'] === $user_id ) {
			throw new TwitchException( TwitchException::BOT_IS_BROADCASTER );
		}
	}

	/**
	 * A new authorization URL with its state.
	 *
	 * @param string $purpose      'bot' or 'channel'.
	 * @param int    $creator_id   Creator (channel), 0 for the bot.
	 * @param string $scopes       Scopes.
	 * @param bool   $force_verify Whether Twitch always asks.
	 * @param string $page         The dashboard page to come back to (channel).
	 * @throws TwitchException When the app is not set up.
	 */
	private function start( string $purpose, int $creator_id, string $scopes, bool $force_verify, string $page = 'alerts/chatbot' ): string {
		if ( ! $this->account->has_app() ) {
			throw new TwitchException( TwitchException::NOT_CONFIGURED );
		}

		$state = bin2hex( random_bytes( 16 ) );
		set_transient(
			self::STATE_PREFIX . $state,
			array(
				'purpose' => $purpose,
				'user'    => get_current_user_id(),
				'creator' => $creator_id,
				'return'  => $page,
			),
			self::STATE_TTL
		);

		return TwitchApi::authorize_url( $this->account->client_id(), TwitchAccount::redirect_uri(), $scopes, $state, $force_verify );
	}

	/**
	 * The dashboard's chatbot page with the outcome of an authorization
	 * (`?chatbot=<result>&platform=<platform>`), which the page shows once.
	 *
	 * @param string       $url      The page's URL.
	 * @param ChatPlatform $platform Platform.
	 * @param string       $result   Result code.
	 */
	public static function dashboard_result( string $url, ChatPlatform $platform, string $result ): string {
		return add_query_arg(
			array(
				'chatbot'  => $result,
				'platform' => $platform->value,
			),
			$url
		);
	}
}
