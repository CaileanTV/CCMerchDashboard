<?php
/**
 * Sales statistics of a creator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * The read model of ADR-040 for `/me/sales`, the Overview and the admin
 * Sales section. Every method reads the creator's ledger rows with
 * `occurred_at` in the half-open UTC interval `[from, to)`; the caller turns
 * local dates into that interval and into periods (SalesPeriods). Results are
 * always per currency; nothing is summed across currencies. The creator id
 * comes from the caller (CreatorContext or the admin's creator), never from a
 * request.
 */
interface SalesStatistics {

	/**
	 * Totals per currency, sorted by currency.
	 *
	 * @phpstan-return list<SalesTotals>
	 *
	 * @param int               $creator_id Creator id.
	 * @param DateTimeImmutable $from       Start (UTC, inclusive).
	 * @param DateTimeImmutable $to         End (UTC, exclusive).
	 * @return SalesTotals[]
	 * @throws DatabaseException When the query fails.
	 */
	public function totals( int $creator_id, DateTimeImmutable $from, DateTimeImmutable $to ): array;

	/**
	 * Sums per period and currency, only for periods with rows, sorted by
	 * period and currency.
	 *
	 * @phpstan-param list<SalesPeriod> $periods
	 * @phpstan-return list<SalesSeriesPoint>
	 *
	 * @param int           $creator_id Creator id.
	 * @param SalesPeriod[] $periods    Contiguous periods, in order (SalesPeriods::between()).
	 * @return SalesSeriesPoint[]
	 * @throws DatabaseException When the query fails.
	 */
	public function series( int $creator_id, array $periods ): array;

	/**
	 * Sums per parent product and currency, sorted by currency, then net
	 * sales descending, then product id.
	 *
	 * @phpstan-return list<SalesProductTotals>
	 *
	 * @param int               $creator_id Creator id.
	 * @param DateTimeImmutable $from       Start (UTC, inclusive).
	 * @param DateTimeImmutable $to         End (UTC, exclusive).
	 * @return SalesProductTotals[]
	 * @throws DatabaseException When the query fails.
	 */
	public function products( int $creator_id, DateTimeImmutable $from, DateTimeImmutable $to ): array;
}
