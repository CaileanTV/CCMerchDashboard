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
use MerchBoard\Modules\Alerts\Chatbot\ChatbotException;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotFormat;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Chatbot\ChatSender;
use MerchBoard\Modules\Alerts\Chatbot\ChatSubscriptions;
use MerchBoard\Modules\Alerts\Chatbot\KickAccount;
use MerchBoard\Modules\Alerts\Chatbot\TwitchAccount;
use MerchBoard\Modules\Alerts\Chatbot\TwitchApi;
use MerchBoard\Modules\Alerts\Chatbot\TwitchOAuth;
use MerchBoard\Modules\Alerts\Chatbot\YouTubeAccount;
use MerchBoard\Modules\Alerts\Chatbot\YouTubeApi;
use MerchBoard\Modules\Alerts\Chatbot\YouTubeOAuth;
use MerchBoard\Modules\Alerts\Legal\YouTubeLegal;
use MerchBoard\Modules\Alerts\Legal\YouTubeTerms;

defined( 'ABSPATH' ) || exit;

/**
 * The chatbot's admin actions (phase 25, steps 8 and 8.2). Order of checks:
 * capability `merchboard_manage_alerts` → nonce bound to the action (and the
 * creator and platform) → input → TwitchAccount, KickAccount, ChatbotFormat
 * or ChatSender (which check the capability again and audit).
 * Post/Redirect/Get to the tab with a whitelisted outcome code; "Log in
 * with the bot account" redirects to Twitch instead.
 */
final class ChatbotAdminActions {

	public const APP_ACTION            = 'merchboard_alert_chatbot_app';
	public const BOT_CONNECT_ACTION    = 'merchboard_alert_chatbot_bot_connect';
	public const BOT_DISCONNECT_ACTION = 'merchboard_alert_chatbot_bot_disconnect';
	public const KICK_APP_ACTION       = 'merchboard_alert_chatbot_kick_app';
	public const FORMAT_ACTION         = 'merchboard_alert_chatbot_format';
	public const RESUBSCRIBE_ACTION    = 'merchboard_alert_chatbot_resubscribe';
	public const SEND_ACTION           = 'merchboard_alert_chatbot_send';

	public const YOUTUBE_APP_ACTION            = 'merchboard_alert_chatbot_youtube_app';
	public const YOUTUBE_BOT_CONNECT_ACTION    = 'merchboard_alert_chatbot_youtube_bot_connect';
	public const YOUTUBE_BOT_DISCONNECT_ACTION = 'merchboard_alert_chatbot_youtube_bot_disconnect';
	public const YOUTUBE_TERMS_ACTION          = 'merchboard_alert_chatbot_youtube_terms';
	public const LEGAL_ACTION                  = 'merchboard_alert_youtube_legal';

	/**
	 * Every handled action.
	 */
	private const ACTIONS = array( self::APP_ACTION, self::BOT_CONNECT_ACTION, self::BOT_DISCONNECT_ACTION, self::KICK_APP_ACTION, self::YOUTUBE_APP_ACTION, self::YOUTUBE_BOT_CONNECT_ACTION, self::YOUTUBE_BOT_DISCONNECT_ACTION, self::YOUTUBE_TERMS_ACTION, self::LEGAL_ACTION, self::FORMAT_ACTION, self::RESUBSCRIBE_ACTION, self::SEND_ACTION );

	/**
	 * Constructor.
	 *
	 * @param TwitchAccount            $account       Twitch app and bot.
	 * @param KickAccount              $kick          Kick app.
	 * @param YouTubeAccount           $youtube       Google app and YouTube bot.
	 * @param YouTubeOAuth             $google        YouTube authorizations.
	 * @param TwitchOAuth              $oauth         Twitch authorizations.
	 * @param ChatbotFormat            $format        Message format.
	 * @param ChatSubscriptions        $subscriptions Chat subscriptions.
	 * @param ChatbotChannelRepository $channels      The creators' channels.
	 * @param ChatSender               $sender        Sending.
	 * @param AuditTrail               $audit         Audit trail.
	 * @param Transaction              $transaction   Database transaction.
	 * @param Logger                   $logger        Technical log.
	 * @param YouTubeLegal             $legal         YouTube's privacy policy and terms.
	 * @param YouTubeTerms             $terms         Their acceptance.
	 */
	public function __construct(
		private TwitchAccount $account,
		private KickAccount $kick,
		private YouTubeAccount $youtube,
		private YouTubeOAuth $google,
		private TwitchOAuth $oauth,
		private ChatbotFormat $format,
		private ChatSubscriptions $subscriptions,
		private ChatbotChannelRepository $channels,
		private ChatSender $sender,
		private AuditTrail $audit,
		private Transaction $transaction,
		private Logger $logger,
		private YouTubeLegal $legal,
		private YouTubeTerms $terms
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
	 * @param string            $action     Admin-post action.
	 * @param int               $creator_id Creator (send); 0 for none.
	 * @param ChatPlatform|null $platform   Platform (send).
	 */
	public static function nonce_action( string $action, int $creator_id = 0, ?ChatPlatform $platform = null ): string {
		return 0 === $creator_id ? $action : $action . ':' . $creator_id . ':' . ( $platform ?? ChatPlatform::Twitch )->value;
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_request(): void {
		// The nonce is verified in process() before any input is used.
		$url = $this->process( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field validated in process().

		// "Log in with the bot account" goes to Twitch's or Google's authorization page; no other host.
		add_filter( 'allowed_redirect_hosts', static fn( array $hosts ): array => array_merge( $hosts, array( (string) wp_parse_url( TwitchApi::AUTHORIZE_URL, PHP_URL_HOST ), (string) wp_parse_url( YouTubeApi::AUTHORIZE_URL, PHP_URL_HOST ) ) ) );
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

		$action   = self::scalar( $input, 'action' );
		$creator  = absint( self::scalar( $input, 'creator' ) );
		$platform = ChatPlatform::parse( self::scalar( $input, 'platform' ) ) ?? ChatPlatform::Twitch;
		$send     = self::SEND_ACTION === $action;

		if ( ! in_array( $action, self::ACTIONS, true ) || 1 !== wp_verify_nonce( self::scalar( $input, '_wpnonce' ), self::nonce_action( $action, $send ? $creator : 0, $platform ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			return $this->run( $action, $input, $creator, $platform );
		} catch ( ChatbotException $e ) {
			$prefix = match ( $action ) {
				self::BOT_CONNECT_ACTION, self::BOT_DISCONNECT_ACTION => 'bot_',
				self::KICK_APP_ACTION => 'kick_',
				self::YOUTUBE_APP_ACTION => 'youtube_',
				self::YOUTUBE_BOT_CONNECT_ACTION, self::YOUTUBE_BOT_DISCONNECT_ACTION, self::YOUTUBE_TERMS_ACTION => 'youtube_bot_',
				self::LEGAL_ACTION => 'legal_',
				default => 'app_',
			};

			return AlertsAdminPage::url( self::tab( $action ), $send ? 'message_failed' : $prefix . $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Changing the alert chatbot failed.', array( 'reason' => $e->getMessage() ) );

			return AlertsAdminPage::url( self::tab( $action ), 'bot_error' );
		}
	}

	/**
	 * Runs an action; returns the redirect URL.
	 *
	 * @param string       $action   Admin-post action.
	 * @param array<mixed> $input    Request data.
	 * @param int          $creator  Creator (send).
	 * @param ChatPlatform $platform Platform (send).
	 * @throws ChatbotException When a platform or the chatbot refuses it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function run( string $action, array $input, int $creator, ChatPlatform $platform ): string {
		$remove = '1' === self::scalar( $input, 'remove' );

		switch ( $action ) {
			case self::APP_ACTION:
				if ( $remove ) {
					$this->account->remove_app();

					return AlertsAdminPage::url( 'chatbot', 'app_removed' );
				}

				$this->account->save_app( self::scalar( $input, 'client_id' ), self::scalar( $input, 'secret' ) );

				return AlertsAdminPage::url( 'chatbot', 'app_saved' );
			case self::KICK_APP_ACTION:
				if ( $remove ) {
					$this->kick->remove_app();

					return AlertsAdminPage::url( 'chatbot', 'kick_removed' );
				}

				$this->kick->save_app( self::scalar( $input, 'client_id' ), self::scalar( $input, 'secret' ), self::scalar( $input, 'bot_name' ) );
				$this->subscriptions->subscribe_all( ChatPlatform::Kick );

				return AlertsAdminPage::url( 'chatbot', 'kick_saved' );
			case self::YOUTUBE_APP_ACTION:
				if ( $remove ) {
					$this->youtube->remove_app();

					return AlertsAdminPage::url( 'chatbot', 'youtube_removed' );
				}

				$this->youtube->save_app( self::scalar( $input, 'client_id' ), self::scalar( $input, 'secret' ) );

				return AlertsAdminPage::url( 'chatbot', 'youtube_saved' );
			case self::YOUTUBE_BOT_CONNECT_ACTION:
				// The administrator accepts the privacy policy and terms for the bot (ADR-071).
				if ( '1' !== self::scalar( $input, 'accept_terms' ) ) {
					return AlertsAdminPage::url( 'chatbot', 'youtube_bot_terms_required' );
				}

				$url = $this->google->start_bot();
				$this->terms->accept_bot( get_current_user_id() );

				return $url;
			case self::YOUTUBE_TERMS_ACTION:
				if ( '1' !== self::scalar( $input, 'accept_terms' ) ) {
					return AlertsAdminPage::url( 'chatbot', 'youtube_bot_terms_required' );
				}

				$this->terms->accept_bot( get_current_user_id() );

				return AlertsAdminPage::url( 'chatbot', 'youtube_terms_accepted' );
			case self::LEGAL_ACTION:
				$texts = array();

				foreach ( YouTubeLegal::DOCUMENTS as $document ) {
					foreach ( YouTubeLegal::LANGUAGES as $language ) {
						$key = YouTubeLegal::key( $document, $language );
						// HTML; YouTubeLegal limits it to wp_kses_post().
						$texts[ $key ] = is_string( $input[ $key ] ?? null ) ? $input[ $key ] : '';
					}
				}

				$renew = '1' === self::scalar( $input, 'renew' );

				return AlertsAdminPage::url( 'youtube-legal', $this->legal->save( $texts, $renew ) );
			case self::YOUTUBE_BOT_DISCONNECT_ACTION:
				$this->youtube->disconnect_bot();

				return AlertsAdminPage::url( 'chatbot', 'youtube_bot_disconnected' );
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
				return add_query_arg(
					array(
						'chat'     => $creator,
						'platform' => $platform->value,
					),
					AlertsAdminPage::url( 'chatbot', $this->send( $creator, $platform, self::scalar( $input, 'message' ) ) )
				);
		}
	}

	/**
	 * Sends the administrator's message as the bot (audited without the text).
	 *
	 * @param int          $creator_id Creator.
	 * @param ChatPlatform $platform   Platform.
	 * @param string       $message    Message.
	 * @return string Outcome code.
	 * @throws ChatbotException When the platform is not set up.
	 * @throws DatabaseException When it cannot be audited.
	 */
	private function send( int $creator_id, ChatPlatform $platform, string $message ): string {
		$channel = $this->channels->find( $creator_id, $platform );

		if ( ! $channel->connected() || '' === $message ) {
			return 'message_failed';
		}

		$this->transaction->run(
			function () use ( $creator_id, $channel ): void {
				$this->audit->record(
					AuditAction::AlertChatbotMessageSent,
					$creator_id,
					array(
						'login'    => $channel->broadcaster_login,
						'platform' => $channel->platform->value,
					)
				);
			}
		);

		$sent    = $this->sender->send_now( $channel, $message );
		$result  = $sent['result'];
		$youtube = ChatPlatform::YouTube === $platform;

		return match ( true ) {
			'sent' === $result => 'message_sent',
			$youtube && 'live_not_allowed' === $sent['reason'] => 'message_youtube_live_not_allowed',
			$youtube && 'quota_exceeded' === $sent['reason'] => 'message_youtube_quota',
			// YouTube refuses the bot because of the stream or chat settings (e.g. a private stream).
			$youtube && 'forbidden' === $result => 'message_youtube_forbidden',
			$youtube && 'not_live' === $result => 'message_youtube_not_live',
			default => 'message_failed',
		};
	}

	/**
	 * The tab an action returns to.
	 *
	 * @param string $action Admin-post action.
	 */
	private static function tab( string $action ): string {
		return self::LEGAL_ACTION === $action ? 'youtube-legal' : 'chatbot';
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
