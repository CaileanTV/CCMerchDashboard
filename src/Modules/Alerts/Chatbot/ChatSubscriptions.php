<?php
/**
 * The bot's chat subscriptions.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * Subscribes the bot to the creators' chats on every platform (Twitch's
 * EventSub, Kick's events; ADR-066, ADR-069), so the admin's chat window
 * shows them, and notes each subscription's status. Where the platform
 * cannot reach the site (no HTTPS, e.g. Local), the status is
 * `no_webhooks` and the admin page shows the platform's own chat instead.
 */
final class ChatSubscriptions {

	/**
	 * Status of a channel whose chat cannot be received (no public HTTPS).
	 */
	public const NO_WEBHOOKS = 'no_webhooks';

	/**
	 * Constructor.
	 *
	 * @param ChatPlatforms            $platforms The platforms.
	 * @param ChatbotChannelRepository $channels  The creators' channels.
	 * @param ChatLogRepository        $chat      The chat log.
	 */
	public function __construct( private ChatPlatforms $platforms, private ChatbotChannelRepository $channels, private ChatLogRepository $chat ) {
	}

	/**
	 * Subscribes the bot to a channel's chat; notes the status.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @return string The subscription's status (or a reason code).
	 * @throws DatabaseException When the status cannot be stored.
	 */
	public function subscribe( ChatbotChannel $channel ): string {
		$platform = $this->platforms->get( $channel->platform );

		if ( ! $channel->connected() || ! $platform->ready() ) {
			return '';
		}

		if ( ! $platform->webhooks_possible() ) {
			$this->channels->set_subscription( $channel->creator_id, $channel->platform, '', self::NO_WEBHOOKS );

			return self::NO_WEBHOOKS;
		}

		try {
			$subscription = $platform->subscribe( $channel );
		} catch ( ChatbotException $e ) {
			$this->channels->set_subscription( $channel->creator_id, $channel->platform, '', $e->reason() );

			return $e->reason();
		}

		$this->channels->set_subscription( $channel->creator_id, $channel->platform, $subscription['id'], $subscription['status'] );

		return $subscription['status'];
	}

	/**
	 * Ends the bot's subscription of a channel and deletes its chat messages.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @throws DatabaseException When the chat messages cannot be deleted.
	 */
	public function unsubscribe( ChatbotChannel $channel ): void {
		$this->platforms->get( $channel->platform )->unsubscribe( $channel );

		if ( '' !== $channel->broadcaster_id ) {
			$this->chat->clear( $channel->platform, $channel->broadcaster_id );
		}
	}

	/**
	 * Subscribes every channel again (after the bot or an app changed).
	 *
	 * @param ChatPlatform|null $platform Only this platform; null for all.
	 * @return int Channels subscribed.
	 * @throws DatabaseException When the channels cannot be read or a status stored.
	 */
	public function subscribe_all( ?ChatPlatform $platform = null ): int {
		$count = 0;

		foreach ( $this->channels->connected( $platform ) as $channel ) {
			if ( in_array( $this->subscribe( $channel ), array( 'enabled', 'webhook_callback_verification_pending' ), true ) ) {
				++$count;
			}
		}

		return $count;
	}
}
