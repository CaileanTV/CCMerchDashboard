<?php
/**
 * In-memory orders for the sales synchronization tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Support;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatuses;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\OrderSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\OrderSnapshotReader;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use Throwable;

/**
 * Stands in for WooCommerce (ADR-038, Q4: the integration suite has no
 * WooCommerce): orders with a status, lines and refunds; `trash` and unknown
 * ids are not synchronizable, like the real reader.
 */
final class FakeOrderReader implements OrderSnapshotReader {

	/**
	 * Orders by id.
	 *
	 * @var array<int, array{status: string, currency: string, lines: list<OrderLineSnapshot>, refunds: list<RefundSnapshot>}>
	 */
	public array $orders = array();

	/**
	 * Number of read() calls per order.
	 *
	 * @var array<int, int>
	 */
	public array $reads = array();

	/**
	 * Called on every read() with the order id (e.g. to provoke failures).
	 *
	 * @var (Closure(int): void)|null
	 */
	public ?Closure $on_read = null;

	/**
	 * Thrown by read() when set.
	 *
	 * @var Throwable|null
	 */
	public ?Throwable $fail = null;

	/**
	 * Whether the order counts; null when not synchronizable.
	 *
	 * @param int $order_id Order id.
	 */
	public function counts( int $order_id ): ?bool {
		$order = $this->orders[ $order_id ] ?? null;

		return null === $order || 'trash' === $order['status'] ? null : ( new CountedOrderStatuses() )->counts( $order['status'] );
	}

	/**
	 * The order as a snapshot.
	 *
	 * @param int      $order_id          Order id.
	 * @param int|null $deleted_refund_id Refund known to be deleted.
	 * @throws Throwable The configured failure.
	 */
	public function read( int $order_id, ?int $deleted_refund_id = null ): ?OrderSnapshot {
		$counts = $this->counts( $order_id );

		if ( null === $counts ) {
			return null;
		}

		$this->reads[ $order_id ] = ( $this->reads[ $order_id ] ?? 0 ) + 1;

		if ( null !== $this->on_read ) {
			( $this->on_read )( $order_id );
		}

		if ( null !== $this->fail ) {
			throw $this->fail;
		}

		$order = $this->orders[ $order_id ];
		$utc   = new DateTimeZone( 'UTC' );

		return new OrderSnapshot(
			$order_id,
			true,
			$counts,
			$order['currency'],
			new DateTimeImmutable( '2026-09-01 10:00:00', $utc ),
			new DateTimeImmutable( '2026-09-01 10:05:00', $utc ),
			$order['lines'],
			$order['refunds'],
			$deleted_refund_id
		);
	}
}
