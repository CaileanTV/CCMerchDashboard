<?php
/**
 * Records what the sales ledger hooks ask for.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use MerchBoard\Modules\WooCommerce\Sales\SalesSync;

/**
 * Keeps "sync:<order>[:-<deleted refund>]" and "deleting:<order>" in call order.
 */
final class RecordingSalesSync implements SalesSync {

	/**
	 * Calls.
	 *
	 * @var list<string>
	 */
	public array $calls = array();

	/**
	 * Records a synchronization.
	 *
	 * @param int      $order_id          Order id.
	 * @param int|null $deleted_refund_id Deleted refund.
	 */
	public function sync( int $order_id, ?int $deleted_refund_id = null ): void {
		$this->calls[] = 'sync:' . $order_id . ( null === $deleted_refund_id ? '' : ':-' . $deleted_refund_id );
	}

	/**
	 * Records a deletion.
	 *
	 * @param int $order_id Order id.
	 */
	public function order_deleting( int $order_id ): void {
		$this->calls[] = 'deleting:' . $order_id;
	}
}
