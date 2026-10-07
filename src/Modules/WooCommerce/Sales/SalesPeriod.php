<?php
/**
 * One period of a sales series.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * A period of a series: the half-open UTC interval `[start, end)` that the
 * ledger is queried with, and the local dates it covers (both inclusive). The
 * first and last period of a week or month series can be partial.
 */
final readonly class SalesPeriod {

	/**
	 * Constructor.
	 *
	 * @param int               $index     Position in the series, from 0.
	 * @param DateTimeImmutable $start     Start (UTC, inclusive).
	 * @param DateTimeImmutable $end       End (UTC, exclusive).
	 * @param string            $first_day First local date covered, `Y-m-d`.
	 * @param string            $last_day  Last local date covered, `Y-m-d` (inclusive).
	 */
	public function __construct(
		public int $index,
		public DateTimeImmutable $start,
		public DateTimeImmutable $end,
		public string $first_day,
		public string $last_day
	) {
	}
}
