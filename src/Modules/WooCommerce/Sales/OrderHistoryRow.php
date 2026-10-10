<?php
/**
 * One order in the admin order history.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use MerchBoard\Modules\WooCommerce\Pricing\ProfitSummary;

defined( 'ABSPATH' ) || exit;

/**
 * A unique order of the sales ledger with the totals of its (shown) ledger
 * rows (ADR-051): one row per order, never per event. No customer data and no
 * item names.
 */
final readonly class OrderHistoryRow {

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<int> $creator_ids
	 *
	 * @param int               $order_id     WooCommerce order id (as stored in the ledger).
	 * @param DateTimeImmutable $first_at     Earliest `occurred_at` (UTC).
	 * @param DateTimeImmutable $last_at      Latest `occurred_at` (UTC).
	 * @param string            $currency     Currency of the rows.
	 * @param int[]             $creator_ids  Creators of the rows.
	 * @param int               $events       Number of ledger rows.
	 * @param int               $lines        Number of distinct order lines.
	 * @param int               $sales        Number of `sale` rows.
	 * @param string            $net_amount   Sum of `net_amount`, decimal string.
	 * @param int               $taxed_rows   Rows with a stored tax.
	 * @param ProfitSummary     $profit       Known and unknown rows (amount only when complete).
	 */
	public function __construct(
		public int $order_id,
		public DateTimeImmutable $first_at,
		public DateTimeImmutable $last_at,
		public string $currency,
		public array $creator_ids,
		public int $events,
		public int $lines,
		public int $sales,
		public string $net_amount,
		public int $taxed_rows,
		public ProfitSummary $profit
	) {
	}
}
