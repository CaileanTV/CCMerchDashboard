<?php
/**
 * Sends the chat messages of paid orders.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\WooCommerce\JobQueue;

defined( 'ABSPATH' ) || exit;

/**
 * The chat messages of the alerts on every platform (ADR-066 pkt. 3, its
 * status note 2026-10-20 and ADR-069 pkt. 1), sent in the background
 * through Action Scheduler, never in a customer's or creator's request.
 *
 * - **When:** a job per creator, order and platform, queued when the
 *   order's alerts are recorded (and again for a resend). The job sends one
 *   message per line or one per order (ChatbotFormat) to the channel the
 *   creator added the bot to, while alerts are switched on, the platform is
 *   set up and the creator's messages on that platform are on.
 * - **Never twice:** the message log (ChatMessageRepository) is checked per
 *   platform before sending; a resend forgets the entry first.
 * - **Rate limit:** the platform tells how long to wait (Twitch's
 *   `Ratelimit-*`, Kick's 429); the job waits that long. Lines of one order
 *   are at least a second apart (the platforms' limit per channel). A
 *   message is tried at most MAX_ATTEMPTS times.
 * - **Logs** only reason codes, never tokens or the message.
 */
final class ChatSender {

	/**
	 * Action Scheduler hook; arguments: creator id, order id, platform
	 * (jobs queued before step 8.2 have no platform: Twitch).
	 */
	public const HOOK = 'merchboard_alert_chat_send';

	/**
	 * Most attempts per message.
	 */
	public const MAX_ATTEMPTS = 5;

	/**
	 * Seconds between two lines of an order.
	 */
	private const LINE_GAP = 1;

	/**
	 * Seconds before a failed attempt is tried again.
	 */
	private const RETRY_DELAY = 60;

	/**
	 * Seconds before the one live check again of a message whose creator was
	 * not live (YouTube, ADR-070 pkt. 5, statusnote 2026-10-21): afterwards
	 * it is skipped, so each purchase costs at most two live checks.
	 */
	private const LIVE_RETRY = 300;

	/**
	 * Transient prefix: a creator's waiting messages were just sent on
	 * (once per ten minutes, so jobs do not queue each other in turn).
	 */
	private const FLUSHED_PREFIX = 'merchboard_chat_flushed_';

	/**
	 * Constructor.
	 *
	 * @param ChatPlatforms            $platforms    The platforms.
	 * @param ChatbotChannelRepository $channels     The creators' channels.
	 * @param ChatMessageRepository    $log          Message log.
	 * @param ChatbotFormat            $format       Message format.
	 * @param ChatMessages             $messages     Message texts.
	 * @param AlertEventRepository     $events       Alerts.
	 * @param AlertsAvailability       $availability Kill switch and MerchAlert guard.
	 * @param JobQueue                 $queue        Background jobs.
	 * @param Logger                   $logger       Technical log.
	 */
	public function __construct(
		private ChatPlatforms $platforms,
		private ChatbotChannelRepository $channels,
		private ChatMessageRepository $log,
		private ChatbotFormat $format,
		private ChatMessages $messages,
		private AlertEventRepository $events,
		private AlertsAvailability $availability,
		private JobQueue $queue,
		private Logger $logger
	) {
	}

	/**
	 * Queues the messages of an order's alerts for every creator that has
	 * alerts in it, on each platform where the creator has the bot
	 * (`woocommerce_order_status_*`, after the alerts are recorded). Cheap
	 * when no platform is set up.
	 *
	 * @param mixed $order_id Order id from WooCommerce.
	 */
	public function order_paid( mixed $order_id ): void {
		if ( ! is_numeric( $order_id ) || (int) $order_id <= 0 || array() === $this->platforms->ready() || ! $this->availability->sending() ) {
			return;
		}

		try {
			foreach ( $this->events->creators_of_order( (int) $order_id ) as $creator_id ) {
				foreach ( $this->active_channels( $creator_id ) as $channel ) {
					$this->schedule( $creator_id, (int) $order_id, $channel->platform );
				}
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Chat messages could not be queued.', array( 'reason' => $e->getMessage() ) );
		}
	}

	/**
	 * Sends the messages of an order again (from the history) on each
	 * platform where the creator has the bot: the log entries are forgotten
	 * and jobs are queued.
	 *
	 * @param int $creator_id    Creator.
	 * @param int $order_id      Order.
	 * @param int $order_item_id One line; 0 for all of the order.
	 * @return bool Whether a job was queued (false while the chatbot is off everywhere).
	 * @throws DatabaseException When the log cannot be updated.
	 */
	public function resend( int $creator_id, int $order_id, int $order_item_id = 0 ): bool {
		$queued = false;

		foreach ( $this->active_channels( $creator_id ) as $channel ) {
			if ( 'order' === $this->format->get()['mode'] ) {
				$this->log->forget( $creator_id, $channel->platform, $order_id, 0 );
			} else {
				foreach ( $this->events->for_creator_orders( $creator_id, array( $order_id ) ) as $event ) {
					if ( 0 === $order_item_id || $event->order_item_id === $order_item_id ) {
						$this->log->forget( $creator_id, $channel->platform, $order_id, $event->order_item_id );
					}
				}
			}

			$queued = $this->schedule( $creator_id, $order_id, $channel->platform ) || $queued;
		}

		return $queued;
	}

	/**
	 * The job (Action Scheduler): sends the next message of a creator's
	 * order on a platform.
	 *
	 * @param mixed $creator_id Creator.
	 * @param mixed $order_id   Order.
	 * @param mixed $platform   Platform value (Twitch for jobs queued before step 8.2).
	 */
	public function run( mixed $creator_id, mixed $order_id, mixed $platform = 'twitch' ): void {
		$platform = ChatPlatform::parse( $platform );

		if ( ! is_numeric( $creator_id ) || ! is_numeric( $order_id ) || null === $platform ) {
			return;
		}

		try {
			$this->send_next( (int) $creator_id, (int) $order_id, $platform );
		} catch ( DatabaseException | ChatbotException $e ) {
			$this->logger->error(
				'A chat message could not be sent.',
				array(
					'creator_id' => (int) $creator_id,
					'platform'   => $platform->value,
					'reason'     => $e instanceof ChatbotException ? $e->reason() : $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Sends a message right away (test message, admin's message).
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @param string         $message Message.
	 * @return array{result: string, reason: string, retry_after: int|null}
	 * @throws ChatbotException When the platform is not set up or refuses the app.
	 * @throws DatabaseException When stored tokens cannot be read or written.
	 */
	public function send_now( ChatbotChannel $channel, string $message ): array {
		self::require_sendable( $channel, $message );

		return $this->platforms->get( $channel->platform )->send( $channel, $message );
	}

	/**
	 * Requires a channel with the bot and a message.
	 *
	 * @param ChatbotChannel $channel The creator's channel.
	 * @param string         $message Message.
	 * @throws ChatbotException When there is no channel or no message.
	 */
	private static function require_sendable( ChatbotChannel $channel, string $message ): void {
		if ( ! $channel->connected() || '' === trim( $message ) ) {
			throw new ChatbotException( ChatbotException::NOT_CONFIGURED );
		}
	}

	/**
	 * The creator's channels where messages are sent: the platform is set
	 * up, the bot is in the channel and the creator's messages are on.
	 *
	 * @param int $creator_id Creator.
	 * @return list<ChatbotChannel>
	 * @throws DatabaseException When the channels cannot be read.
	 */
	private function active_channels( int $creator_id ): array {
		$active = array();

		foreach ( $this->channels->for_creator( $creator_id ) as $channel ) {
			if ( $channel->connected() && $channel->enabled && $this->platforms->get( $channel->platform )->ready() ) {
				$active[] = $channel;
			}
		}

		return $active;
	}

	/**
	 * Queues a job unless the same one waits.
	 *
	 * @param int          $creator_id Creator.
	 * @param int          $order_id   Order.
	 * @param ChatPlatform $platform   Platform.
	 */
	private function schedule( int $creator_id, int $order_id, ChatPlatform $platform ): bool {
		$args = array( $creator_id, $order_id, $platform->value );

		return ! $this->queue->has_pending( self::HOOK, $args ) && $this->queue->enqueue( self::HOOK, $args );
	}

	/**
	 * Queues the job again from within itself (Action Scheduler counts the
	 * running job as pending, so there is no duplicate check; the message
	 * log keeps a second job from sending anything twice).
	 *
	 * @param int          $creator_id Creator.
	 * @param int          $order_id   Order.
	 * @param ChatPlatform $platform   Platform.
	 * @param int          $delay      Seconds to wait.
	 */
	private function again( int $creator_id, int $order_id, ChatPlatform $platform, int $delay ): void {
		$this->queue->enqueue( self::HOOK, array( $creator_id, $order_id, $platform->value ), $delay );
	}

	/**
	 * Sends the next message that is due.
	 *
	 * @param int          $creator_id Creator.
	 * @param int          $order_id   Order.
	 * @param ChatPlatform $platform   Platform.
	 * @throws DatabaseException When the database cannot be read or written.
	 * @throws ChatbotException When the platform is not set up or refuses the app.
	 */
	private function send_next( int $creator_id, int $order_id, ChatPlatform $platform ): void {
		$channel = $this->channels->find( $creator_id, $platform );
		$adapter = $this->platforms->get( $platform );

		if ( ! $this->availability->sending() || ! $adapter->ready() || ! $channel->connected() || ! $channel->enabled ) {
			return;
		}

		$wait = $adapter->wait_seconds();

		if ( $wait > 0 ) {
			$this->again( $creator_id, $order_id, $platform, $wait );
			return;
		}

		$events = $this->events->for_creator_orders( $creator_id, array( $order_id ) );
		$format = $this->format->get();

		if ( array() === $events ) {
			return;
		}

		if ( 'order' === $format['mode'] ) {
			if ( ! $this->log->sent( $creator_id, $platform, $order_id, 0 ) ) {
				$this->deliver( $channel, $order_id, 0, $this->messages->order( $channel->template_for( 'order' ), $events, $format ) );
			}

			return;
		}

		// Lines that are sent, failed for good or skipped are done; the next one follows.
		$pending = array_values( array_filter( $events, fn( AlertEvent $event ): bool => ! $this->log->finished( $creator_id, $platform, $order_id, $event->order_item_id ) ) );
		$next    = $pending[0] ?? null;

		if ( null === $next ) {
			return;
		}

		// The creator was not live for a line before: the order's other lines are skipped without asking again.
		if ( $this->log->skipped_in_order( $creator_id, $platform, $order_id, 'not_live' ) ) {
			foreach ( $pending as $event ) {
				$this->log->note( $creator_id, $platform, $order_id, $event->order_item_id, ChatMessageRepository::SKIPPED, 'not_live' );
			}

			return;
		}

		$done = $this->deliver( $channel, $order_id, $next->order_item_id, $this->messages->line( $channel->template_for( 'line' ), $next ) );

		// The order's next line, at least a second later (also after a skipped one).
		if ( $done && count( $pending ) > 1 ) {
			$this->again( $creator_id, $order_id, $platform, self::LINE_GAP );
		}
	}

	/**
	 * Sends one message and notes the outcome; queues a retry when it may
	 * succeed later.
	 *
	 * @param ChatbotChannel $channel       The creator's channel.
	 * @param int            $order_id      Order.
	 * @param int            $order_item_id Line; 0 for the whole order.
	 * @param string         $message       Message.
	 * @return bool Whether this message is finished (sent or given up), so the next may follow.
	 * @throws DatabaseException When the log cannot be written.
	 * @throws ChatbotException When the platform is not set up or refuses the app.
	 */
	private function deliver( ChatbotChannel $channel, int $order_id, int $order_item_id, string $message ): bool {
		$creator_id = $channel->creator_id;
		$platform   = $channel->platform;

		if ( '' === $message ) {
			$this->log->note( $creator_id, $platform, $order_id, $order_item_id, ChatMessageRepository::SKIPPED, 'empty' );

			return true;
		}

		$result   = $this->send_now( $channel, $message );
		$attempts = $this->log->attempts( $creator_id, $platform, $order_id, $order_item_id ) + 1;

		if ( 'sent' === $result['result'] ) {
			$this->log->note( $creator_id, $platform, $order_id, $order_item_id, ChatMessageRepository::SENT );

			// The creator is live: the messages that waited for the stream follow.
			if ( ChatPlatform::YouTube === $platform ) {
				$this->flush_waiting( $creator_id, $platform, $order_id );
			}

			return true;
		}

		if ( 'not_live' === $result['result'] ) {
			return $this->wait_for_stream( $channel, $order_id, $order_item_id, $attempts );
		}

		// Not for this creator now (e.g. YouTube's terms are not accepted): not tried again.
		if ( 'skipped' === $result['result'] ) {
			$this->log->note( $creator_id, $platform, $order_id, $order_item_id, ChatMessageRepository::SKIPPED, $result['reason'] );

			return true;
		}

		// Dropped (e.g. the channel's filters) or the channel refused: not tried again.
		$final = in_array( $result['result'], array( 'dropped', 'forbidden', 'unauthorized' ), true ) || $attempts >= self::MAX_ATTEMPTS;
		$this->log->note( $creator_id, $platform, $order_id, $order_item_id, $final ? ChatMessageRepository::FAILED : ChatMessageRepository::WAITING, '' !== $result['reason'] ? $result['reason'] : $result['result'] );

		if ( ! $final ) {
			$this->again( $creator_id, $order_id, $platform, $result['retry_after'] ?? self::RETRY_DELAY );
		}

		return $final;
	}

	/**
	 * Queues the creator's messages that wait for a livestream right away
	 * (the creator was found live: a message was sent, or a test message).
	 * At most once per ten minutes per creator and platform.
	 *
	 * @param int          $creator_id Creator.
	 * @param ChatPlatform $platform   Platform.
	 * @param int          $except     Order already being sent (0 for none).
	 * @throws DatabaseException When the log cannot be read.
	 */
	public function flush_waiting( int $creator_id, ChatPlatform $platform, int $except = 0 ): void {
		$key = self::FLUSHED_PREFIX . $platform->value . '_' . $creator_id;

		if ( false !== get_transient( $key ) ) {
			return;
		}

		$orders = array_values( array_diff( $this->log->waiting_orders( $creator_id, $platform, 'not_live' ), array( $except ) ) );

		if ( array() === $orders ) {
			return;
		}

		set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );

		foreach ( $orders as $order_id ) {
			$this->again( $creator_id, $order_id, $platform, self::LINE_GAP );
		}
	}

	/**
	 * A message whose creator is not live is checked once more after
	 * LIVE_RETRY; when the creator is still not live, it is skipped (ADR-070
	 * pkt. 5, statusnote 2026-10-21: as few API calls as possible).
	 *
	 * @param ChatbotChannel $channel       The creator's channel.
	 * @param int            $order_id      Order.
	 * @param int            $order_item_id Line; 0 for the whole order.
	 * @param int            $attempts      Attempts including this one.
	 * @return bool Whether this message is finished (skipped).
	 * @throws DatabaseException When the log cannot be written.
	 */
	private function wait_for_stream( ChatbotChannel $channel, int $order_id, int $order_item_id, int $attempts ): bool {
		if ( $attempts > 1 ) {
			$this->log->note( $channel->creator_id, $channel->platform, $order_id, $order_item_id, ChatMessageRepository::SKIPPED, 'not_live' );

			return true;
		}

		$this->log->note( $channel->creator_id, $channel->platform, $order_id, $order_item_id, ChatMessageRepository::WAITING, 'not_live' );
		$this->again( $channel->creator_id, $order_id, $channel->platform, self::LIVE_RETRY );

		return false;
	}
}
