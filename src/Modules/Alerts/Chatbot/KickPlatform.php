<?php
/**
 * Kick as a platform of the alert chatbot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * Kick's adapter (ADR-069): messages are sent as the app's bot
 * (`type: bot`) with the creator's own token, which is renewed when Kick
 * refuses it. When the creator has revoked the access at Kick (the refresh
 * token is refused), the channel and its tokens are forgotten, so the
 * creator sees "Add the bot to your channel" again. The chat is read over
 * Kick's webhook with the app access token.
 */
final class KickPlatform implements ChatbotPlatform {

	/**
	 * Constructor.
	 *
	 * @param KickAccount              $account  The Kick app.
	 * @param KickApi                  $api      Kick.
	 * @param CreatorTokens            $tokens   The creators' Kick tokens.
	 * @param ChatbotChannelRepository $channels The creators' channels.
	 */
	public function __construct( private KickAccount $account, private KickApi $api, private CreatorTokens $tokens, private ChatbotChannelRepository $channels ) {
	}

	/**
	 * The platform.
	 */
	public function platform(): ChatPlatform {
		return ChatPlatform::Kick;
	}

	/**
	 * Whether the app is set up.
	 */
	public function ready(): bool {
		return $this->account->has_app();
	}

	/**
	 * The bot's name as the administrator entered it.
	 */
	public function bot_login(): string {
		return $this->account->bot_name();
	}

	/**
	 * Seconds until Kick's last "too many requests" has passed.
	 */
	public function wait_seconds(): int {
		return $this->account->wait_seconds();
	}

	/**
	 * Sends a message as the bot with the creator's token; a refused token
	 * is renewed once.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @param string         $message Message.
	 * @return array{result: string, reason: string, retry_after: int|null}
	 * @throws KickException When the app is not set up or Kick cannot be reached for a token.
	 * @throws DatabaseException When the tokens cannot be read or stored.
	 */
	public function send( ChatbotChannel $channel, string $message ): array {
		$this->require_app( $message );
		$result = $this->send_with_token( $channel, $message );

		if ( null === $result ) {
			// The creator revoked the access at Kick: the channel is forgotten.
			$this->channels->disconnect( $channel->creator_id, ChatPlatform::Kick );

			return array(
				'result'      => 'unauthorized',
				'reason'      => 'authorization_revoked',
				'retry_after' => null,
			);
		}

		if ( 'rate_limited' === $result['result'] && null !== $result['retry_after'] ) {
			$this->account->note_backoff( $result['retry_after'] );
		}

		return $result;
	}

	/**
	 * Sends with the creator's token, renewed once when Kick refuses it;
	 * null when the creator revoked the access.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @param string         $message Message.
	 * @return array{result: string, reason: string, retry_after: int|null}|null
	 * @throws ChatbotException When Kick cannot be reached for a token.
	 * @throws DatabaseException When the tokens cannot be read or stored.
	 */
	private function send_with_token( ChatbotChannel $channel, string $message ): ?array {
		try {
			$result = $this->api->send_message( $this->tokens->access( $channel->creator_id ), $message );

			return 'unauthorized' === $result['result'] ? $this->api->send_message( $this->tokens->access( $channel->creator_id, true ), $message ) : $result;
		} catch ( ChatbotException $e ) {
			return self::revoked( $e );
		}
	}

	/**
	 * Null when the failure means the creator revoked the access.
	 *
	 * @param ChatbotException $e Failure.
	 * @throws ChatbotException Every other failure.
	 */
	private static function revoked( ChatbotException $e ): null {
		if ( ChatbotException::UNAUTHORIZED !== $e->reason() ) {
			throw $e;
		}

		return null;
	}

	/**
	 * Requires the app and a message.
	 *
	 * @param string $message Message.
	 * @throws KickException When the app is not set up or the message is empty.
	 */
	private function require_app( string $message ): void {
		if ( ! $this->account->has_app() || '' === trim( $message ) ) {
			throw new KickException( KickException::NOT_CONFIGURED );
		}
	}

	/**
	 * Subscribes the app to the channel's chat.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @return array{id: string, status: string}
	 * @throws KickException When the app is not set up or Kick refuses it.
	 */
	public function subscribe( ChatbotChannel $channel ): array {
		return $this->api->subscribe_chat( $this->account->app_token(), $channel->broadcaster_id );
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
			$this->api->unsubscribe( $this->account->app_token(), $channel->subscription_id );
		} catch ( KickException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Kick ends a subscription that keeps failing by itself.
		}
	}

	/**
	 * Revokes the creator's tokens at Kick.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @throws DatabaseException When the tokens cannot be read.
	 */
	public function release( ChatbotChannel $channel ): void {
		$this->tokens->revoke( $channel->creator_id );
	}

	/**
	 * Whether Kick can reach the webhook.
	 */
	public function webhooks_possible(): bool {
		return KickAccount::webhooks_possible();
	}
}
