<?php
/**
 * The "Chatbot" tab of the Alerts admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannel;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotFormat;
use MerchBoard\Modules\Alerts\Chatbot\ChatMessageRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Chatbot\ChatSubscriptions;
use MerchBoard\Modules\Alerts\Chatbot\KickAccount;
use MerchBoard\Modules\Alerts\Chatbot\TwitchAccount;
use MerchBoard\Modules\Creators\CreatorRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Merch Dashboard → Alerts → Chatbot (phase 25, steps 8 and 8.2; ADR-066,
 * ADR-069). Read-only rendering; every change goes through
 * ChatbotAdminActions. Everything is escaped; secrets are never shown.
 *
 * - **Twitch:** the app (Client ID, Secret, redirect URL), the bot account
 *   (Twitch always asks, so the right account can be chosen), the
 *   requirements of Twitch's chatbot badge and chat identity, and the
 *   latest rate limit.
 * - **Kick:** the app (Client ID, Secret, the bot's name, redirect and
 *   webhook URL) and its requirements.
 * - **Message format:** one message per line or per order, with the format
 *   of `$Products`, shared by the platforms.
 * - **Channels:** the creators' channels on every platform, with a chat
 *   window per channel: the bot's own (webhook) when the platform can reach
 *   the site, the platform's own chat otherwise, and "Send as the bot".
 */
final class ChatbotAdminTab {

	/**
	 * Script handle of the chat window.
	 */
	public const SCRIPT = 'merchboard-alerts-admin';

	/**
	 * Constructor.
	 *
	 * @param TwitchAccount            $account    Twitch app and bot.
	 * @param KickAccount              $kick       Kick app.
	 * @param ChatbotFormat            $format     Message format.
	 * @param ChatbotChannelRepository $channels   The creators' channels.
	 * @param ChatMessageRepository    $log        Message log.
	 * @param CreatorRepository        $creators   Creator names.
	 * @param string                   $plugin_dir Plugin directory (with slash).
	 * @param string                   $plugin_url Plugin URL (with slash).
	 */
	public function __construct( private TwitchAccount $account, private KickAccount $kick, private ChatbotFormat $format, private ChatbotChannelRepository $channels, private ChatMessageRepository $log, private CreatorRepository $creators, private string $plugin_dir, private string $plugin_url ) {
	}

	/**
	 * Messages of the outcome codes of this tab.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'app_saved'                    => array( __( 'The Twitch app was saved. Twitch accepted its credentials.', 'merchboard' ), 'success' ),
			'app_removed'                  => array( __( 'The Twitch app, the bot and every secret were removed.', 'merchboard' ), 'success' ),
			'app_invalid_credentials'      => array( __( 'Twitch did not accept the Client ID and Secret. Check them in the Twitch developer console.', 'merchboard' ), 'error' ),
			'app_unavailable', 'bot_unavailable' => array( __( 'Twitch could not be reached. Try again later.', 'merchboard' ), 'error' ),
			'app_unreadable', 'kick_unreadable' => array( __( 'The secret cannot be stored encrypted on this server (sodium is missing).', 'merchboard' ), 'error' ),
			'bot_connected'                => array( __( 'The bot account was connected. Its chat subscriptions were renewed.', 'merchboard' ), 'success' ),
			'bot_disconnected'             => array( __( 'The bot account was disconnected. No messages are sent until a bot is connected again.', 'merchboard' ), 'success' ),
			'bot_denied'                   => array( __( 'The login at Twitch was cancelled. Nothing was changed.', 'merchboard' ), 'info' ),
			'bot_not_configured'           => array( __( 'Save the Twitch app first.', 'merchboard' ), 'error' ),
			'bot_invalid_credentials', 'bot_unauthorized', 'bot_forbidden' => array( __( 'Twitch did not accept the login. Try again, and check the redirect URL of the Twitch app.', 'merchboard' ), 'error' ),
			'bot_error'                    => array( __( 'A technical error occurred. Nothing was changed.', 'merchboard' ), 'error' ),
			'kick_saved'                   => array( __( 'The Kick app was saved. Kick accepted its credentials.', 'merchboard' ), 'success' ),
			'kick_removed'                 => array( __( 'The Kick app and every secret were removed.', 'merchboard' ), 'success' ),
			'kick_invalid_credentials'     => array( __( 'Kick did not accept the Client ID and Secret (or the bot name is invalid). Check them in the Kick developer settings.', 'merchboard' ), 'error' ),
			'kick_unavailable'             => array( __( 'Kick could not be reached. Try again later.', 'merchboard' ), 'error' ),
			'format_saved'                 => array( __( 'The message format was saved.', 'merchboard' ), 'success' ),
			'resubscribed'                 => array( __( 'The chat subscriptions were renewed.', 'merchboard' ), 'success' ),
			'message_sent'                 => array( __( 'The message was sent as the bot.', 'merchboard' ), 'success' ),
			'message_failed'               => array( __( 'The platform did not send the message. Check that the bot is still in the channel.', 'merchboard' ), 'error' ),
			default                        => null,
		};
	}

	/**
	 * Renders the tab.
	 *
	 * @param int          $chat     Creator whose chat window is shown; 0 for the overview.
	 * @param ChatPlatform $platform The chat window's platform.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function render( int $chat, ChatPlatform $platform = ChatPlatform::Twitch ): void {
		if ( $chat > 0 ) {
			$this->render_chat( $chat, $platform );
			return;
		}

		echo '<h2>Twitch</h2>';
		$this->render_app();
		$this->render_bot();
		$this->render_rate_limit();
		echo '<hr><h2>Kick</h2>';
		$this->render_kick();
		echo '<hr>';
		$this->render_format();
		$this->render_channels();
	}

	/**
	 * The Twitch app.
	 */
	private function render_app(): void {
		echo '<h3>' . esc_html__( 'Twitch app', 'merchboard' ) . '</h3>';
		echo '<p>' . esc_html__( 'Create an application in the Twitch developer console (category "Chat Bot", client type "Confidential") and register this OAuth redirect URL:', 'merchboard' ) . '</p>';
		echo '<p><code>' . esc_html( TwitchAccount::redirect_uri() ) . '</code></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( ChatbotAdminActions::APP_ACTION ) . '">';
		wp_nonce_field( ChatbotAdminActions::APP_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="merchboard-twitch-client">' . esc_html__( 'Client ID', 'merchboard' ) . '</label></th><td><input type="text" id="merchboard-twitch-client" name="client_id" class="regular-text" autocomplete="off" spellcheck="false" value="' . esc_attr( $this->account->client_id() ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="merchboard-twitch-secret">' . esc_html__( 'Client Secret', 'merchboard' ) . '</label></th><td><input type="password" id="merchboard-twitch-secret" name="secret" class="regular-text" autocomplete="off" spellcheck="false" aria-describedby="merchboard-twitch-secret-help">';
		echo '<p id="merchboard-twitch-secret-help" class="description">' . esc_html( $this->account->has_app() ? __( 'A secret is saved and never shown again. Enter it again to change the app.', 'merchboard' ) : __( 'The secret is stored encrypted.', 'merchboard' ) ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save and check', 'merchboard' ), 'primary', 'submit', false );

		if ( '' !== $this->account->client_id() ) {
			echo ' <button type="submit" name="remove" value="1" class="button-link button-link-delete">' . esc_html__( 'Remove the app', 'merchboard' ) . '</button>';
		}

		echo '</form>';
	}

	/**
	 * The Twitch bot account and the badge requirements.
	 */
	private function render_bot(): void {
		$bot = $this->account->bot();

		echo '<h3>' . esc_html__( 'Bot account', 'merchboard' ) . '</h3>';

		if ( ! $this->account->has_app() ) {
			echo '<p>' . esc_html__( 'Save the Twitch app first.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';

		if ( null === $bot ) {
			echo '<p>' . esc_html__( 'Log in with the Twitch account that writes the messages, not with your own. Twitch always asks, so you can choose the account; a private browser window helps.', 'merchboard' ) . '</p>';
			echo '<input type="hidden" name="action" value="' . esc_attr( ChatbotAdminActions::BOT_CONNECT_ACTION ) . '">';
			wp_nonce_field( ChatbotAdminActions::BOT_CONNECT_ACTION );
			submit_button( __( 'Log in with the bot account at Twitch', 'merchboard' ), 'primary', 'submit', false );
		} else {
			/* translators: %s: Twitch login of the bot. */
			echo '<p>' . esc_html( sprintf( __( 'Connected as %s.', 'merchboard' ), $bot['login'] ) ) . '</p>';
			echo '<input type="hidden" name="action" value="' . esc_attr( ChatbotAdminActions::BOT_DISCONNECT_ACTION ) . '">';
			wp_nonce_field( ChatbotAdminActions::BOT_DISCONNECT_ACTION );
			submit_button( __( 'Disconnect the bot', 'merchboard' ), 'secondary', 'submit', false );
		}

		echo '</form>';

		self::checklist(
			__( 'Chatbot badge and chat identity', 'merchboard' ),
			array(
				array( true, __( 'Messages are sent with Send Chat Message and an app access token.', 'merchboard' ) ),
				array( null !== $bot, __( 'The bot account authorized the app (user:bot, user:write:chat, user:read:chat).', 'merchboard' ) ),
				array( true, __( 'Each creator adds the bot to the own channel (channel:bot) in the dashboard.', 'merchboard' ) ),
				array( TwitchAccount::webhooks_possible(), __( 'The bot listens to the chats over an EventSub webhook (needs an HTTPS site Twitch can reach).', 'merchboard' ) ),
			)
		);
	}

	/**
	 * The Kick app and its requirements.
	 */
	private function render_kick(): void {
		echo '<h3>' . esc_html__( 'Kick app', 'merchboard' ) . '</h3>';
		echo '<p>' . esc_html__( 'Create an app in your Kick account under Settings → Developer (two-factor authentication must be on), create a bot for the app there, and register this redirect URL:', 'merchboard' ) . '</p>';
		echo '<p><code>' . esc_html( KickAccount::redirect_uri() ) . '</code></p>';
		echo '<p>' . esc_html__( 'Turn on webhooks for the app and enter this webhook URL:', 'merchboard' ) . '</p>';
		echo '<p><code>' . esc_html( KickAccount::webhook_url() ) . '</code></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( ChatbotAdminActions::KICK_APP_ACTION ) . '">';
		wp_nonce_field( ChatbotAdminActions::KICK_APP_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="merchboard-kick-client">' . esc_html__( 'Client ID', 'merchboard' ) . '</label></th><td><input type="text" id="merchboard-kick-client" name="client_id" class="regular-text" autocomplete="off" spellcheck="false" value="' . esc_attr( $this->kick->client_id() ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="merchboard-kick-secret">' . esc_html__( 'Client Secret', 'merchboard' ) . '</label></th><td><input type="password" id="merchboard-kick-secret" name="secret" class="regular-text" autocomplete="off" spellcheck="false" aria-describedby="merchboard-kick-secret-help">';
		echo '<p id="merchboard-kick-secret-help" class="description">' . esc_html( $this->kick->has_app() ? __( 'A secret is saved and never shown again. Enter it again to change the app.', 'merchboard' ) : __( 'The secret is stored encrypted.', 'merchboard' ) ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="merchboard-kick-bot">' . esc_html__( 'Bot name', 'merchboard' ) . '</label></th><td><input type="text" id="merchboard-kick-bot" name="bot_name" class="regular-text" maxlength="25" autocomplete="off" spellcheck="false" value="' . esc_attr( $this->kick->bot_name() ) . '" aria-describedby="merchboard-kick-bot-help">';
		echo '<p id="merchboard-kick-bot-help" class="description">' . esc_html__( 'The name of the app\'s bot at Kick, shown to creators (optional).', 'merchboard' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save and check', 'merchboard' ), 'primary', 'submit', false );

		if ( '' !== $this->kick->client_id() ) {
			echo ' <button type="submit" name="remove" value="1" class="button-link button-link-delete">' . esc_html__( 'Remove the app', 'merchboard' ) . '</button>';
		}

		echo '</form>';

		self::checklist(
			__( 'Bot messages at Kick', 'merchboard' ),
			array(
				array( $this->kick->has_app(), __( 'The Kick app is saved and Kick accepted its credentials.', 'merchboard' ) ),
				array( true, __( 'Messages are sent as the app\'s bot (type "bot") with the channel\'s own token, which is stored encrypted and renewed automatically.', 'merchboard' ) ),
				array( true, __( 'Each creator adds the bot to the own channel (chat:write) in the dashboard.', 'merchboard' ) ),
				array( KickAccount::webhooks_possible(), __( 'The bot listens to the chats over Kick\'s webhook (needs an HTTPS site Kick can reach).', 'merchboard' ) ),
			)
		);
	}

	/**
	 * A list of requirements.
	 *
	 * @param string                    $title  Heading.
	 * @param list<array{bool, string}> $checks Met and text.
	 */
	private static function checklist( string $title, array $checks ): void {
		echo '<h4>' . esc_html( $title ) . '</h4><ul>';

		foreach ( $checks as [ $met, $text ] ) {
			echo '<li>' . ( $met ? '✓ ' : '✗ ' ) . esc_html( $text ) . ( $met ? '' : ' <strong>' . esc_html__( '(not met)', 'merchboard' ) . '</strong>' ) . '</li>';
		}

		echo '</ul>';
	}

	/**
	 * The message format.
	 */
	private function render_format(): void {
		$format = $this->format->get();

		echo '<h2>' . esc_html__( 'Message format', 'merchboard' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( ChatbotAdminActions::FORMAT_ACTION ) . '">';
		wp_nonce_field( ChatbotAdminActions::FORMAT_ACTION );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Messages per purchase', 'merchboard' ) . '</legend>';
		echo '<p><label><input type="radio" name="mode" value="line"' . checked( 'line', $format['mode'], false ) . '> ' . esc_html__( 'One message per order line (as MerchAlert)', 'merchboard' ) . '</label><br>';
		echo '<label><input type="radio" name="mode" value="order"' . checked( 'order', $format['mode'], false ) . '> ' . esc_html__( 'One message per order', 'merchboard' ) . '</label></p></fieldset>';
		echo '<p><label for="merchboard-chat-item">' . esc_html__( 'Each product in $Products (one message per order)', 'merchboard' ) . '</label><br>';
		echo '<input type="text" id="merchboard-chat-item" name="item" class="regular-text" maxlength="' . esc_attr( (string) ChatbotFormat::ITEM_MAX ) . '" value="' . esc_attr( $format['item'] ) . '" aria-describedby="merchboard-chat-item-help">';
		echo '<span id="merchboard-chat-item-help" class="description"> ' . esc_html__( 'Variables: $Quantity, $Product, $Variation, $Category.', 'merchboard' ) . '</span></p>';
		echo '<p><label for="merchboard-chat-separator">' . esc_html__( 'Between the products', 'merchboard' ) . '</label><br>';
		echo '<input type="text" id="merchboard-chat-separator" name="separator" class="small-text" maxlength="' . esc_attr( (string) ChatbotFormat::SEPARATOR_MAX ) . '" value="' . esc_attr( $format['separator'] ) . '"></p>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: a product, 2: the separator, 3: another product. */
				__( 'Example of $Products: %1$s%2$s%3$s', 'merchboard' ),
				strtr(
					$format['item'],
					array(
						'$Quantity'  => '2',
						'$Product'   => 'Hoodie',
						'$Variation' => 'L',
						'$Category'  => 'Merch',
					)
				),
				$format['separator'],
				strtr(
					$format['item'],
					array(
						'$Quantity'  => '1',
						'$Product'   => 'Mug',
						'$Variation' => '',
						'$Category'  => 'Merch',
					)
				)
			)
		) . '</p>';
		submit_button( __( 'Save format', 'merchboard' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * The latest Twitch rate limit.
	 */
	private function render_rate_limit(): void {
		$rate = $this->account->rate_limit();

		echo '<h3>' . esc_html__( 'Rate limit and verification', 'merchboard' ) . '</h3>';
		echo '<p>' . esc_html__( 'Twitch lets a bot send 20 messages per 30 seconds across all channels (100 where it is moderator or VIP), and at most one per second in a channel. A verified bot may send 7,500 per 30 seconds. Messages wait in a queue when the limit is reached.', 'merchboard' ) . '</p>';
		echo '<p>' . esc_html(
			0 === $rate['seen_at']
				? __( 'No message has been sent yet.', 'merchboard' )
				/* translators: %d: messages per 30 seconds. */
				: sprintf( __( 'Twitch reported a limit of %d messages per 30 seconds for the latest message.', 'merchboard' ), $rate['limit'] )
		) . '</p>';
	}

	/**
	 * The channels with the bot on every platform.
	 *
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function render_channels(): void {
		$channels = $this->channels->connected();

		echo '<h2>' . esc_html__( 'Channels', 'merchboard' ) . '</h2>';

		if ( array() === $channels ) {
			echo '<p>' . esc_html__( 'No creator has added the bot to a channel yet. Creators do this under Alerts → Chatbot in the dashboard.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Creator', 'merchboard' ) . '</th><th>' . esc_html__( 'Platform', 'merchboard' ) . '</th><th>' . esc_html__( 'Channel', 'merchboard' ) . '</th><th>' . esc_html__( 'Messages', 'merchboard' ) . '</th><th>' . esc_html__( 'Listening', 'merchboard' ) . '</th><th>' . esc_html__( 'Latest message', 'merchboard' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'merchboard' ) . '</span></th></tr></thead><tbody>';

		foreach ( $channels as $channel ) {
			$last = $this->log->latest( $channel->creator_id, $channel->platform );
			echo '<tr><td>' . esc_html( $this->creators->find( $channel->creator_id )->display_name ?? '' ) . '</td>';
			echo '<td>' . esc_html( $channel->platform->label() ) . '</td>';
			echo '<td><a href="' . esc_url( $channel->platform->channel_url( $channel->broadcaster_login ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $channel->broadcaster_login ) . '</a></td>';
			echo '<td>' . esc_html( $channel->enabled ? __( 'On', 'merchboard' ) : __( 'Off', 'merchboard' ) ) . '</td>';
			echo '<td>' . esc_html( self::listening( $channel ) ) . '</td>';
			echo '<td>' . esc_html( null === $last ? '—' : $last['status'] . ( '' !== $last['reason'] ? ' (' . $last['reason'] . ')' : '' ) ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( self::chat_url( $channel->creator_id, $channel->platform ) ) . '">' . esc_html__( 'Open chat', 'merchboard' ) . '</a></td></tr>';
		}

		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( ChatbotAdminActions::RESUBSCRIBE_ACTION ) . '">';
		wp_nonce_field( ChatbotAdminActions::RESUBSCRIBE_ACTION );
		echo '<p>';
		submit_button( __( 'Renew the chat subscriptions', 'merchboard' ), 'secondary', 'submit', false );
		echo '</p></form>';
	}

	/**
	 * A channel's chat window and "Send as the bot".
	 *
	 * @param int          $creator_id Creator.
	 * @param ChatPlatform $platform   Platform.
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function render_chat( int $creator_id, ChatPlatform $platform ): void {
		$channel = $this->channels->find( $creator_id, $platform );

		echo '<p><a href="' . esc_url( AlertsAdminPage::url( 'chatbot' ) ) . '">← ' . esc_html__( 'Back to the chatbot', 'merchboard' ) . '</a></p>';

		if ( ! $channel->connected() ) {
			echo '<p>' . esc_html__( 'This creator has not added the bot to a channel.', 'merchboard' ) . '</p>';
			return;
		}

		/* translators: 1: channel login, 2: platform (Twitch or Kick). */
		echo '<h2>' . esc_html( sprintf( __( 'Chat of %1$s (%2$s)', 'merchboard' ), $channel->broadcaster_login, $platform->label() ) ) . '</h2>';

		if ( 'enabled' === $channel->subscription_status && $this->enqueue_script() ) {
			echo '<div class="merchboard-chat" data-merchboard-chat data-endpoint="' . esc_url( rest_url( 'merchboard/v1/alerts/chat/' . $platform->value . '/' . $creator_id ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '" data-empty="' . esc_attr__( 'No messages in the last 24 hours.', 'merchboard' ) . '" data-error="' . esc_attr__( 'The chat could not be loaded. It is tried again.', 'merchboard' ) . '" role="log" aria-live="polite" style="height:420px;overflow-y:auto;padding:8px;background:#fff;border:1px solid #c3c4c7"></div>';
			echo '<p class="description">' . esc_html__( 'The bot\'s own view of the chat (webhook). Messages are kept for 24 hours.', 'merchboard' ) . '</p>';
		} elseif ( ChatPlatform::Twitch === $platform ) {
			echo '<p class="description">' . esc_html__( 'Twitch cannot send the chat to this site (no HTTPS webhook), so Twitch\'s own chat is shown. It loads from twitch.tv.', 'merchboard' ) . '</p>';
			echo '<iframe title="' . esc_attr__( 'Twitch chat', 'merchboard' ) . '" src="' . esc_url( 'https://www.twitch.tv/embed/' . rawurlencode( $channel->broadcaster_login ) . '/chat?parent=' . rawurlencode( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) . '" width="100%" height="420" style="max-width:640px;border:1px solid #c3c4c7"></iframe>';
			// Twitch embeds only on HTTPS pages; the pop-out chat works everywhere.
			echo '<p><a href="' . esc_url( 'https://www.twitch.tv/popout/' . rawurlencode( $channel->broadcaster_login ) . '/chat?popout=' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open the chat at Twitch', 'merchboard' ) . '</a></p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Kick cannot send the chat to this site (no HTTPS webhook, or webhooks are off in the Kick app).', 'merchboard' ) . '</p>';
			echo '<p><a href="' . esc_url( 'https://kick.com/popout/' . rawurlencode( $channel->broadcaster_login ) . '/chat' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open the chat at Kick', 'merchboard' ) . '</a></p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( ChatbotAdminActions::SEND_ACTION ) . '">';
		echo '<input type="hidden" name="creator" value="' . esc_attr( (string) $creator_id ) . '">';
		echo '<input type="hidden" name="platform" value="' . esc_attr( $platform->value ) . '">';
		wp_nonce_field( ChatbotAdminActions::nonce_action( ChatbotAdminActions::SEND_ACTION, $creator_id, $platform ) );
		echo '<p><label for="merchboard-chat-message">' . esc_html__( 'Send as the bot', 'merchboard' ) . '</label><br>';
		echo '<input type="text" id="merchboard-chat-message" name="message" class="large-text" maxlength="500" required></p>';
		submit_button( __( 'Send', 'merchboard' ), 'primary', 'submit', false );
		echo '</form>';
	}

	/**
	 * The chat window's URL.
	 *
	 * @param int          $creator_id Creator.
	 * @param ChatPlatform $platform   Platform.
	 */
	public static function chat_url( int $creator_id, ChatPlatform $platform ): string {
		return add_query_arg(
			array(
				'chat'     => $creator_id,
				'platform' => $platform->value,
			),
			AlertsAdminPage::url( 'chatbot' )
		);
	}

	/**
	 * What the subscription status means.
	 *
	 * @param ChatbotChannel $channel Channel.
	 */
	private static function listening( ChatbotChannel $channel ): string {
		return match ( $channel->subscription_status ) {
			'enabled'                               => __( 'Yes', 'merchboard' ),
			'webhook_callback_verification_pending' => __( 'Waiting for Twitch', 'merchboard' ),
			ChatSubscriptions::NO_WEBHOOKS          => __( 'No (site not reachable)', 'merchboard' ),
			''                                      => __( 'No', 'merchboard' ),
			default                                 => $channel->subscription_status,
		};
	}

	/**
	 * Enqueues the chat window script.
	 */
	private function enqueue_script(): bool {
		$file  = $this->plugin_dir . 'build/alerts-admin.asset.php';
		$asset = is_readable( $file ) && is_readable( $this->plugin_dir . 'build/alerts-admin.js' ) ? require $file : null;

		if ( ! is_array( $asset ) || ! is_string( $asset['version'] ?? null ) ) {
			return false;
		}

		wp_enqueue_script( self::SCRIPT, $this->plugin_url . 'build/alerts-admin.js', array(), $asset['version'], true );

		return true;
	}
}
