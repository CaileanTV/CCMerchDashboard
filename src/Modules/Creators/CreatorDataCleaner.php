<?php
/**
 * A module's part of deleting a creator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * Removes one module's data of a creator that is being deleted (phase 27,
 * step 27.3, ADR-076): product links, alerts, news reads and so on. Never
 * financial history (sales ledger, payouts, settlement documents), which
 * CreatorDeletion keeps. Modules add their cleaner with the filter
 * `merchboard_creator_data_cleaners`; CreatorDeletion runs them in that
 * order while the creator is inactive.
 *
 * A cleaner must be idempotent: a deletion that stopped half-way is run
 * again from the start.
 */
interface CreatorDataCleaner {

	/**
	 * A short key for the audit record's counts (e.g. "woocommerce").
	 */
	public function key(): string;

	/**
	 * Removes the module's data of the creator.
	 *
	 * @param Creator $creator The creator (inactive).
	 * @return array<string, int> What was removed, for the audit record (counts only).
	 * @throws DatabaseException When it cannot be removed (the deletion stops; nothing after it runs).
	 */
	public function remove( Creator $creator ): array;
}
