<?php
/**
 * A creator's chatbot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlays;
use MerchBoard\Modules\Alerts\Overlay\OverlayRateLimiter;
use MerchBoard\Modules\Alerts\Legal\YouTubeTerms;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's side of the chatbot on every platform (Alerts → Chatbot,
 * phase 25 steps 8 and 8.2; ADR-069 pkt. 2). Callers pass the creator that
 * CreatorContext resolved.
 *
 * - **Channels:** one per platform; the creator adds the bot to the own
 *   channel (TwitchOAuth, KickOAuth) and can remove it again; both are
 *   audited with the channel's login and the platform.
 * - **Messages:** one template shared by the platforms, messages on or off
 *   per platform; not audited (like the other alert settings).
 * - **Test message:** a sample purchase ("TestFan") sent right away on one
 *   platform, in the format the administrator chose.
 */
final class CreatorChatbot {

	/**
	 * Username of a test message.
	 */
	public const TEST_USERNAME = 'TestFan';

	/**
	 * Test messages a creator may send per minute (25.13 review).
	 */
	public const TESTS_PER_MINUTE = 10;

	/**
	 * Limit of the creator's test messages.
	 *
	 * @var OverlayRateLimiter
	 */
	private OverlayRateLimiter $tests;

	/**
	 * Constructor.
	 *
	 * @param ChatPlatforms            $platforms     The platforms.
	 * @param ChatbotChannelRepository $channels      The creators' channels.
	 * @param ChatMessageRepository    $log           Message log.
	 * @param ChatbotFormat            $format        Message format.
	 * @param ChatMessages             $messages      Message texts.
	 * @param ChatSubscriptions        $subscriptions Chat subscriptions.
	 * @param ChatSender               $sender        Sending.
	 * @param AuditTrail               $audit         Audit trail.
	 * @param Transaction              $transaction   Database transaction.
	 * @param YouTubeTerms             $terms         YouTube's privacy policy and terms (ADR-071).
	 * @param OverlayRateLimiter|null  $tests         Limit of the test messages; defaults to TESTS_PER_MINUTE.
	 */
	public function __construct(
		private ChatPlatforms $platforms,
		private ChatbotChannelRepository $channels,
		private ChatMessageRepository $log,
		private ChatbotFormat $format,
		private ChatMessages $messages,
		private ChatSubscriptions $subscriptions,
		private ChatSender $sender,
		private AuditTrail $audit,
		private Transaction $transaction,
		private YouTubeTerms $terms,
		?OverlayRateLimiter $tests = null
	) {
		$this->tests = $tests ?? new OverlayRateLimiter( null, 'merchboard_alerts_chat_test_', self::TESTS_PER_MINUTE );
	}

	/**
	 * The creator accepts the current privacy policy and terms of the YouTube
	 * chatbot (ADR-071); the current user is the creator's (CreatorContext).
	 *
	 * @param int $creator_id Creator.
	 * @throws ChatbotException TERMS_REQUIRED while the texts are not published.
	 * @throws DatabaseException When it cannot be audited.
	 */
	public function accept_youtube_terms( int $creator_id ): void {
		$this->terms->accept_creator( $creator_id, get_current_user_id() );
	}

	/**
	 * What the dashboard shows.
	 *
	 * @param int $creator_id Creator.
	 * @return array{mode: string, item_format: string, separator: string, variables: list<string>, template: string, default_template: string, platforms: list<array{platform: string, label: string, available: bool, bot_login: string, enabled: bool, channel: array{login: string, url: string, connected_at: string}|null, last_message: array{status: string, reason: string, at: string}|null, terms: array{privacy_url: string, terms_url: string, version: int, accepted: bool}|null}>}
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function describe( int $creator_id ): array {
		$channels  = $this->channels->for_creator( $creator_id );
		$format    = $this->format->get();
		$mode      = $format['mode'];
		$platforms = array();

		foreach ( $this->platforms->all() as $adapter ) {
			$platform    = $adapter->platform();
			$channel     = $channels[ $platform->value ];
			$last        = $this->log->latest( $creator_id, $platform );
			$platforms[] = array(
				'platform'     => $platform->value,
				'label'        => $platform->label(),
				'available'    => $adapter->ready(),
				'bot_login'    => $adapter->bot_login(),
				'enabled'      => $channel->enabled,
				'channel'      => $channel->connected() ? array(
					'login'        => $channel->broadcaster_login,
					'url'          => $platform->channel_url( $channel->broadcaster_id, $channel->broadcaster_login ),
					'connected_at' => gmdate( 'Y-m-d\TH:i:s\Z', $channel->authorized_at ),
				) : null,
				'last_message' => null === $last ? null : array(
					'status' => $last['status'],
					'reason' => $last['reason'],
					'at'     => gmdate( 'Y-m-d\TH:i:s\Z', $last['at'] ),
				),
				// Only YouTube asks for the privacy policy and terms, and only once they are published.
				'terms'        => ChatPlatform::YouTube === $platform && $this->terms->published() ? array(
					'privacy_url' => $this->terms->url( 'privacy' ),
					'terms_url'   => $this->terms->url( 'terms' ),
					'version'     => $this->terms->version(),
					'accepted'    => $this->terms->creator_accepted( $creator_id ),
				) : null,
			);
		}

		return array(
			'mode'             => $mode,
			'item_format'      => $format['item'],
			'separator'        => $format['separator'],
			'variables'        => 'order' === $mode ? ChatMessages::ORDER_VARIABLES : ChatMessages::LINE_VARIABLES,
			'template'         => $channels[ ChatPlatform::Twitch->value ]->template,
			'default_template' => 'order' === $mode ? ChatbotChannel::DEFAULT_ORDER_TEMPLATE : ChatbotChannel::DEFAULT_TEMPLATE,
			'platforms'        => $platforms,
		);
	}

	/**
	 * Saves the shared template and whether purchases are announced per
	 * platform.
	 *
	 * @param int                 $creator_id Creator.
	 * @param string|null         $template   Template ('' for the default; null: unchanged); plain text, one line, at most 500 characters.
	 * @param array<string, bool> $enabled    On or off by platform value (unknown platforms are ignored).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save( int $creator_id, ?string $template, array $enabled ): void {
		if ( null !== $template ) {
			$template = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $template ) ) );
			$this->channels->save_template( $creator_id, mb_substr( $template, 0, ChatbotChannel::TEMPLATE_MAX ) );
		}

		foreach ( $enabled as $value => $on ) {
			$platform = ChatPlatform::parse( $value );

			if ( null !== $platform ) {
				$this->channels->save_enabled( $creator_id, $platform, $on );
			}
		}
	}

	/**
	 * Removes the bot from the creator's channel on a platform.
	 *
	 * @param int          $creator_id Creator.
	 * @param ChatPlatform $platform   Platform.
	 * @return bool Whether a channel was removed.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function disconnect( int $creator_id, ChatPlatform $platform ): bool {
		$channel = $this->channels->find( $creator_id, $platform );

		if ( ! $channel->connected() ) {
			return false;
		}

		// Kick: the creator's token is revoked before it is deleted.
		$this->platforms->get( $platform )->release( $channel );
		$this->transaction->run(
			function () use ( $creator_id, $channel ): void {
				$this->audit->record(
					AuditAction::AlertChatbotChannelDisconnected,
					$creator_id,
					array(
						'login'    => $channel->broadcaster_login,
						'platform' => $channel->platform->value,
					)
				);
				$this->channels->disconnect( $creator_id, $channel->platform );
			}
		);
		$this->subscriptions->unsubscribe( $channel );

		return true;
	}

	/**
	 * Sends a test message to the creator's channel on a platform.
	 *
	 * @param int          $creator_id Creator.
	 * @param ChatPlatform $platform   Platform.
	 * @return array{result: string, reason: string, message: string}
	 * @throws ChatbotException When the platform or the channel is not set up, the creator sent too many tests or the platform refuses the app.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function test( int $creator_id, ChatPlatform $platform ): array {
		$channel = self::require_channel( $this->channels->find( $creator_id, $platform ) );
		self::require_allowed( $this->tests->allow( 'creator:' . $creator_id ) );
		$format  = $this->format->get();
		$event   = new AlertEvent( $creator_id, 0, '0001', 0, 0, 0, __( 'Test product', 'merchboard' ), '', 1, 0, array(), self::TEST_USERNAME, new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ), 0, AlertOverlays::TEST_AMOUNT, AlertOverlays::TEST_AMOUNT );
		$message = 'order' === $format['mode']
			? $this->messages->order( $channel->template_for( 'order' ), array( $event ), $format )
			: $this->messages->line( $channel->template_for( 'line' ), $event );
		$result  = $this->sender->send_now( $channel, $message );

		// The creator is live (YouTube): messages that waited for the stream follow.
		if ( 'sent' === $result['result'] && ChatPlatform::YouTube === $platform ) {
			$this->sender->flush_waiting( $creator_id, $platform );
		}

		return array(
			'result'  => $result['result'],
			'reason'  => $result['reason'],
			'message' => $message,
		);
	}

	/**
	 * Requires a channel with the bot.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @throws ChatbotException When the creator has not added the bot.
	 */
	private static function require_channel( ChatbotChannel $channel ): ChatbotChannel {
		if ( ! $channel->connected() ) {
			throw new ChatbotException( ChatbotException::NOT_CONFIGURED );
		}

		return $channel;
	}

	/**
	 * Requires a test within the creator's limit.
	 *
	 * @param bool $allowed Whether the limiter allowed it.
	 * @throws ChatbotException TOO_MANY beyond TESTS_PER_MINUTE.
	 */
	private static function require_allowed( bool $allowed ): void {
		if ( ! $allowed ) {
			throw new ChatbotException( ChatbotException::TOO_MANY );
		}
	}
}
