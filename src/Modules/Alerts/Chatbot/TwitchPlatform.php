<?php
/**
 * Twitch as a platform of the alert chatbot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Twitch's adapter (ADR-066, status note 2026-10-20): messages are sent with
 * Send Chat Message and the app access token (chatbot badge and chat
 * identity), the chat is read over an EventSub webhook. No user token is
 * stored, so there is nothing to release.
 */
final class TwitchPlatform implements ChatbotPlatform {

	/**
	 * Constructor.
	 *
	 * @param TwitchAccount $account App and bot.
	 * @param TwitchApi     $api     Twitch.
	 */
	public function __construct( private TwitchAccount $account, private TwitchApi $api ) {
	}

	/**
	 * The platform.
	 */
	public function platform(): ChatPlatform {
		return ChatPlatform::Twitch;
	}

	/**
	 * Whether the app and the bot are set up.
	 */
	public function ready(): bool {
		return $this->account->ready();
	}

	/**
	 * The bot's Twitch login.
	 */
	public function bot_login(): string {
		return $this->account->bot()['login'] ?? '';
	}

	/**
	 * Seconds until Twitch's rate limit window opens again.
	 */
	public function wait_seconds(): int {
		return $this->account->wait_seconds();
	}

	/**
	 * Sends a message with the app access token; a refused token is renewed
	 * once. The rate limit Twitch reports is noted.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @param string         $message Message.
	 * @return array{result: string, reason: string, retry_after: int|null}
	 * @throws TwitchException When the app or the bot is not set up or Twitch refuses the app.
	 */
	public function send( ChatbotChannel $channel, string $message ): array {
		$bot = $this->account->bot();

		if ( null === $bot || '' === trim( $message ) ) {
			throw new TwitchException( TwitchException::NOT_CONFIGURED );
		}

		$client = $this->account->client_id();
		$result = $this->api->send_message( $client, $this->account->app_token(), $channel->broadcaster_id, $bot['id'], $message );

		if ( 'unauthorized' === $result['result'] ) {
			$result = $this->api->send_message( $client, $this->account->app_token( true ), $channel->broadcaster_id, $bot['id'], $message );
		}

		$this->account->note_rate_limit( $result['limit'], $result['remaining'], $result['reset'] );

		return array(
			'result'      => $result['result'],
			'reason'      => $result['reason'],
			'retry_after' => 'rate_limited' === $result['result'] && null !== $result['reset'] ? max( 1, $result['reset'] - time() + 1 ) : null,
		);
	}

	/**
	 * Subscribes the bot to the channel's chat (EventSub over webhook).
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @return array{id: string, status: string}
	 * @throws TwitchException When the bot is not set up or Twitch refuses it.
	 */
	public function subscribe( ChatbotChannel $channel ): array {
		$bot = $this->account->bot();

		if ( null === $bot ) {
			throw new TwitchException( TwitchException::NOT_CONFIGURED );
		}

		return $this->api->subscribe_chat( $this->account->client_id(), $this->account->app_token(), $channel->broadcaster_id, $bot['id'], TwitchAccount::webhook_url(), $this->account->webhook_secret() );
	}

	/**
	 * Ends the channel's chat subscription.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 */
	public function unsubscribe( ChatbotChannel $channel ): void {
		if ( '' === $channel->subscription_id || ! $this->account->has_app() ) {
			return;
		}

		try {
			$this->api->unsubscribe( $this->account->client_id(), $this->account->app_token(), $channel->subscription_id );
		} catch ( TwitchException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Twitch ends a subscription whose authorization is gone by itself.
		}
	}

	/**
	 * Nothing to release: no user token is stored for Twitch.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 */
	public function release( ChatbotChannel $channel ): void {
		unset( $channel );
	}

	/**
	 * Whether Twitch can reach the EventSub webhook.
	 */
	public function webhooks_possible(): bool {
		return TwitchAccount::webhooks_possible();
	}
}
