<?php
/**
 * A streaming platform of the alert chatbot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * What the shared chatbot engine (ChatSender, ChatSubscriptions,
 * CreatorChatbot, ADR-069 pkt. 1) needs from a platform: sending a message
 * in a creator's channel, subscribing to its chat and letting go of what the
 * platform granted. Login (OAuth) and the platform's webhook are the
 * platform's own (TwitchOAuth/KickOAuth, TwitchController/KickController).
 */
interface ChatbotPlatform {

	/**
	 * The platform.
	 */
	public function platform(): ChatPlatform;

	/**
	 * Whether an administrator has set the platform up, so creators can add
	 * the bot and messages can be sent.
	 */
	public function ready(): bool;

	/**
	 * The bot's name for the creator page; '' when unknown.
	 */
	public function bot_login(): string;

	/**
	 * Seconds to wait before the next message may be sent (0: now).
	 */
	public function wait_seconds(): int;

	/**
	 * Sends a message in a creator's channel as the bot.
	 *
	 * @param ChatbotChannel $channel The creator's channel (connected).
	 * @param string         $message Message.
	 * @return array{result: string, reason: string, retry_after: int|null} result: sent, dropped, rate_limited, unauthorized, forbidden or failed; retry_after: seconds Twitch or Kick asked to wait.
	 * @throws ChatbotException When the platform is not set up or refuses the app.
	 * @throws DatabaseException When stored tokens cannot be read or written.
	 */
	public function send( ChatbotChannel $channel, string $message ): array;

	/**
	 * Subscribes the bot to a channel's chat (the platform's webhook).
	 *
	 * @param ChatbotChannel $channel The creator's channel (connected).
	 * @return array{id: string, status: string}
	 * @throws ChatbotException When the platform refuses it or cannot be reached.
	 */
	public function subscribe( ChatbotChannel $channel ): array;

	/**
	 * Ends a channel's chat subscription; failures are ignored (the
	 * platform ends a subscription whose authorization is gone by itself).
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 */
	public function unsubscribe( ChatbotChannel $channel ): void;

	/**
	 * Lets go of what the platform granted for a channel before it is
	 * forgotten (Kick: revokes the creator's token). Failures are ignored.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @throws DatabaseException When stored tokens cannot be read.
	 */
	public function release( ChatbotChannel $channel ): void;

	/**
	 * Whether the platform can reach the site's webhook (an HTTPS site on
	 * port 443, not a local one).
	 */
	public function webhooks_possible(): bool;
}
