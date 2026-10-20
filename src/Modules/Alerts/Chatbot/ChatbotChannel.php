<?php
/**
 * A creator's chatbot settings and channel.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * A row of `merchboard_alert_chatbot`: whether the creator's purchases are
 * announced in chat, the template, and the Twitch channel the creator added
 * the bot to (empty before that).
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
	 * Longest template in characters (Twitch's longest message).
	 */
	public const TEMPLATE_MAX = 500;

	/**
	 * Constructor.
	 *
	 * @param int    $creator_id          Creator.
	 * @param bool   $enabled             Whether purchases are announced.
	 * @param string $template            Template; '' for the default.
	 * @param string $broadcaster_id      Twitch channel id; '' before the bot is added.
	 * @param string $broadcaster_login   Twitch channel login.
	 * @param int    $authorized_at       When the creator added the bot (Unix time; 0 for never).
	 * @param string $subscription_id     EventSub chat subscription.
	 * @param string $subscription_status Its status as Twitch reported it.
	 */
	public function __construct(
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
