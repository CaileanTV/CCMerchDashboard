<?php
/**
 * The synchronization's backfill entry.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * Registration of one order without ledger history, and its read-only dry
 * run (ADR-038, status note "ROADMAP trin 7", D4). Implemented by
 * SalesSynchronizer next to, not as part of, the live SalesSync contract.
 */
interface SalesBackfillSync {

	/**
	 * Registers the order if it has no ledger history; never throws.
	 *
	 * @param int $order_id Order id.
	 */
	public function backfill( int $order_id ): SalesBackfillOutcome;

	/**
	 * What backfill() would do, without lock, rows, marker or log; never throws.
	 *
	 * @param int $order_id Order id.
	 */
	public function preview( int $order_id ): SalesBackfillOutcome;
}
