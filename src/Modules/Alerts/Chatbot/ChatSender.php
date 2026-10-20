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
 * The chat messages of the alerts (ADR-066 pkt. 3 and status note
 * 2026-10-20), sent in the background through Action Scheduler, never in a
 * customer's or creator's request.
 *
 * - **When:** a job per creator and order, queued when the order's alerts
 *   are recorded (and again for a resend). The job sends one message per
 *   line or one per order (ChatbotFormat) to the channel the creator added
 *   the bot to, while alerts are switched on, the bot is connected and the
 *   creator's messages are on.
 * - **Never twice:** the message log (ChatMessageRepository) is checked
 *   before sending; a resend forgets the entry first.
 * - **Rate limit:** after each message the job notes Twitch's `Ratelimit-*`
 *   values; when the limit is used up, or Twitch answers 429, the job waits
 *   until Twitch's reset. Lines of one order are at least a second apart
 *   (Twitch's limit per channel). A message is tried at most MAX_ATTEMPTS
 *   times.
 * - **Logs** only reason codes, never tokens or the message.
 */
final class ChatSender {

	/**
	 * Action Scheduler hook; arguments: creator id, order id.
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
	 * Constructor.
	 *
	 * @param TwitchAccount            $account      App and bot.
	 * @param TwitchApi                $api          Twitch.
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
		private TwitchAccount $account,
		private TwitchApi $api,
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
	 * alerts in it (`woocommerce_order_status_*`, after the alerts are
	 * recorded). Cheap when the chatbot is not set up.
	 *
	 * @param mixed $order_id Order id from WooCommerce.
	 */
	public function order_paid( mixed $order_id ): void {
		if ( ! is_numeric( $order_id ) || (int) $order_id <= 0 || ! $this->account->ready() || ! $this->availability->sending() ) {
			return;
		}

		try {
			foreach ( $this->events->creators_of_order( (int) $order_id ) as $creator_id ) {
				$this->schedule( $creator_id, (int) $order_id );
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Chat messages could not be queued.', array( 'reason' => $e->getMessage() ) );
		}
	}

	/**
	 * Sends the messages of an order again (from the history): the log
	 * entries are forgotten and a job is queued.
	 *
	 * @param int $creator_id    Creator.
	 * @param int $order_id      Order.
	 * @param int $order_item_id One line; 0 for all of the order.
	 * @return bool Whether a job was queued (false while the chatbot is off).
	 * @throws DatabaseException When the log cannot be updated.
	 */
	public function resend( int $creator_id, int $order_id, int $order_item_id = 0 ): bool {
		$channel = $this->channels->find( $creator_id );

		if ( ! $this->account->ready() || ! $channel->connected() || ! $channel->enabled ) {
			return false;
		}

		if ( 'order' === $this->format->get()['mode'] ) {
			$this->log->forget( $creator_id, $order_id, 0 );
		} else {
			foreach ( $this->events->for_creator_orders( $creator_id, array( $order_id ) ) as $event ) {
				if ( 0 === $order_item_id || $event->order_item_id === $order_item_id ) {
					$this->log->forget( $creator_id, $order_id, $event->order_item_id );
				}
			}
		}

		return $this->schedule( $creator_id, $order_id );
	}

	/**
	 * The job (Action Scheduler): sends the next message of a creator's order.
	 *
	 * @param mixed $creator_id Creator.
	 * @param mixed $order_id   Order.
	 */
	public function run( mixed $creator_id, mixed $order_id ): void {
		if ( ! is_numeric( $creator_id ) || ! is_numeric( $order_id ) ) {
			return;
		}

		try {
			$this->send_next( (int) $creator_id, (int) $order_id );
		} catch ( DatabaseException | TwitchException $e ) {
			$this->logger->error(
				'A chat message could not be sent.',
				array(
					'creator_id' => (int) $creator_id,
					'reason'     => $e instanceof TwitchException ? $e->reason() : $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Sends a message right away (test message, admin's message); the rate
	 * limit is noted.
	 *
	 * @param string $broadcaster_id Channel.
	 * @param string $message        Message.
	 * @return array{result: string, reason: string, limit: int|null, remaining: int|null, reset: int|null}
	 * @throws TwitchException When the chatbot is not set up or Twitch refuses the app.
	 */
	public function send_now( string $broadcaster_id, string $message ): array {
		$bot = $this->account->bot();

		if ( null === $bot || '' === trim( $message ) ) {
			throw new TwitchException( TwitchException::NOT_CONFIGURED );
		}

		$client = $this->account->client_id();
		$result = $this->api->send_message( $client, $this->account->app_token(), $broadcaster_id, $bot['id'], $message );

		// A refused app token is renewed once.
		if ( 'unauthorized' === $result['result'] ) {
			$result = $this->api->send_message( $client, $this->account->app_token( true ), $broadcaster_id, $bot['id'], $message );
		}

		$this->account->note_rate_limit( $result['limit'], $result['remaining'], $result['reset'] );

		return $result;
	}

	/**
	 * Queues a job unless the same one waits.
	 *
	 * @param int $creator_id Creator.
	 * @param int $order_id   Order.
	 */
	private function schedule( int $creator_id, int $order_id ): bool {
		$args = array( $creator_id, $order_id );

		return ! $this->queue->has_pending( self::HOOK, $args ) && $this->queue->enqueue( self::HOOK, $args );
	}

	/**
	 * Queues the job again from within itself (Action Scheduler counts the
	 * running job as pending, so there is no duplicate check; the message
	 * log keeps a second job from sending anything twice).
	 *
	 * @param int $creator_id Creator.
	 * @param int $order_id   Order.
	 * @param int $delay      Seconds to wait.
	 */
	private function again( int $creator_id, int $order_id, int $delay ): void {
		$this->queue->enqueue( self::HOOK, array( $creator_id, $order_id ), $delay );
	}

	/**
	 * Sends the next message that is due.
	 *
	 * @param int $creator_id Creator.
	 * @param int $order_id   Order.
	 * @throws DatabaseException When the database cannot be read or written.
	 * @throws TwitchException When the app is not set up or Twitch refuses it.
	 */
	private function send_next( int $creator_id, int $order_id ): void {
		$channel = $this->channels->find( $creator_id );

		if ( ! $this->availability->sending() || ! $this->account->ready() || ! $channel->connected() || ! $channel->enabled ) {
			return;
		}

		$wait = $this->account->wait_seconds();

		if ( $wait > 0 ) {
			$this->again( $creator_id, $order_id, $wait );
			return;
		}

		$events = $this->events->for_creator_orders( $creator_id, array( $order_id ) );
		$format = $this->format->get();

		if ( array() === $events ) {
			return;
		}

		if ( 'order' === $format['mode'] ) {
			if ( ! $this->log->sent( $creator_id, $order_id, 0 ) ) {
				$this->deliver( $channel, $order_id, 0, $this->messages->order( $channel->template_for( 'order' ), $events, $format ) );
			}

			return;
		}

		$pending = array_values( array_filter( $events, fn( AlertEvent $event ): bool => ! $this->log->sent( $creator_id, $order_id, $event->order_item_id ) ) );
		$next    = $pending[0] ?? null;

		if ( null === $next ) {
			return;
		}

		$done = $this->deliver( $channel, $order_id, $next->order_item_id, $this->messages->line( $channel->template_for( 'line' ), $next ) );

		// The order's next line, at least a second later (also after a skipped one).
		if ( $done && count( $pending ) > 1 ) {
			$this->again( $creator_id, $order_id, self::LINE_GAP );
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
	 * @throws TwitchException When the app is not set up or Twitch refuses it.
	 */
	private function deliver( ChatbotChannel $channel, int $order_id, int $order_item_id, string $message ): bool {
		$creator_id = $channel->creator_id;

		if ( '' === $message ) {
			$this->log->note( $creator_id, $order_id, $order_item_id, ChatMessageRepository::SKIPPED, 'empty' );

			return true;
		}

		$result   = $this->send_now( $channel->broadcaster_id, $message );
		$attempts = $this->log->attempts( $creator_id, $order_id, $order_item_id ) + 1;

		if ( 'sent' === $result['result'] ) {
			$this->log->note( $creator_id, $order_id, $order_item_id, ChatMessageRepository::SENT );

			return true;
		}

		// Twitch dropped it (e.g. the channel's filters) or refused the channel: not tried again.
		$final = in_array( $result['result'], array( 'dropped', 'forbidden', 'unauthorized' ), true ) || $attempts >= self::MAX_ATTEMPTS;
		$this->log->note( $creator_id, $order_id, $order_item_id, $final ? ChatMessageRepository::FAILED : ChatMessageRepository::WAITING, '' !== $result['reason'] ? $result['reason'] : $result['result'] );

		if ( ! $final ) {
			$delay = 'rate_limited' === $result['result'] && null !== $result['reset'] ? max( 1, $result['reset'] - time() + 1 ) : self::RETRY_DELAY;
			$this->again( $creator_id, $order_id, $delay );
		}

		return $final;
	}
}
