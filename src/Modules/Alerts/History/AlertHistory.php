<?php
/**
 * The creator's alert history: resend and stop.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\History;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Http\Pagination;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\Api\AlertApiFeed;
use MerchBoard\Modules\Alerts\Chatbot\ChatSender;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlay;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\OverlayFeed;
use MerchBoard\Modules\Alerts\Overlay\OverlayTestQueue;
use MerchBoard\Modules\Alerts\Webhooks\WebhookDispatcher;

defined( 'ABSPATH' ) || exit;

/**
 * The history of a creator's alerts (phase 25, step 7), as in MerchAlert's
 * creator portal. Callers pass the creator that CreatorContext resolved;
 * only the creator's own alerts are ever listed or resent.
 *
 * - **History:** orders with alerts, newest first, each with its alerts.
 *   Only what an alert shows: the order number, the products, variations,
 *   quantities, the customer's voluntary username and the time.
 * - **Resend:** an alert or all alerts of an order are shown again in the
 *   overlay, through the queue that test alerts use (OverlayTestQueue);
 *   nothing is stored again. The chat message is sent again too, when the
 *   creator's chatbot is on (step 25.8), and the creator's bots get it
 *   through the alert API and the webhooks, marked as resent (steps
 *   25.12.1 and 25.12.2).
 * - **Stop all alerts:** the waiting resends and tests are dropped and the
 *   overlay gets a new version, so the open overlay page reloads at once:
 *   the alert it shows and its queue are gone, and it starts again from
 *   now, so alerts it had not fetched yet are skipped. New purchases after
 *   that are shown as usual.
 *
 * Not audited (like test alerts): nothing changes but what the overlay shows.
 */
final class AlertHistory {

	/**
	 * Orders per page (MerchAlert's history page size).
	 */
	public const PER_PAGE = 10;

	/**
	 * Constructor.
	 *
	 * @param AlertEventRepository   $events   Alerts.
	 * @param AlertOverlayRepository $overlays Overlays.
	 * @param OverlayFeed            $feed     Alert rendering.
	 * @param OverlayTestQueue       $queue    Queue of the overlay's resends and tests.
	 * @param ChatSender|null        $chat     Chat messages; null for none.
	 * @param AlertApiFeed|null      $api      The creator's bots (ADR-073); null for none.
	 * @param WebhookDispatcher|null $webhooks The creator's webhooks (step 25.12.2); null for none.
	 */
	public function __construct( private AlertEventRepository $events, private AlertOverlayRepository $overlays, private OverlayFeed $feed, private OverlayTestQueue $queue, private ?ChatSender $chat = null, private ?AlertApiFeed $api = null, private ?WebhookDispatcher $webhooks = null ) {
	}

	/**
	 * The number of the creator's orders with alerts.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function count( int $creator_id ): int {
		return $this->events->order_count( $creator_id );
	}

	/**
	 * A page of orders with their alerts, the newest first.
	 *
	 * @param int        $creator_id Creator.
	 * @param Pagination $pagination Page.
	 * @return list<array{order_id: int, order_number: string, username: string, created_at: string, alerts: list<array{id: int, product: string, variation: string, quantity: int, created_at: string}>}>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function page( int $creator_id, Pagination $pagination ): array {
		$order_ids = $this->events->order_page( $creator_id, $pagination );
		$by_order  = array();

		foreach ( $this->events->for_creator_orders( $creator_id, $order_ids ) as $event ) {
			$by_order[ $event->order_id ][] = $event;
		}

		$orders = array();

		foreach ( $order_ids as $order_id ) {
			$events = $by_order[ $order_id ] ?? array();
			$first  = $events[0] ?? null;

			if ( null === $first ) {
				continue;
			}

			$orders[] = array(
				'order_id'     => $order_id,
				'order_number' => $first->order_number,
				'username'     => $first->customer_username,
				'created_at'   => self::time( $first ),
				'alerts'       => array_map(
					static fn( AlertEvent $event ): array => array(
						'id'         => $event->id,
						'product'    => $event->product_name,
						'variation'  => $event->variation,
						'quantity'   => $event->quantity,
						'created_at' => self::time( $event ),
					),
					$events
				),
			);
		}

		return $orders;
	}

	/**
	 * Shows one of the creator's alerts again.
	 *
	 * @param int $creator_id Creator.
	 * @param int $alert_id   Alert id.
	 * @return int|null Alerts sent (0 when it is not the creator's alert); null before the overlay is set up.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function resend_alert( int $creator_id, int $alert_id ): ?int {
		$event = $this->events->find_for_creator( $creator_id, $alert_id );
		$sent  = $this->resend( $creator_id, null === $event ? array() : array( $event ) );

		if ( null !== $event && null !== $sent ) {
			$this->chat?->resend( $creator_id, $event->order_id, $event->order_item_id );
		}

		return $sent;
	}

	/**
	 * Shows all of the creator's alerts of an order again.
	 *
	 * @param int $creator_id Creator.
	 * @param int $order_id   Order.
	 * @return int|null Alerts sent (0 when the order has none of the creator's); null before the overlay is set up.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function resend_order( int $creator_id, int $order_id ): ?int {
		$sent = $this->resend( $creator_id, $this->events->for_creator_orders( $creator_id, array( $order_id ) ) );

		if ( null !== $sent && $sent > 0 ) {
			$this->chat?->resend( $creator_id, $order_id );
		}

		return $sent;
	}

	/**
	 * Stops all alerts at once.
	 *
	 * @param int $creator_id Creator.
	 * @return bool False before the overlay is set up.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function stop( int $creator_id ): bool {
		$overlay = $this->overlays->find_for_creator( $creator_id );

		if ( null === $overlay ) {
			return false;
		}

		$this->queue->clear( $overlay->id );
		$this->overlays->touch( $overlay->id );

		return true;
	}

	/**
	 * Queues alerts for the creator's overlay.
	 *
	 * @phpstan-param list<AlertEvent> $events
	 *
	 * @param int          $creator_id Creator.
	 * @param AlertEvent[] $events     The creator's alerts.
	 * @return int|null Alerts sent; null before the overlay is set up.
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function resend( int $creator_id, array $events ): ?int {
		$this->api?->resend( $creator_id, $events );
		$this->webhooks?->resend( $creator_id, $events );

		$overlay = $this->overlays->find_for_creator( $creator_id );

		if ( null === $overlay ) {
			return null;
		}

		foreach ( $events as $event ) {
			$this->queue->push( $overlay->id, $this->render( $overlay, $event ) );
		}

		return count( $events );
	}

	/**
	 * An alert as the overlay shows it, as a queued alert (id 0, like tests).
	 *
	 * @param AlertOverlay $overlay Overlay.
	 * @param AlertEvent   $event   Alert.
	 * @return array{id: int, image: string, text: string, secondary: string, sound: string}
	 */
	private function render( AlertOverlay $overlay, AlertEvent $event ): array {
		return array_merge( $this->feed->render( $overlay, $event ), array( 'id' => 0 ) );
	}

	/**
	 * When an alert was recorded, as ISO 8601 in UTC.
	 *
	 * @param AlertEvent $event Alert.
	 */
	private static function time( AlertEvent $event ): string {
		return $event->created_at->format( 'Y-m-d\TH:i:s\Z' );
	}
}
