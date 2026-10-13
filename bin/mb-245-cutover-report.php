<?php
/**
 * Merch Dashboard, step 24.5 (A2): READ-ONLY impact report of a cut-over date for "ready for payout".
 * Version 2 (2026-10-12), review material: not part of the plugin package (bin/ is not shipped).
 * Quality-checked by tests/Integration/WooCommerce/CutoverReportTest.php on synthetic data.
 *
 * Run by the responsible person on the production site, only after review:
 *   wp eval-file bin/mb-245-cutover-report.php [deploy-date YYYY-MM-DD]
 * deploy-date defaults to today (UTC). Requires Merch Dashboard schema 11+ and its plugin active.
 *
 * Read only:
 * - It issues SELECT statements only, inside `START TRANSACTION READ ONLY` (MySQL 5.6.5+ / MariaDB
 *   10.0+), and ends with ROLLBACK. Any query error aborts the whole report (no partial output) with
 *   "Read N of 5 failed" and exit code 1. wpdb's error log and display are suppressed meanwhile: they
 *   would quote the failed query, and read 5 lists order ids. So does the database's own message,
 *   which is therefore not shown either.
 * - It does not load WooCommerce orders: the paid date is read with SQL (HPOS
 *   `wc_order_operational_data.date_paid_gmt`, else post meta `_date_paid` / `_paid_date`).
 * - WordPress or other plugins may still write during WP-CLI bootstrap or shutdown, outside this
 *   script's transaction (as for any `wp` command); run it when that is acceptable.
 * - Output: counts and sums per currency only. No order ids, names, e-mails, payout details or codes.
 *
 * Rules simulated (owner's preliminary decisions 2026-10-12, NOT implemented in the plugin):
 * - No order is marked yet: an order "on or after" the cut-over is unmarked; one before counts as today.
 * - Rows already in a settlement document count as today.
 * - Unmarked order, per creator and currency: its unsettled known rows in the creator's window
 *   contribute min(0, their net payout) at once (solution b); otherwise they do not count.
 * - Everything else exactly as LedgerCreatorPayouts / PayoutBalances today: settlement start (D1),
 *   holding period (D2), one unknown row in the window makes that creator's currency unknown.
 *
 * @package MerchBoard
 */

use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\WooCommerce\Pricing\LedgerCreatorPayouts;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

if ( ! function_exists( 'merchboard_cutover_collect' ) ) {

	/**
	 * Runs a read (wpdb resets `last_error` with every query) and fails on any database error or a
	 * query that could not be prepared. The message names the read only (see the file header).
	 *
	 * @param wpdb     $wpdb   Database.
	 * @param int      $number The read, 1–5.
	 * @param callable $read   The read.
	 * @return mixed
	 * @throws RuntimeException On a database error.
	 */
	function merchboard_cutover_read( wpdb $wpdb, int $number, callable $read ): mixed {
		$result = $read();

		if ( '' !== (string) $wpdb->last_error || null === $result ) {
			throw new RuntimeException( "Read {$number} of 5 failed." );
		}

		return $result;
	}

	/**
	 * Empty totals of one currency.
	 *
	 * @return array{creators: int, unknown: int, earned_before: int, earned_after: int, available_before: int, available_after: int, newly_uncovered_n: int, newly_uncovered: int, shortfall: int, refund_rows: int, refund_amount: int, refund_orders: int, neutralised_orders: int, negative_net_orders: int, negative_net_amount: int}
	 */
	function merchboard_cutover_currency_row(): array {
		return array(
			'creators'            => 0,
			'unknown'             => 0,
			'earned_before'       => 0,
			'earned_after'        => 0,
			'available_before'    => 0,
			'available_after'     => 0,
			'newly_uncovered_n'   => 0,
			'newly_uncovered'     => 0,
			'shortfall'           => 0,
			'refund_rows'         => 0,
			'refund_amount'       => 0,
			'refund_orders'       => 0,
			'neutralised_orders'  => 0,
			'negative_net_orders' => 0,
			'negative_net_amount' => 0,
		);
	}

	/**
	 * Reads everything the report needs.
	 *
	 * @param wpdb $wpdb Database.
	 * @return array{rows: list<array<string, mixed>>, first_sale: array<int, string>, cycle_sale: array<int, array<int, string>>, paid: array<int, string>, order_ids: list<int>, totals: array<string, array<string, int>>, last_settlement: string|null}
	 * @throws RuntimeException On a database error.
	 */
	function merchboard_cutover_collect( wpdb $wpdb ): array {
		$t = static fn( string $table ): string => Tables::name( $wpdb, $table );

		// Ledger rows with known flag, payout, sale row (D1), settled flag and the creator's settlement start.
		$query = 'SELECT r.order_id, r.creator_id, r.currency, r.cycle, r.type, r.occurred_at, c.payout_start_at, s.occurred_at AS sale_at,
				CASE WHEN ' . LedgerCreatorPayouts::KNOWN . ' THEN 1 ELSE 0 END AS known_row,
				CASE WHEN ' . LedgerCreatorPayouts::KNOWN . ' THEN ' . LedgerCreatorPayouts::PAYOUT . ' ELSE NULL END AS payout,
				CASE WHEN d.event_key IS NULL THEN 0 ELSE 1 END AS settled '
			. LedgerCreatorPayouts::FROM . ' LEFT JOIN %i d ON d.event_key = r.event_key LEFT JOIN %i c ON c.id = r.creator_id ORDER BY r.id';
		$sql   = $wpdb->prepare(
			$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The plugin's constant SQL fragments; placeholders are the table names.
			$t( Tables::SALES ),
			$t( Tables::SALES_LINE_PRICING ),
			$t( Tables::SALES_TAX ),
			$t( Tables::SALES ),
			$t( Tables::SETTLEMENT_ROWS ),
			$t( Tables::CREATORS )
		);
		$rows  = merchboard_cutover_read( $wpdb, 1, static fn() => is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null ); // phpcs:ignore WordPress.DB

		$first_sale = array();
		$cycle_sale = array();
		$orders     = merchboard_cutover_read( $wpdb, 2, static fn() => $wpdb->get_results( $wpdb->prepare( "SELECT order_id, cycle, MIN(occurred_at) AS at FROM %i WHERE type = 'sale' GROUP BY order_id, cycle", $t( Tables::SALES ) ), ARRAY_A ) ); // phpcs:ignore WordPress.DB

		foreach ( $orders as $row ) {
			$order                                       = (int) $row['order_id'];
			$cycle_sale[ $order ][ (int) $row['cycle'] ] = (string) $row['at'];
			$first_sale[ $order ]                        = isset( $first_sale[ $order ] ) ? min( $first_sale[ $order ], (string) $row['at'] ) : (string) $row['at'];
		}

		$order_ids = array_values( array_unique( array_map( static fn( array $row ): int => (int) $row['order_id'], $rows ) ) );

		$totals = array();

		foreach ( merchboard_cutover_read( $wpdb, 3, static fn() => $wpdb->get_results( $wpdb->prepare( "SELECT creator_id, currency, status, COUNT(*) AS n, SUM(amount) AS amount FROM %i WHERE status IN ('pending', 'completed') GROUP BY creator_id, currency, status", $t( Tables::PAYOUTS ) ), ARRAY_A ) ) as $row ) { // phpcs:ignore WordPress.DB
			$key                                     = $row['creator_id'] . '|' . $row['currency'];
			$totals[ $key ][ $row['status'] ]        = SalesAmount::parse( (string) $row['amount'] );
			$totals[ $key ][ $row['status'] . '_n' ] = (int) $row['n'];
		}

		// The latest settlement across all creators; a correction document settles nothing and does not count.
		$last = merchboard_cutover_read( $wpdb, 4, static fn() => array( $wpdb->get_var( $wpdb->prepare( "SELECT MAX(issued_at) FROM %i WHERE document_type = 'settlement'", $t( Tables::SETTLEMENT_DOCUMENTS ) ) ) ) )[0]; // phpcs:ignore WordPress.DB

		return array(
			'rows'            => $rows,
			'first_sale'      => $first_sale,
			'cycle_sale'      => $cycle_sale,
			'paid'            => merchboard_cutover_paid_dates( $wpdb, $order_ids ),
			'order_ids'       => $order_ids,
			'totals'          => $totals,
			'last_settlement' => null === $last ? null : (string) $last,
		);
	}

	/**
	 * WooCommerce' paid date (UTC 'Y-m-d H:i:s') per order, read with SQL; orders without one are missing.
	 *
	 * @phpstan-param list<int> $order_ids
	 *
	 * @param wpdb  $wpdb      Database.
	 * @param int[] $order_ids Orders.
	 * @return array<int, string>
	 * @throws RuntimeException On a database error.
	 */
	function merchboard_cutover_paid_dates( wpdb $wpdb, array $order_ids ): array {
		$paid = array();

		if ( array() === $order_ids ) {
			return $paid;
		}

		$in = implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) );

		if ( 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ) ) {
			$hpos = 'SELECT order_id, date_paid_gmt FROM %i WHERE order_id IN (' . $in . ') AND date_paid_gmt IS NOT NULL';
			$rows = merchboard_cutover_read( $wpdb, 5, static fn() => $wpdb->get_results( $wpdb->prepare( $hpos, $wpdb->prefix . 'wc_order_operational_data', ...$order_ids ), ARRAY_A ) ); // phpcs:ignore WordPress.DB

			foreach ( $rows as $row ) {
				$paid[ (int) $row['order_id'] ] = (string) $row['date_paid_gmt'];
			}

			return $paid;
		}

		$meta = "SELECT post_id, meta_key, meta_value FROM %i WHERE post_id IN ({$in}) AND meta_key IN ('_date_paid', '_paid_date') AND meta_value <> ''";
		$rows = merchboard_cutover_read( $wpdb, 5, static fn() => $wpdb->get_results( $wpdb->prepare( $meta, $wpdb->postmeta, ...$order_ids ), ARRAY_A ) ); // phpcs:ignore WordPress.DB

		foreach ( $rows as $row ) {
			$value = (string) $row['meta_value'];
			$id    = (int) $row['post_id'];

			// `_date_paid` (timestamp) wins over the old `_paid_date` (site-time text), as in WooCommerce.
			if ( '_date_paid' === $row['meta_key'] && ctype_digit( $value ) ) {
				$paid[ $id ] = gmdate( 'Y-m-d H:i:s', (int) $value );
			} elseif ( '_paid_date' === $row['meta_key'] && ! isset( $paid[ $id ] ) ) {
				$time = date_create_immutable( $value, wp_timezone() );

				if ( false !== $time ) {
					$paid[ $id ] = $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
				}
			}
		}

		return $paid;
	}

	/**
	 * Whether a row is in the creator's window (D1) and whether it is unknown there.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return string 'out', 'unknown' or 'known'.
	 */
	function merchboard_cutover_row_state( array $row ): string {
		$start = $row['payout_start_at'];

		if ( null !== $start && null !== $row['sale_at'] && (string) $row['sale_at'] < (string) $start ) {
			return 'out';
		}

		return '1' !== (string) $row['known_row'] || ( null !== $start && null === $row['sale_at'] ) ? 'unknown' : 'known';
	}

	/**
	 * Earned per creator|currency (null = unknown) under today's rules or the simulated rule.
	 *
	 * @phpstan-param array{rows: list<array<string, mixed>>, first_sale: array<int, string>, cycle_sale: array<int, array<int, string>>, paid: array<int, string>, order_ids: list<int>, totals: array<string, array<string, int>>, last_settlement: string|null} $data
	 *
	 * @param array<string, mixed> $data     From merchboard_cutover_collect().
	 * @param string               $now      Now, UTC.
	 * @param string               $released Now minus the holding period, UTC.
	 * @param callable|null        $unmarked fn( int $order, int $cycle ): bool; null = today's rules.
	 * @return array<string, int|null>
	 */
	function merchboard_cutover_earned( array $data, string $now, string $released, ?callable $unmarked ): array {
		$sum = array();
		$net = array();

		foreach ( array_keys( $data['totals'] ) as $key ) {
			$sum[ $key ] = 0;
		}

		foreach ( $data['rows'] as $row ) {
			$key = $row['creator_id'] . '|' . $row['currency'];

			if ( ! array_key_exists( $key, $sum ) ) {
				$sum[ $key ] = 0;
			}

			$state = merchboard_cutover_row_state( $row );

			if ( 'out' === $state ) {
				continue;
			}

			if ( 'unknown' === $state ) {
				$sum[ $key ] = null;
				continue;
			}

			$payout = SalesAmount::parse( (string) $row['payout'] );

			if ( null !== $unmarked && '0' === (string) $row['settled'] && $unmarked( (int) $row['order_id'], (int) $row['cycle'] ) ) {
				$net[ $key ][ (int) $row['order_id'] ] = ( $net[ $key ][ (int) $row['order_id'] ] ?? 0 ) + $payout;
				continue;
			}

			// D2: a negative row at once, a row >= 0 after the holding period.
			$counted = $payout < 0 ? (string) $row['occurred_at'] <= $now : (string) $row['occurred_at'] < $released;

			if ( $counted && null !== $sum[ $key ] ) {
				$sum[ $key ] += $payout;
			}
		}

		foreach ( $net as $key => $orders ) {
			foreach ( $orders as $order_net ) {
				if ( null !== $sum[ $key ] ) {
					$sum[ $key ] += min( 0, $order_net );
				}
			}
		}

		return $sum;
	}

	/**
	 * Balance (earned − completed − reserved) per creator|currency, null when unknown.
	 *
	 * @param array<string, int|null>           $earned Earned.
	 * @param array<string, array<string, int>> $totals Payout totals.
	 * @return array<string, int|null>
	 */
	function merchboard_cutover_balances( array $earned, array $totals ): array {
		$balances = array();

		foreach ( $earned as $key => $units ) {
			$balances[ $key ] = null === $units ? null : $units - ( $totals[ $key ]['completed'] ?? 0 ) - ( $totals[ $key ]['pending'] ?? 0 );
		}

		return $balances;
	}

	/**
	 * The report of one cut-over and definition.
	 *
	 * @phpstan-param array{rows: list<array<string, mixed>>, first_sale: array<int, string>, cycle_sale: array<int, array<int, string>>, paid: array<int, string>, order_ids: list<int>, totals: array<string, array<string, int>>, last_settlement: string|null} $data
	 *
	 * @param array<string, mixed> $data     From merchboard_cutover_collect().
	 * @param string               $now      Now, UTC.
	 * @param string               $released Now minus the holding period, UTC.
	 * @param callable             $unmarked fn( int $order, int $cycle ): bool.
	 * @return array<string, mixed>
	 */
	function merchboard_cutover_option( array $data, string $now, string $released, callable $unmarked ): array {
		$earned_before  = merchboard_cutover_earned( $data, $now, $released, null );
		$earned_after   = merchboard_cutover_earned( $data, $now, $released, $unmarked );
		$balance_before = merchboard_cutover_balances( $earned_before, $data['totals'] );
		$balance_after  = merchboard_cutover_balances( $earned_after, $data['totals'] );
		$orders         = array();
		$creators       = array();
		$net            = array();
		$refunds        = array();
		$currencies     = array();

		foreach ( $data['rows'] as $row ) {
			if ( '0' !== (string) $row['settled'] || 'out' === merchboard_cutover_row_state( $row ) || ! $unmarked( (int) $row['order_id'], (int) $row['cycle'] ) ) {
				continue;
			}

			$orders[ (int) $row['order_id'] ]     = true;
			$creators[ (int) $row['creator_id'] ] = true;

			if ( 'known' !== merchboard_cutover_row_state( $row ) ) {
				continue;
			}

			$okey         = $row['order_id'] . '|' . $row['creator_id'] . '|' . $row['currency'];
			$payout       = SalesAmount::parse( (string) $row['payout'] );
			$net[ $okey ] = ( $net[ $okey ] ?? 0 ) + $payout;

			if ( $payout < 0 ) {
				$refunds[ $okey ] = ( $refunds[ $okey ] ?? array( 0, 0 ) );
				++$refunds[ $okey ][0];
				$refunds[ $okey ][1] += $payout;
			}
		}

		$keys = array_unique( array_merge( array_keys( $earned_before ), array_keys( $earned_after ) ) );

		foreach ( $keys as $key ) {
			$currency                = explode( '|', $key )[1];
			$currencies[ $currency ] = $currencies[ $currency ] ?? merchboard_cutover_currency_row();
			++$currencies[ $currency ]['creators'];

			if ( null === $balance_before[ $key ] || null === $balance_after[ $key ] ) {
				++$currencies[ $currency ]['unknown'];
				continue;
			}

			$currencies[ $currency ]['earned_before']    += (int) $earned_before[ $key ];
			$currencies[ $currency ]['earned_after']     += (int) $earned_after[ $key ];
			$currencies[ $currency ]['available_before'] += max( 0, $balance_before[ $key ] );
			$currencies[ $currency ]['available_after']  += max( 0, $balance_after[ $key ] );

			// Pending payouts that the transition leaves uncovered (balance includes their reservation).
			if ( $balance_before[ $key ] >= 0 && $balance_after[ $key ] < 0 && ( $data['totals'][ $key ]['pending_n'] ?? 0 ) > 0 ) {
				$currencies[ $currency ]['newly_uncovered_n'] += $data['totals'][ $key ]['pending_n'];
				$currencies[ $currency ]['newly_uncovered']   += $data['totals'][ $key ]['pending'];
				$currencies[ $currency ]['shortfall']         += -$balance_after[ $key ];
			}
		}

		foreach ( $net as $okey => $order_net ) {
			$currency                = explode( '|', $okey )[2];
			$currencies[ $currency ] = $currencies[ $currency ] ?? merchboard_cutover_currency_row();

			if ( isset( $refunds[ $okey ] ) ) {
				$currencies[ $currency ]['refund_rows']   += $refunds[ $okey ][0];
				$currencies[ $currency ]['refund_amount'] += $refunds[ $okey ][1];
				++$currencies[ $currency ]['refund_orders'];

				if ( $order_net >= 0 ) {
					++$currencies[ $currency ]['neutralised_orders'];
				}
			}

			if ( $order_net < 0 ) {
				++$currencies[ $currency ]['negative_net_orders'];
				$currencies[ $currency ]['negative_net_amount'] += $order_net;
			}
		}

		ksort( $currencies );

		return array(
			'orders'     => count( $orders ),
			'creators'   => count( $creators ),
			'currencies' => $currencies,
		);
	}

	/**
	 * The three definitions of "on or after the cut-over" (unmarked).
	 *
	 * @phpstan-param array{rows: list<array<string, mixed>>, first_sale: array<int, string>, cycle_sale: array<int, array<int, string>>, paid: array<int, string>, order_ids: list<int>, totals: array<string, array<string, int>>, last_settlement: string|null} $data
	 *
	 * @param array<string, mixed> $data From merchboard_cutover_collect().
	 * @param string               $at   Cut-over, UTC.
	 * @return array<string, callable>
	 */
	function merchboard_cutover_definitions( array $data, string $at ): array {
		return array(
			'1a first sale row of the order'            => static fn( int $order, int $cycle ): bool => isset( $data['first_sale'][ $order ] ) && $data['first_sale'][ $order ] >= $at, // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- One signature for every definition.
			'1b sale row of the cycle (reopened = new)' => static fn( int $order, int $cycle ): bool => isset( $data['cycle_sale'][ $order ][ $cycle ] ) ? $data['cycle_sale'][ $order ][ $cycle ] >= $at : ( isset( $data['first_sale'][ $order ] ) && $data['first_sale'][ $order ] >= $at ),
			'2  WooCommerce paid date (no date = unmarked)' => static fn( int $order, int $cycle ): bool => ! isset( $data['paid'][ $order ] ) || $data['paid'][ $order ] >= $at, // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- One signature for every definition.
		);
	}

	/**
	 * The WP-CLI report (read only).
	 *
	 * @param wpdb         $wpdb Database.
	 * @param array<mixed> $args `wp eval-file` arguments: [deploy-date].
	 */
	function merchboard_cutover_main( wpdb $wpdb, array $args ): void {
		if ( (int) get_option( 'merchboard_db_version' ) < 11 || ! class_exists( LedgerCreatorPayouts::class ) ) {
			WP_CLI::error( 'Merch Dashboard with schema 11 or later must be active.' );
			return;
		}

		$utc    = new DateTimeZone( 'UTC' );
		$now    = new DateTimeImmutable( 'now', $utc );
		$deploy = DateTimeImmutable::createFromFormat( '!Y-m-d', $args[0] ?? $now->format( 'Y-m-d' ), $utc );

		if ( false === $deploy ) {
			WP_CLI::error( 'The deploy date must be YYYY-MM-DD.' );
			return;
		}

		// See the file header: a failed query would be logged (or printed) in full.
		$suppressed = $wpdb->suppress_errors( true );

		if ( false === $wpdb->query( 'START TRANSACTION READ ONLY' ) ) { // phpcs:ignore WordPress.DB
			$wpdb->suppress_errors( $suppressed );
			WP_CLI::error( 'Could not start a read-only transaction; nothing was read.' );
			return;
		}

		// Every read happens here, before the first line is printed. WP_CLI::error() exits, so it comes
		// after the inner finally (ROLLBACK).
		try {
			try {
				$holding  = Settings::payout_holding_days();
				$until    = $now->format( 'Y-m-d H:i:s' );
				$released = $now->sub( new DateInterval( 'P' . $holding . 'D' ) )->format( 'Y-m-d H:i:s' );
				$data     = merchboard_cutover_collect( $wpdb );
				$month    = new DateTimeImmutable( 'first day of last month 00:00:00', wp_timezone() );
				$options  = array(
					'deploy day ' . $deploy->format( 'Y-m-d' ) . ' (UTC)' => $deploy->format( 'Y-m-d H:i:s' ),
					'start of the last completed month ' . $month->format( 'Y-m-d' ) . ' (site time)' => $month->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
					'latest settlement document' => $data['last_settlement'],
				);
				$fmt      = static fn( int $units ): string => SalesAmount::format( $units );
			} finally {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
				$wpdb->suppress_errors( $suppressed );
			}
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() . ' The report is aborted; nothing was printed.' );
			return;
		}

		WP_CLI::line( "Merch Dashboard 24.5 cut-over report v2 (read only). Now {$until} UTC, holding period {$holding} days." );
		WP_CLI::line( 'Orders in the ledger: ' . count( $data['order_ids'] ) . ', without a sale row: ' . count( array_diff( $data['order_ids'], array_keys( $data['first_sale'] ) ) ) . ', without a WooCommerce paid date: ' . count( array_diff( $data['order_ids'], array_keys( $data['paid'] ) ) ) . ', reopened (more than one cycle): ' . count( array_filter( $data['cycle_sale'], static fn( array $c ): bool => count( $c ) > 1 ) ) );

		foreach ( $options as $label => $at ) {
			WP_CLI::line( '' );

			if ( null === $at ) {
				WP_CLI::line( "== {$label}: none, not applicable" );
				continue;
			}

			WP_CLI::line( "== {$label}: cut-over {$at} UTC" );

			foreach ( merchboard_cutover_definitions( $data, $at ) as $name => $unmarked ) {
				$r = merchboard_cutover_option( $data, $until, $released, $unmarked );
				WP_CLI::line( "  -- {$name}: unmarked orders with unsettled rows {$r['orders']}, creators {$r['creators']}, currencies " . count( $r['currencies'] ) );

				foreach ( $r['currencies'] as $currency => $c ) {
					WP_CLI::line( "     {$currency}: creators {$c['creators']} (unknown balance {$c['unknown']}, not in the sums)" );
					WP_CLI::line( '       earned/released before ' . $fmt( $c['earned_before'] ) . ', after ' . $fmt( $c['earned_after'] ) );
					WP_CLI::line( '       available before ' . $fmt( $c['available_before'] ) . ', after ' . $fmt( $c['available_after'] ) );
					WP_CLI::line( "       pending payouts newly uncovered {$c['newly_uncovered_n']} (" . $fmt( $c['newly_uncovered'] ) . '), shortfall ' . $fmt( $c['shortfall'] ) );
					WP_CLI::line( "       unmarked orders with refunds/reversals {$c['refund_orders']} ({$c['refund_rows']} rows, " . $fmt( $c['refund_amount'] ) . "), of which neutralised by rule b {$c['neutralised_orders']}; unmarked orders with a negative net {$c['negative_net_orders']} (" . $fmt( $c['negative_net_amount'] ) . ')' );
				}
			}
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && ! defined( 'MERCHBOARD_CUTOVER_NO_MAIN' ) ) {
	merchboard_cutover_main( $GLOBALS['wpdb'], isset( $args ) && is_array( $args ) ? $args : array() );
}
