<?php
/**
 * The bot's EventSub chat subscriptions.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * The bot listens to the chat of every channel it was added to (ADR-066,
 * status note 2026-10-20): EventSub `channel.chat.message` over webhook,
 * with the app access token, which gives the bot Twitch's chat identity
 * (listed under "Chat Bots") and feeds the admin's chat window. A site
 * Twitch cannot reach (not HTTPS, or a local site) gets no subscription;
 * the admin page shows Twitch's embedded chat there instead.
 */
final class ChatSubscriptions {

	/**
	 * Status of a channel on a site Twitch cannot reach.
	 */
	public const NO_WEBHOOKS = 'no_webhooks';

	/**
	 * Constructor.
	 *
	 * @param TwitchAccount            $account  App and bot.
	 * @param TwitchApi                $api      Twitch.
	 * @param ChatbotChannelRepository $channels The creators' channels.
	 * @param ChatLogRepository        $chat     Chat window messages.
	 */
	public function __construct( private TwitchAccount $account, private TwitchApi $api, private ChatbotChannelRepository $channels, private ChatLogRepository $chat ) {
	}

	/**
	 * Subscribes the bot to a channel's chat; notes the status.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @return string The subscription's status (or a reason code).
	 * @throws DatabaseException When the status cannot be stored.
	 */
	public function subscribe( ChatbotChannel $channel ): string {
		$bot = $this->account->bot();

		if ( ! $channel->connected() || null === $bot ) {
			return '';
		}

		if ( ! TwitchAccount::webhooks_possible() ) {
			$this->channels->set_subscription( $channel->creator_id, '', self::NO_WEBHOOKS );

			return self::NO_WEBHOOKS;
		}

		try {
			$subscription = $this->api->subscribe_chat( $this->account->client_id(), $this->account->app_token(), $channel->broadcaster_id, $bot['id'], TwitchAccount::webhook_url(), $this->account->webhook_secret() );
		} catch ( TwitchException $e ) {
			$this->channels->set_subscription( $channel->creator_id, '', $e->reason() );

			return $e->reason();
		}

		$this->channels->set_subscription( $channel->creator_id, $subscription['id'], $subscription['status'] );

		return $subscription['status'];
	}

	/**
	 * Ends the bot's subscription of a channel and deletes its chat messages.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @throws DatabaseException When the chat messages cannot be deleted.
	 */
	public function unsubscribe( ChatbotChannel $channel ): void {
		if ( '' !== $channel->subscription_id && $this->account->has_app() ) {
			try {
				$this->api->unsubscribe( $this->account->client_id(), $this->account->app_token(), $channel->subscription_id );
			} catch ( TwitchException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Twitch ends a subscription whose authorization is gone by itself.
			}
		}

		if ( '' !== $channel->broadcaster_id ) {
			$this->chat->clear( $channel->broadcaster_id );
		}
	}

	/**
	 * Subscribes every channel again (after the bot or the app changed).
	 *
	 * @return int Channels subscribed.
	 * @throws DatabaseException When the channels cannot be read or a status stored.
	 */
	public function subscribe_all(): int {
		$count = 0;

		foreach ( $this->channels->connected() as $channel ) {
			if ( in_array( $this->subscribe( $channel ), array( 'enabled', 'webhook_callback_verification_pending' ), true ) ) {
				++$count;
			}
		}

		return $count;
	}
}
