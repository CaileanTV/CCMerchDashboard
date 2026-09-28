<?php
/**
 * Outcome of a committed ledger write.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * What a committed SalesRepository::write() stored. A row or cycle event whose
 * identity (`event_key`, or order/cycle/event) was already stored is skipped
 * as recorded, not as an error (idempotency, ADR-038 pkt. 17).
 */
final readonly class SalesWriteResult {

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<string> $inserted_entries
	 * @phpstan-param list<string> $skipped_entries
	 *
	 * @param string[] $inserted_entries      Event keys of the rows written.
	 * @param string[] $skipped_entries       Event keys that were already stored.
	 * @param int      $inserted_cycle_events Cycle events written.
	 * @param int      $skipped_cycle_events  Cycle events that were already stored.
	 */
	public function __construct(
		public array $inserted_entries,
		public array $skipped_entries,
		public int $inserted_cycle_events,
		public int $skipped_cycle_events
	) {
	}
}
