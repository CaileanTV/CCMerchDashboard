<?php
/**
 * Aggregated earned payout from the sales ledger.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's earned payout per currency (ADR-054): the sum of
 * RealizedPayout (ADR-049) over the ledger rows that are released, in one
 * query on Merch Dashboard's own tables, with LedgerCreatorProfits' row
 * conditions and profit formula (ADR-050), so profit and payout cannot drift
 * apart.
 *
 * - Settlement start (D1): when a start is given, a row belongs to the window
 *   when the sale row of its order line and cycle (`s:<item>:<cycle>`)
 *   occurred on or after the start, so a sale before the start and its
 *   refunds, reversals and corrections are left out together. A row whose
 *   sale row is missing cannot be placed and is unknown. Without a start the
 *   whole history counts and no sale row is needed.
 * - Holding period (D2): a row with a negative payout counts from its own
 *   `occurred_at`; a row with a payout ≥ 0 counts from `occurred_at` plus the
 *   holding period, so a known refund can never leave too much available.
 * - Unknown rows (RealizedPayout gives null, or no sale row with a start)
 *   are counted, never summed and never skipped, whether or not they are
 *   held: one makes the currency's amount unknown (ProfitSummary::amount_units()
 *   is null), so nothing can be requested against it.
 *
 * earned() and unsettled_rows() select rows with the same private helpers
 * (window(), known() and RELEASED), so the balance and a settlement document
 * can never apply different rules (K6.1).
 *
 * A row's payout is its profit, whether or not the creator is VAT registered
 * (ADR-063 removed ADR-049's 20 % deduction), the same as
 * CreatorShare::payout(). A row that a settlement document has settled
 * counts with the payout stored in its document row instead (ADR-060 060-4:
 * issued documents are never recalculated), so the balance stays in step
 * with the documents and earlier settlements are not regulated
 * automatically (ADR-063 pkt. 2–3 is a separate workflow). Integration tests
 * check that every sum of unsettled rows equals the sum of
 * RealizedPayout::for_entry() over the same rows.
 */
final class LedgerCreatorPayouts {

	/**
	 * The default holding period in days (planning decision 2).
	 */
	public const DEFAULT_HOLDING_DAYS = 14;

	/**
	 * The longest holding period accepted, in days.
	 */
	public const MAX_HOLDING_DAYS = 3650;

	/**
	 * The ledger rows with their snapshot, tax and the sale row of their
	 * order line and cycle. Placeholders: sales, snapshot, tax and sales table.
	 */
	public const FROM = LedgerCreatorProfits::FROM . "
		LEFT JOIN %i s ON s.order_id = r.order_id AND s.order_item_id = r.order_item_id
			AND s.cycle = r.cycle AND s.type = 'sale' AND s.creator_id = r.creator_id";

	/**
	 * When a row's payout is known (RealizedPayout::for_entry()).
	 */
	public const KNOWN = '( ' . LedgerCreatorProfits::KNOWN . ' AND p.vat_registered IN ( 0, 1 ) )';

	/**
	 * A row's payout as calculated now (ADR-063): its profit, already rounded
	 * to the snapshot's store precision.
	 */
	public const PAYOUT = LedgerCreatorProfits::PROFIT;

	/**
	 * A row's payout for the balance: the payout stored in its settlement
	 * document row when it is settled (ADR-060 060-4), otherwise PAYOUT.
	 * Needs the settlement rows joined as `d` on the row's event key.
	 */
	private const SETTLED_OR_PAYOUT = 'COALESCE( d.payout_amount, ' . self::PAYOUT . ' )';

	/**
	 * D2: when a row is released. Placeholders: now, then now minus the
	 * holding period (release_times()).
	 */
	private const RELEASED = '( ( ( ' . self::SETTLED_OR_PAYOUT . ' ) < 0 AND r.occurred_at <= %s ) OR ( ( ' . self::SETTLED_OR_PAYOUT . ' ) >= 0 AND r.occurred_at < %s ) )';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Earned (released) payout per currency.
	 *
	 * A currency without any row in the window has no entry: nothing is
	 * earned there, which is known. A currency whose rows are all held has
	 * no entry either.
	 *
	 * @param int                    $creator_id   Creator id.
	 * @param DateTimeImmutable|null $start        Settlement start, null for the whole history.
	 * @param DateTimeImmutable      $now          The time of the calculation.
	 * @param int                    $holding_days Holding period for rows with a payout ≥ 0.
	 * @return array<string, ProfitSummary>
	 * @throws InvalidArgumentException When the holding period is out of range.
	 * @throws DatabaseException When the ledger cannot be read.
	 */
	public function earned( int $creator_id, ?DateTimeImmutable $start, DateTimeImmutable $now, int $holding_days ): array {
		if ( $holding_days < 0 || $holding_days > self::MAX_HOLDING_DAYS ) {
			throw new InvalidArgumentException( 'The holding period is out of range.' );
		}

		if ( $creator_id < 1 ) {
			return array();
		}

		$known   = self::known( $start );
		$times   = self::release_times( $now, $holding_days );
		$columns = 'r.currency,
			SUM( CASE WHEN ' . $known . ' AND ' . self::RELEASED . ' THEN ' . self::SETTLED_OR_PAYOUT . ' ELSE 0 END ) AS earned,
			SUM( CASE WHEN ' . $known . ' AND ' . self::RELEASED . ' THEN 1 ELSE 0 END ) AS known_rows,
			SUM( CASE WHEN ' . $known . ' THEN 0 ELSE 1 END ) AS unknown_rows';
		$wpdb    = $this->wpdb;
		$sql     = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The replacements are spread from array_merge() below.
			"SELECT {$columns} " . self::FROM . ' LEFT JOIN %i d ON d.event_key = r.event_key WHERE r.creator_id = %d' . self::window( $start ) . ' GROUP BY r.currency HAVING known_rows + unknown_rows > 0 ORDER BY r.currency', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Constant SQL fragments with placeholders only: the two release times twice (earned, known rows), the table names, the creator, then the window's values.
			...array_merge(
				$times,
				$times,
				array(
					Tables::name( $wpdb, Tables::SALES ),
					Tables::name( $wpdb, Tables::SALES_LINE_PRICING ),
					Tables::name( $wpdb, Tables::SALES_TAX ),
					Tables::name( $wpdb, Tables::SALES ),
					Tables::name( $wpdb, Tables::SETTLEMENT_ROWS ),
					$creator_id,
				),
				self::window_args( $start )
			)
		);
		$rows    = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; payouts are never cached (they change with the time and with every request).

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the creator\'s payout.' );
		}

		$result = array();

		foreach ( $rows as $row ) {
			try {
				foreach ( array( 'known_rows', 'unknown_rows' ) as $column ) {
					if ( ! is_numeric( $row[ $column ] ?? null ) ) {
						throw new DatabaseException( 'A payout row does not match the schema.' );
					}
				}

				$known   = (int) $row['known_rows'];
				$summary = new ProfitSummary(
					(string) ( $row['currency'] ?? '' ),
					0 === $known ? 0 : SalesAmount::parse( is_string( $row['earned'] ?? null ) ? $row['earned'] : '' ),
					$known,
					(int) $row['unknown_rows']
				);
			} catch ( DatabaseException $e ) {
				throw $e;
			} catch ( Throwable ) {
				throw new DatabaseException( 'A payout row does not match the schema.' );
			}

			$result[ $summary->currency ] = $summary;
		}

		return $result;
	}

	/**
	 * The released ledger rows of one currency that no settlement document has
	 * settled yet (ADR-060), with the same window, release and known rules as
	 * earned(), each with its profit and payout. Null when any row in the
	 * window is unknown, held or not: the balance is then unknown and nothing
	 * may be settled (never 0). With $lock the rows are read LOCK IN SHARE
	 * MODE inside the caller's transaction, so an order's history cannot be
	 * deleted (ADR-052, D5) while they are settled.
	 *
	 * @param int                    $creator_id   Creator id.
	 * @param string                 $currency     Currency.
	 * @param DateTimeImmutable|null $start        Settlement start, null for the whole history.
	 * @param DateTimeImmutable      $now          The time of the settlement.
	 * @param int                    $holding_days Holding period for rows with a payout ≥ 0.
	 * @param bool                   $lock         Read with shared locks (inside a transaction).
	 * @return list<array{event_key: string, order_id: int, order_item_id: int, product_id: int, variation_id: int, item_name: string, type: string, quantity: int, net_units: int, tax_units: int, share_bps: int, vat_registered: bool, price_decimals: int, profit_units: int, payout_units: int, occurred_at: string}>|null
	 * @throws InvalidArgumentException When the holding period is out of range.
	 * @throws DatabaseException When the ledger cannot be read.
	 */
	public function unsettled_rows( int $creator_id, string $currency, ?DateTimeImmutable $start, DateTimeImmutable $now, int $holding_days, bool $lock = false ): ?array {
		if ( $holding_days < 0 || $holding_days > self::MAX_HOLDING_DAYS ) {
			throw new InvalidArgumentException( 'The holding period is out of range.' );
		}

		$known = self::known( $start );
		$wpdb  = $this->wpdb;
		$query = 'SELECT r.event_key, r.order_id, r.order_item_id, r.product_id, r.variation_id, r.item_name, r.type, r.quantity, r.net_amount, r.occurred_at, t.tax_amount, p.share_bps, p.vat_registered, p.price_decimals,
			CASE WHEN ' . $known . ' THEN 1 ELSE 0 END AS known_row,
			CASE WHEN ' . $known . ' AND ' . self::RELEASED . ' THEN 1 ELSE 0 END AS released_row,
			CASE WHEN ' . $known . ' THEN ' . LedgerCreatorProfits::PROFIT . ' ELSE NULL END AS profit,
			CASE WHEN ' . $known . ' THEN ' . self::PAYOUT . ' ELSE NULL END AS payout '
			. self::FROM . ' LEFT JOIN %i d ON d.event_key = r.event_key WHERE r.creator_id = %d AND r.currency = %s AND d.event_key IS NULL' . self::window( $start ) . ' ORDER BY r.occurred_at, r.id' . ( $lock ? ' LOCK IN SHARE MODE' : '' );
		$sql   = $wpdb->prepare(
			$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Constant SQL fragments with placeholders only: the two release times, the table names, the creator and currency, then the window's values.
			...array_merge(
				self::release_times( $now, $holding_days ),
				array(
					Tables::name( $wpdb, Tables::SALES ),
					Tables::name( $wpdb, Tables::SALES_LINE_PRICING ),
					Tables::name( $wpdb, Tables::SALES_TAX ),
					Tables::name( $wpdb, Tables::SALES ),
					Tables::name( $wpdb, Tables::SETTLEMENT_ROWS ),
					$creator_id,
					$currency,
				),
				self::window_args( $start )
			)
		);
		$rows  = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; settlements are never cached.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the creator\'s unsettled rows.' );
		}

		$result = array();

		foreach ( $rows as $row ) {
			if ( '1' !== (string) $row['known_row'] ) {
				return null;
			}

			try {
				$payout   = SalesAmount::parse( (string) $row['payout'] );
				$occurred = (string) $row['occurred_at'];

				// D2, as in earned(): a held row is not settled yet.
				if ( '1' !== (string) $row['released_row'] ) {
					continue;
				}

				$result[] = array(
					'event_key'      => (string) $row['event_key'],
					'order_id'       => (int) $row['order_id'],
					'order_item_id'  => (int) $row['order_item_id'],
					'product_id'     => (int) $row['product_id'],
					'variation_id'   => (int) $row['variation_id'],
					'item_name'      => (string) $row['item_name'],
					'type'           => (string) $row['type'],
					'quantity'       => (int) $row['quantity'],
					'net_units'      => SalesAmount::parse( (string) $row['net_amount'] ),
					'tax_units'      => SalesAmount::parse( (string) $row['tax_amount'] ),
					'share_bps'      => (int) $row['share_bps'],
					'vat_registered' => '1' === (string) $row['vat_registered'],
					'price_decimals' => (int) $row['price_decimals'],
					'profit_units'   => SalesAmount::parse( (string) $row['profit'] ),
					'payout_units'   => $payout,
					'occurred_at'    => $occurred,
				);
			} catch ( Throwable ) {
				throw new DatabaseException( 'A ledger row does not match the schema.' );
			}
		}

		return $result;
	}

	/**
	 * When a row in the window is known: its payout is known and, with a
	 * start, its sale row exists (a row that cannot be placed is unknown).
	 *
	 * @param DateTimeImmutable|null $start Settlement start, null for the whole history.
	 * @return literal-string
	 */
	private static function known( ?DateTimeImmutable $start ): string {
		return null === $start ? self::KNOWN : '( ' . self::KNOWN . ' AND s.id IS NOT NULL )';
	}

	/**
	 * D1: the condition that keeps a row in the window, appended to a WHERE
	 * clause; its value comes from window_args(). A row whose sale row is
	 * missing stays in the window (and is unknown).
	 *
	 * @param DateTimeImmutable|null $start Settlement start, null for the whole history.
	 * @return literal-string
	 */
	private static function window( ?DateTimeImmutable $start ): string {
		return null === $start ? '' : ' AND ( s.id IS NULL OR s.occurred_at >= %s )';
	}

	/**
	 * The values of window().
	 *
	 * @param DateTimeImmutable|null $start Settlement start, null for the whole history.
	 * @return list<string>
	 */
	private static function window_args( ?DateTimeImmutable $start ): array {
		return null === $start ? array() : array( self::utc( $start ) );
	}

	/**
	 * The values of RELEASED.
	 *
	 * @param DateTimeImmutable $now          The time of the calculation.
	 * @param int               $holding_days Holding period for rows with a payout ≥ 0.
	 * @return list<string>
	 */
	private static function release_times( DateTimeImmutable $now, int $holding_days ): array {
		return array( self::utc( $now ), self::utc( $now->sub( new DateInterval( 'P' . $holding_days . 'D' ) ) ) );
	}

	/**
	 * A time as stored in `occurred_at` (UTC).
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function utc( DateTimeImmutable $time ): string {
		return $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
