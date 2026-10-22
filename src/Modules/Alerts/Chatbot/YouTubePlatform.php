<?php
/**
 * YouTube as a platform of the alert chatbot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Alerts\Legal\YouTubeTerms;

defined( 'ABSPATH' ) || exit;

/**
 * YouTube's adapter (ADR-070): a message can only be written in the live
 * chat of the creator's current livestream, as the bot channel with the
 * bot's token. Whether the creator is live is looked up with the creator's
 * token (`liveBroadcasts.list`, 1 quota unit) and kept briefly, so the lines
 * of one order cost one lookup. When the creator is not live, the result is
 * `not_live` and ChatSender lets the message wait (pkt. 5). When the day's
 * quota is used up, messages wait until it resets (pkt. 6). YouTube has no
 * chat webhooks; the admin page embeds YouTube's own live chat.
 */
final class YouTubePlatform implements ChatbotPlatform {

	/**
	 * Transient prefix of a creator's current live chat ('' for not live).
	 */
	private const LIVE_PREFIX = 'merchboard_youtube_live_';

	/**
	 * Seconds a found live chat is kept.
	 */
	private const LIVE_TTL = 600;

	/**
	 * Seconds "not live" is kept (the same order's lines share one lookup).
	 */
	private const NOT_LIVE_TTL = 60;

	/**
	 * Subscription status of a YouTube channel: its chat is shown embedded.
	 */
	public const EMBEDDED = 'embedded';

	/**
	 * Constructor.
	 *
	 * @param YouTubeAccount           $account  The Google app and the bot.
	 * @param YouTubeApi               $api      YouTube.
	 * @param CreatorTokens            $tokens   The creators' Google tokens.
	 * @param ChatbotChannelRepository $channels The creators' channels.
	 * @param YouTubeTerms             $terms    Accepted privacy policy and terms (ADR-071).
	 * @param Logger                   $logger   Technical log (refused messages with Google's reason, never a token).
	 */
	public function __construct( private YouTubeAccount $account, private YouTubeApi $api, private CreatorTokens $tokens, private ChatbotChannelRepository $channels, private YouTubeTerms $terms, private Logger $logger ) {
	}

	/**
	 * The platform.
	 */
	public function platform(): ChatPlatform {
		return ChatPlatform::YouTube;
	}

	/**
	 * Whether the app and a working bot are set up, and the administrator
	 * accepted the published privacy policy and terms for the bot.
	 */
	public function ready(): bool {
		return $this->account->ready() && $this->terms->bot_accepted();
	}

	/**
	 * The bot channel's name.
	 */
	public function bot_login(): string {
		return $this->account->bot()['name'] ?? '';
	}

	/**
	 * Seconds until the day's quota resets (0 when it is not used up).
	 */
	public function wait_seconds(): int {
		return $this->account->wait_seconds();
	}

	/**
	 * Sends a message in the creator's live chat as the bot.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @param string         $message Message.
	 * @return array{result: string, reason: string, retry_after: int|null} result also `not_live` when the creator is not live, `skipped` (`terms_not_accepted`) when the creator has not accepted the current privacy policy and terms, `rate_limited` (`quota_exceeded`) while the day's quota is used up and `forbidden` (`live_not_allowed`) when Google does not let the creator's account see its livestreams.
	 * @throws ChatbotException When the app or the bot is not set up, or Google cannot be reached.
	 * @throws DatabaseException When the creator's tokens cannot be read or stored.
	 */
	public function send( ChatbotChannel $channel, string $message ): array {
		$this->require_ready( $message );

		// A creator who has not accepted the current privacy policy and terms gets no messages (ADR-071).
		if ( ! $this->terms->creator_accepted( $channel->creator_id ) ) {
			return self::outcome( 'skipped', 'terms_not_accepted' );
		}

		// The day's quota is used up: no call until it resets (also for test messages).
		if ( $this->account->wait_seconds() > 0 ) {
			return self::outcome( 'rate_limited', 'quota_exceeded', $this->account->wait_seconds() );
		}

		try {
			$live = $this->live( $channel );
		} catch ( ChatbotException $e ) {
			return $this->live_failure( $channel, $e );
		}

		if ( null === $live ) {
			return self::outcome( 'unauthorized', 'authorization_revoked' );
		}

		if ( '' === $live['chat'] ) {
			return self::outcome( 'not_live', 'not_live' );
		}

		$result = $this->api->send_message( $this->account->bot_access(), $live['chat'], $message );

		if ( 'unauthorized' === $result['result'] ) {
			$result = $this->api->send_message( $this->account->bot_access( true ), $live['chat'], $message );
		}

		if ( 'not_live' === $result['result'] ) {
			// The stream ended since the lookup.
			delete_transient( self::LIVE_PREFIX . $channel->creator_id );
		}

		if ( 'quota' === $result['result'] ) {
			$this->account->note_quota_exhausted();

			return self::outcome( 'rate_limited', 'quota_exceeded', $this->account->wait_seconds() );
		}

		if ( ! in_array( $result['result'], array( 'sent', 'not_live' ), true ) ) {
			$this->logger->warning(
				'YouTube refused a chat message of the bot.',
				array(
					'creator_id' => $channel->creator_id,
					'result'     => $result['result'],
					'reason'     => $result['reason'],
					'status'     => $result['status'],
					'detail'     => $result['detail'],
				)
			);
		}

		return self::outcome( $result['result'], $result['reason'], $result['retry_after'] );
	}

	/**
	 * A failed live lookup: logged with Google's error code and text (never
	 * a token). A used-up quota makes the messages wait until it resets; an
	 * account that may not see its livestreams is a final refusal.
	 *
	 * @param ChatbotChannel   $channel The creator's channel.
	 * @param ChatbotException $e       Failure.
	 * @return array{result: string, reason: string, retry_after: int|null}
	 * @throws ChatbotException Every other failure (e.g. Google cannot be reached).
	 */
	private function live_failure( ChatbotChannel $channel, ChatbotException $e ): array {
		$this->logger->warning(
			'YouTube refused the live check of a creator.',
			array(
				'creator_id' => $channel->creator_id,
				'reason'     => $e->reason(),
				'status'     => $e->status(),
				'detail'     => $e->detail(),
			)
		);

		if ( ChatbotException::QUOTA_EXCEEDED === $e->reason() ) {
			$this->account->note_quota_exhausted();

			return self::outcome( 'rate_limited', 'quota_exceeded', $this->account->wait_seconds() );
		}

		if ( ChatbotException::LIVE_NOT_ALLOWED === $e->reason() ) {
			return self::outcome( 'forbidden', 'live_not_allowed' );
		}

		throw $e;
	}

	/**
	 * The video of the creator's current livestream, for the admin's chat
	 * window; '' when the creator is not live or cannot be looked up.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @throws DatabaseException When the creator's tokens cannot be read.
	 */
	public function live_video( ChatbotChannel $channel ): string {
		try {
			return $this->live( $channel )['video'] ?? '';
		} catch ( ChatbotException ) {
			return '';
		}
	}

	/**
	 * Nothing to subscribe: YouTube has no chat webhooks, and the admin page
	 * embeds YouTube's live chat.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @return array{id: string, status: string}
	 */
	public function subscribe( ChatbotChannel $channel ): array {
		unset( $channel );

		return array(
			'id'     => '',
			'status' => self::EMBEDDED,
		);
	}

	/**
	 * Nothing to end.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 */
	public function unsubscribe( ChatbotChannel $channel ): void {
		delete_transient( self::LIVE_PREFIX . $channel->creator_id );
	}

	/**
	 * Revokes the creator's tokens at Google.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @throws DatabaseException When the tokens cannot be read.
	 */
	public function release( ChatbotChannel $channel ): void {
		$this->tokens->revoke( $channel->creator_id );
	}

	/**
	 * Always: nothing has to reach the site.
	 */
	public function webhooks_possible(): bool {
		return true;
	}

	/**
	 * The creator's current livestream (chat '' when not live), kept
	 * briefly; null when the creator revoked the access at Google (the
	 * channel is then forgotten).
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @return array{video: string, chat: string}|null
	 * @throws ChatbotException When Google cannot be reached.
	 * @throws DatabaseException When the tokens cannot be read or stored.
	 */
	private function live( ChatbotChannel $channel ): ?array {
		$key    = self::LIVE_PREFIX . $channel->creator_id;
		$cached = get_transient( $key );

		if ( is_array( $cached ) && is_string( $cached['video'] ?? null ) && is_string( $cached['chat'] ?? null ) ) {
			return array(
				'video' => $cached['video'],
				'chat'  => $cached['chat'],
			);
		}

		try {
			$broadcast = $this->broadcast( $channel->creator_id );
		} catch ( ChatbotException $e ) {
			return $this->revoked( $channel, $e );
		}

		$live = $broadcast ?? array(
			'video' => '',
			'chat'  => '',
		);
		set_transient( $key, $live, null === $broadcast ? self::NOT_LIVE_TTL : self::LIVE_TTL );

		return $live;
	}

	/**
	 * The creator's current livestream; an access token Google refuses is
	 * renewed once.
	 *
	 * @param int $creator_id Creator.
	 * @return array{video: string, chat: string}|null
	 * @throws ChatbotException UNAUTHORIZED when the renewed token is refused too; others when Google cannot be reached.
	 * @throws DatabaseException When the tokens cannot be read or stored.
	 */
	private function broadcast( int $creator_id ): ?array {
		try {
			return $this->api->active_broadcast( $this->tokens->access( $creator_id ) );
		} catch ( ChatbotException $e ) {
			self::require_unauthorized( $e );
		}

		return $this->api->active_broadcast( $this->tokens->access( $creator_id, true ) );
	}

	/**
	 * Passes on every failure but a refused token.
	 *
	 * @param ChatbotException $e Failure.
	 * @throws ChatbotException When it is not UNAUTHORIZED.
	 */
	private static function require_unauthorized( ChatbotException $e ): void {
		if ( ChatbotException::UNAUTHORIZED !== $e->reason() ) {
			throw $e;
		}
	}

	/**
	 * Forgets the channel when the failure means the creator revoked the
	 * access; any other failure is passed on.
	 *
	 * @param ChatbotChannel   $channel The creator's channel.
	 * @param ChatbotException $e       Failure.
	 * @throws ChatbotException Every other failure.
	 * @throws DatabaseException When the channel cannot be forgotten.
	 */
	private function revoked( ChatbotChannel $channel, ChatbotException $e ): null {
		if ( ChatbotException::UNAUTHORIZED !== $e->reason() ) {
			throw $e;
		}

		$this->channels->disconnect( $channel->creator_id, ChatPlatform::YouTube );

		return null;
	}

	/**
	 * Requires a working setup and a message.
	 *
	 * @param string $message Message.
	 * @throws ChatbotException When the app or the bot is not set up or the message is empty.
	 */
	private function require_ready( string $message ): void {
		if ( ! $this->ready() || '' === trim( $message ) ) {
			throw new ChatbotException( ChatbotException::NOT_CONFIGURED );
		}
	}

	/**
	 * A sending outcome.
	 *
	 * @param string   $result      Result.
	 * @param string   $reason      Reason.
	 * @param int|null $retry_after Seconds to wait.
	 * @return array{result: string, reason: string, retry_after: int|null}
	 */
	private static function outcome( string $result, string $reason, ?int $retry_after = null ): array {
		return array(
			'result'      => $result,
			'reason'      => $reason,
			'retry_after' => $retry_after,
		);
	}
}
