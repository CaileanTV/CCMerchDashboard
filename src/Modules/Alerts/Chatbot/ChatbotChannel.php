<?php
/**
 * A creator's chatbot settings and channel on one platform.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * A row of `merchboard_alert_chatbot`: whether the creator's purchases are
 * announced in the platform's chat, the template (shared by the creator's
 * platforms, ADR-069 pkt. 2), and the channel the creator added the bot to
 * (empty before that). Never carries a token: the Kick tokens are read
 * separately (ChatbotChannelRepository::tokens()), so they cannot end up in
 * an answer by accident.
 */
final readonly class ChatbotChannel {

	/**
	 * MerchAlert's default message.
	 */
	public const DEFAULT_TEMPLATE = '🔔 $Name just bought $Product!';

	/**
	 * The default message with one message per order.
	 */
	public const DEFAULT_ORDER_TEMPLATE = '🔔 $Name just bought $Products!';

	/**
	 * Longest template in characters (the platforms' longest message).
	 */
	public const TEMPLATE_MAX = 500;

	/**
	 * Constructor.
	 *
	 * @param ChatPlatform $platform            Platform.
	 * @param int          $creator_id          Creator.
	 * @param bool         $enabled             Whether purchases are announced on this platform.
	 * @param string       $template            Template; '' for the default.
	 * @param string       $broadcaster_id      Channel id at the platform; '' before the bot is added.
	 * @param string       $broadcaster_login   Channel login (Twitch) or slug (Kick).
	 * @param int          $authorized_at       When the creator added the bot (Unix time; 0 for never).
	 * @param string       $subscription_id     The bot's chat subscription.
	 * @param string       $subscription_status Its status as the platform reported it.
	 */
	public function __construct(
		public ChatPlatform $platform,
		public int $creator_id,
		public bool $enabled,
		public string $template,
		public string $broadcaster_id,
		public string $broadcaster_login,
		public int $authorized_at,
		public string $subscription_id = '',
		public string $subscription_status = ''
	) {
	}

	/**
	 * Whether the creator added the bot to a channel.
	 */
	public function connected(): bool {
		return '' !== $this->broadcaster_id;
	}

	/**
	 * The template, or the default for the message format.
	 *
	 * @param string $mode 'line' or 'order' (ChatbotFormat).
	 */
	public function template_for( string $mode ): string {
		if ( '' !== $this->template ) {
			return $this->template;
		}

		return 'order' === $mode ? self::DEFAULT_ORDER_TEMPLATE : self::DEFAULT_TEMPLATE;
	}
}
