<?php
/**
 * Routes a paid order to the creators' alerts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ProductStore;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * One alert per product line and creator when an order is paid (ADR-065
 * pkt. 2). The creator of a line is the creator the product is attributed
 * to (`ProductCreatorLink::creator_id()`), the same attribution as the sales
 * ledger: mapping a category to a creator links its products, so the
 * category turns the alerts on, and a manual link does too. Only active
 * creators get alerts. Nothing happens while alerts are switched off or
 * MerchAlert is active (AlertsAvailability).
 *
 * System work in the customer's or the shop manager's request: no
 * capability check. It never throws, so no order status change is
 * interrupted; a failure is logged without personal data and the order
 * simply has no alert.
 */
final class AlertRouter {

	/**
	 * The current time.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @param PaidOrderReader                     $orders       The paid order.
	 * @param ProductCreatorLink                  $links        Product attribution.
	 * @param ProductStore                        $products     The product's categories.
	 * @param CreatorRepository                   $creators     Creator status.
	 * @param AlertEventRepository                $events       Alert storage.
	 * @param AlertsAvailability                  $availability Kill switch and MerchAlert guard.
	 * @param Logger                              $logger       Technical log.
	 * @param (Closure(): DateTimeImmutable)|null $clock        Current time; defaults to now (UTC).
	 */
	public function __construct(
		private PaidOrderReader $orders,
		private ProductCreatorLink $links,
		private ProductStore $products,
		private CreatorRepository $creators,
		private AlertEventRepository $events,
		private AlertsAvailability $availability,
		private Logger $logger,
		?Closure $clock = null
	) {
		$this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Records the alerts of a paid order.
	 *
	 * @param int $order_id Order id.
	 * @return int The number of new alerts.
	 */
	public function order_paid( int $order_id ): int {
		if ( $order_id < 1 || ! $this->availability->sending() ) {
			return 0;
		}

		try {
			$order = $this->orders->read( $order_id );

			if ( null === $order ) {
				return 0;
			}

			$now      = ( $this->clock )();
			$active   = array();
			$recorded = 0;

			foreach ( $order->lines as $line ) {
				$creator_id = $this->links->creator_id( $line->product_id );

				if ( null === $creator_id ) {
					continue;
				}

				if ( ! array_key_exists( $creator_id, $active ) ) {
					$active[ $creator_id ] = true === $this->creators->find( $creator_id )?->is_active();
				}

				if ( ! $active[ $creator_id ] ) {
					continue;
				}

				$recorded += $this->events->insert(
					new AlertEvent(
						$creator_id,
						$order->order_id,
						$order->order_number,
						$line->order_item_id,
						$line->product_id,
						$line->variation_id,
						$line->product_name,
						$line->variation,
						$line->quantity,
						$line->image_id,
						$this->products->find( $line->product_id )->category_ids ?? array(),
						$order->first_name,
						$order->username,
						$now
					)
				) ? 1 : 0;
			}

			return $recorded;
		} catch ( Throwable $e ) {
			$this->logger->error(
				'The alerts of a paid order could not be recorded.',
				array(
					'order_id' => $order_id,
					'error'    => $e::class,
				)
			);

			return 0;
		}
	}
}
