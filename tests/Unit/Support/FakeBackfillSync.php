<?php
/**
 * Backfill port of the synchronization for tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillOutcome;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillStatus;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillSync;

/**
 * Returns a configured outcome per order (default: registered with one sale)
 * and records which orders were registered or previewed.
 */
final class FakeBackfillSync implements SalesBackfillSync {

	/**
	 * Outcome per order id.
	 *
	 * @var array<int, SalesBackfillOutcome>
	 */
	public array $outcomes = array();

	/**
	 * Orders passed to backfill(), in order.
	 *
	 * @var list<int>
	 */
	public array $registered = array();

	/**
	 * Orders passed to preview(), in order.
	 *
	 * @var list<int>
	 */
	public array $previewed = array();

	/**
	 * Registers the order.
	 *
	 * @param int $order_id Order id.
	 */
	public function backfill( int $order_id ): SalesBackfillOutcome {
		$this->registered[] = $order_id;

		return $this->outcome( $order_id );
	}

	/**
	 * Previews the order.
	 *
	 * @param int $order_id Order id.
	 */
	public function preview( int $order_id ): SalesBackfillOutcome {
		$this->previewed[] = $order_id;

		return $this->outcome( $order_id );
	}

	/**
	 * The configured outcome.
	 *
	 * @param int $order_id Order id.
	 */
	private function outcome( int $order_id ): SalesBackfillOutcome {
		return $this->outcomes[ $order_id ] ?? new SalesBackfillOutcome(
			SalesBackfillStatus::Registered,
			array(
				'sale'       => 1,
				'refund'     => 0,
				'reversal'   => 0,
				'correction' => 0,
			)
		);
	}
}
