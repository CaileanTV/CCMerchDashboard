<?php
/**
 * State-changing requests of the "Chatbot" tab (admin-post.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotFormat;
use MerchBoard\Modules\Alerts\Chatbot\ChatSender;
use MerchBoard\Modules\Alerts\Chatbot\ChatSubscriptions;
use MerchBoard\Modules\Alerts\Chatbot\TwitchAccount;
use MerchBoard\Modules\Alerts\Chatbot\TwitchApi;
use MerchBoard\Modules\Alerts\Chatbot\TwitchException;
use MerchBoard\Modules\Alerts\Chatbot\TwitchOAuth;

defined( 'ABSPATH' ) || exit;

/**
 * The chatbot's admin actions (phase 25, step 8). Order of checks:
 * capability `merchboard_manage_alerts` → nonce bound to the action (and the
 * creator) → input → TwitchAccount, ChatbotFormat or ChatSender (which check
 * the capability again and audit). Post/Redirect/Get to the tab with a
 * whitelisted outcome code; "Log in with the bot account" redirects to
 * Twitch instead.
 */
final class ChatbotAdminActions {

	public const APP_ACTION            = 'merchboard_alert_chatbot_app';
	public const BOT_CONNECT_ACTION    = 'merchboard_alert_chatbot_bot_connect';
	public const BOT_DISCONNECT_ACTION = 'merchboard_alert_chatbot_bot_disconnect';
	public const FORMAT_ACTION         = 'merchboard_alert_chatbot_format';
	public const RESUBSCRIBE_ACTION    = 'merchboard_alert_chatbot_resubscribe';
	public const SEND_ACTION           = 'merchboard_alert_chatbot_send';

	/**
	 * Every handled action.
	 */
	private const ACTIONS = array( self::APP_ACTION, self::BOT_CONNECT_ACTION, self::BOT_DISCONNECT_ACTION, self::FORMAT_ACTION, self::RESUBSCRIBE_ACTION, self::SEND_ACTION );

	/**
	 * Constructor.
	 *
	 * @param TwitchAccount            $account       App and bot.
	 * @param TwitchOAuth              $oauth         Authorizations.
	 * @param ChatbotFormat            $format        Message format.
	 * @param ChatSubscriptions        $subscriptions Chat subscriptions.
	 * @param ChatbotChannelRepository $channels      The creators' channels.
	 * @param ChatSender               $sender        Sending.
	 * @param AuditTrail               $audit         Audit trail.
	 * @param Transaction              $transaction   Database transaction.
	 * @param Logger                   $logger        Technical log.
	 */
	public function __construct(
		private TwitchAccount $account,
		private TwitchOAuth $oauth,
		private ChatbotFormat $format,
		private ChatSubscriptions $subscriptions,
		private ChatbotChannelRepository $channels,
		private ChatSender $sender,
		private AuditTrail $audit,
		private Transaction $transaction,
		private Logger $logger
	) {
	}

	/**
	 * Registers the admin-post handlers.
	 */
	public function register(): void {
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( $this, 'handle_request' ) );
		}
	}

	/**
	 * The nonce action of a request.
	 *
	 * @param string $action     Admin-post action.
	 * @param int    $creator_id Creator (send); 0 for none.
	 */
	public static function nonce_action( string $action, int $creator_id = 0 ): string {
		return 0 === $creator_id ? $action : $action . ':' . $creator_id;
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_request(): void {
		// The nonce is verified in process() before any input is used.
		$url = $this->process( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field validated in process().

		// "Log in with the bot account" goes to Twitch's authorization page; no other host.
		add_filter( 'allowed_redirect_hosts', static fn( array $hosts ): array => array_merge( $hosts, array( (string) wp_parse_url( TwitchApi::AUTHORIZE_URL, PHP_URL_HOST ) ) ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Processes a request.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function process( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage alerts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$action  = self::scalar( $input, 'action' );
		$creator = absint( self::scalar( $input, 'creator' ) );

		if ( ! in_array( $action, self::ACTIONS, true ) || 1 !== wp_verify_nonce( self::scalar( $input, '_wpnonce' ), self::nonce_action( $action, self::SEND_ACTION === $action ? $creator : 0 ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			return $this->run( $action, $input, $creator );
		} catch ( TwitchException $e ) {
			$prefix = in_array( $action, array( self::BOT_CONNECT_ACTION, self::BOT_DISCONNECT_ACTION ), true ) ? 'bot_' : 'app_';

			return AlertsAdminPage::url( 'chatbot', self::SEND_ACTION === $action ? 'message_failed' : $prefix . $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Changing the alert chatbot failed.', array( 'reason' => $e->getMessage() ) );

			return AlertsAdminPage::url( 'chatbot', 'bot_error' );
		}
	}

	/**
	 * Runs an action; returns the redirect URL.
	 *
	 * @param string       $action  Admin-post action.
	 * @param array<mixed> $input   Request data.
	 * @param int          $creator Creator (send).
	 * @throws TwitchException When Twitch or the chatbot refuses it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function run( string $action, array $input, int $creator ): string {
		switch ( $action ) {
			case self::APP_ACTION:
				if ( '1' === self::scalar( $input, 'remove' ) ) {
					$this->account->remove_app();

					return AlertsAdminPage::url( 'chatbot', 'app_removed' );
				}

				$this->account->save_app( self::scalar( $input, 'client_id' ), self::scalar( $input, 'secret' ) );

				return AlertsAdminPage::url( 'chatbot', 'app_saved' );
			case self::BOT_CONNECT_ACTION:
				return $this->oauth->start_bot();
			case self::BOT_DISCONNECT_ACTION:
				$this->account->disconnect_bot();

				return AlertsAdminPage::url( 'chatbot', 'bot_disconnected' );
			case self::FORMAT_ACTION:
				$this->format->save( self::scalar( $input, 'mode' ), self::scalar( $input, 'item' ), is_string( $input['separator'] ?? null ) ? $input['separator'] : '' );

				return AlertsAdminPage::url( 'chatbot', 'format_saved' );
			case self::RESUBSCRIBE_ACTION:
				$this->subscriptions->subscribe_all();

				return AlertsAdminPage::url( 'chatbot', 'resubscribed' );
			default:
				return add_query_arg( 'chat', $creator, AlertsAdminPage::url( 'chatbot', $this->send( $creator, self::scalar( $input, 'message' ) ) ) );
		}
	}

	/**
	 * Sends the administrator's message as the bot (audited without the text).
	 *
	 * @param int    $creator_id Creator.
	 * @param string $message    Message.
	 * @return string Outcome code.
	 * @throws TwitchException When the chatbot is not set up.
	 * @throws DatabaseException When it cannot be audited.
	 */
	private function send( int $creator_id, string $message ): string {
		$channel = $this->channels->find( $creator_id );

		if ( ! $channel->connected() || '' === $message ) {
			return 'message_failed';
		}

		$this->transaction->run(
			function () use ( $creator_id, $channel ): void {
				$this->audit->record( AuditAction::AlertChatbotMessageSent, $creator_id, array( 'login' => $channel->broadcaster_login ) );
			}
		);

		return 'sent' === $this->sender->send_now( $channel->broadcaster_id, $message )['result'] ? 'message_sent' : 'message_failed';
	}

	/**
	 * A scalar input field as a trimmed, sanitized string.
	 *
	 * @param array<mixed> $input Request data.
	 * @param string       $key   Field.
	 */
	private static function scalar( array $input, string $key ): string {
		return is_scalar( $input[ $key ] ?? null ) ? trim( sanitize_text_field( (string) $input[ $key ] ) ) : '';
	}
}
