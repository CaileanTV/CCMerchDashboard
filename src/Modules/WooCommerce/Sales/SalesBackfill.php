<?php
/**
 * Pages of the sales ledger backfill.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * The backfill of ADR-037 pkt. 6 with the decisions of ADR-038's status note
 * "ROADMAP trin 7" (D1-D3):
 *
 * - Only orders without ledger history are registered; an order with history
 *   is never touched, a historical order that does not count now gets no
 *   rows, and earlier status changes are not reconstructed. No recalculation
 *   of existing cycles and no reconciliation.
 * - Orders in the counted statuses, oldest id first, page by page through
 *   WooCommerce CRUD (SalesBackfillOrders); the synchronization decides per
 *   order, so a status that changed after the query is respected.
 * - Each order goes through SalesSynchronizer's backfill entry: the same
 *   lock, transaction, planner, failure marker and log as a live sync.
 *   A failed order is counted and the page continues; nothing is retried.
 * - The dry run uses the preview: no rows, no lock, no marker, no audit.
 */
final class SalesBackfill {

	/**
	 * Constructor.
	 *
	 * @param SalesBackfillSync   $sync   Per-order registration and preview.
	 * @param SalesBackfillOrders $orders Order pages.
	 */
	public function __construct( private SalesBackfillSync $sync, private SalesBackfillOrders $orders ) {
	}

	/**
	 * Processes the run's next page and returns the run after it. The cursor
	 * advances only when the whole page was processed; if reading the page
	 * fails, the exception propagates and the run is unchanged.
	 *
	 * @param SalesBackfillRun $run Run before the page.
	 * @throws \RuntimeException When the page cannot be read.
	 */
	public function run_page( SalesBackfillRun $run ): SalesBackfillRun {
		$ids   = $this->orders->page( $run->statuses, $run->offset, $run->batch_size );
		$tally = $run->tally;

		foreach ( $ids as $order_id ) {
			$tally = $tally->with( $this->sync->backfill( $order_id ) );
		}

		return $run->advanced( count( $ids ), $tally );
	}

	/**
	 * The dry run over every page, inline: counts only, writes nothing.
	 *
	 * @phpstan-param list<string> $statuses
	 * @phpstan-param (Closure(int): void)|null $progress
	 *
	 * @param string[]     $statuses   Counted statuses.
	 * @param int          $batch_size Orders per page.
	 * @param Closure|null $progress   Receives the number of orders scanned after each page.
	 * @throws \RuntimeException When a page cannot be read.
	 */
	public function dry_run( array $statuses, int $batch_size, ?Closure $progress = null ): SalesBackfillTally {
		$batch_size = SalesBackfillRun::batch_size( $batch_size );
		$tally      = SalesBackfillTally::empty();
		$offset     = 0;

		do {
			$ids = $this->orders->page( $statuses, $offset, $batch_size );

			foreach ( $ids as $order_id ) {
				$tally = $tally->with( $this->sync->preview( $order_id ) );
			}

			$page    = count( $ids );
			$offset += $page;

			if ( null !== $progress ) {
				$progress( $offset );
			}
		} while ( $page === $batch_size );

		return $tally;
	}
}
