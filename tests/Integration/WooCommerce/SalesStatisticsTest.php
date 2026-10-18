<?php
/**
 * Sales statistics read from the sales ledger (ADR-040) against a real
 * MySQL/MariaDB.
 *
 * Ledger rows are stored directly, so every type, sign, date and currency can
 * be placed exactly; the write path is covered by the synchronizer's tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\WooCommerce\Sales\LedgerSalesStatistics;
use MerchBoard\Modules\WooCommerce\Sales\SalesInterval;
use MerchBoard\Modules\WooCommerce\Sales\SalesPeriods;
use MerchBoard\Modules\WooCommerce\Sales\SalesProductTotals;
use MerchBoard\Modules\WooCommerce\Sales\SalesSeriesPoint;
use MerchBoard\Modules\WooCommerce\Sales\SalesTotals;
use MerchBoard\Tests\Integration\IntegrationTestCase;

final class SalesStatisticsTest extends IntegrationTestCase {

	private const MINE   = 3;
	private const THEIRS = 4;

	private LedgerSalesStatistics $statistics;

	private DateTimeZone $copenhagen;

	private int $key = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->statistics = new LedgerSalesStatistics( $this->wpdb );
		$this->copenhagen = new DateTimeZone( 'Europe/Copenhagen' );
	}

	public function test_amounts_incl_vat_add_each_rows_stored_tax_and_are_null_when_a_row_has_none(): void {
		$this->row( 'sale', 101, 2, '200.0000', '2026-07-10 10:00:00' );
		$this->tax( 101, '50.0000' );
		$this->row( 'refund', 101, -1, '-100.0000', '2026-07-12 10:00:00' );
		$this->tax( 101, '-25.0000' );

		$totals = $this->statistics->totals( self::MINE, ...SalesPeriods::from_dates( '2026-07-01', '2026-07-31', $this->copenhagen ) );
		self::assertCount( 1, $totals );
		self::assertSame( array( '200.0000', '-100.0000', '100.0000' ), array( $totals[0]->sales, $totals[0]->adjustments, $totals[0]->net_sales ), 'The amounts excl. VAT are unchanged.' );
		self::assertSame( array( '250.0000', '-125.0000', '125.0000', 0 ), array( $totals[0]->sales_incl_vat, $totals[0]->adjustments_incl_vat, $totals[0]->net_sales_incl_vat, $totals[0]->missing_vat_rows ) );
		self::assertSame(
			array(
				9  => '250.0000',
				11 => '-125.0000',
			),
			$this->series_incl_vat( '2026-07-01', '2026-07-31' )
		);
		self::assertSame( array( '125.0000' ), $this->products_incl_vat( '2026-07-01', '2026-07-31' ) );

		// A row without stored tax (registered before migration 7): unknown, never 0.
		$this->row( 'sale', 102, 1, '80.0000', '2026-07-20 10:00:00' );
		$totals = $this->statistics->totals( self::MINE, ...SalesPeriods::from_dates( '2026-07-01', '2026-07-31', $this->copenhagen ) );
		self::assertSame( array( '280.0000', null, null, null, 1 ), array( $totals[0]->sales, $totals[0]->sales_incl_vat, $totals[0]->adjustments_incl_vat, $totals[0]->net_sales_incl_vat, $totals[0]->missing_vat_rows ) );
		self::assertSame(
			array(
				9  => '250.0000',
				11 => '-125.0000',
				19 => null,
			),
			$this->series_incl_vat( '2026-07-01', '2026-07-31' ),
			'Only the period with the untaxed row is unknown.'
		);
		self::assertSame( array( null ), $this->products_incl_vat( '2026-07-01', '2026-07-31' ) );

		// Tax in another currency than the row counts as missing.
		$this->row( 'sale', 103, 1, '10.0000', '2026-08-03 10:00:00', array( 'currency' => 'EUR' ) );
		$this->tax( 103, '2.5000', 'DKK' );
		$eur = $this->statistics->totals( self::MINE, ...SalesPeriods::from_dates( '2026-08-01', '2026-08-31', $this->copenhagen ) );
		self::assertSame( array( 'EUR', null, 1 ), array( $eur[0]->currency, $eur[0]->net_sales_incl_vat, $eur[0]->missing_vat_rows ) );
	}

	public function test_sales_only(): void {
		$this->row( 'sale', 101, 2, '200.0000', '2026-07-10 10:00:00' );

		self::assertSame(
			array( array( 'DKK', '200.0000', '0.0000', '200.0000', 2, 1 ) ),
			$this->totals( '2026-07-01', '2026-07-31' )
		);
	}

	public function test_a_refund_counts_on_its_own_date(): void {
		$this->row( 'sale', 101, 2, '200.0000', '2026-07-10 10:00:00' );
		$this->row( 'refund', 101, -1, '-100.0000', '2026-08-05 10:00:00' );

		self::assertSame( array( array( 'DKK', '200.0000', '0.0000', '200.0000', 2, 1 ) ), $this->totals( '2026-07-01', '2026-07-31' ) );
		self::assertSame( array( array( 'DKK', '0.0000', '-100.0000', '-100.0000', -1, 0 ) ), $this->totals( '2026-08-01', '2026-08-31' ), 'A period with only a refund: negative, no orders.' );
		self::assertSame( array( array( 'DKK', '200.0000', '-100.0000', '100.0000', 1, 1 ) ), $this->totals( '2026-07-01', '2026-08-31' ) );
	}

	public function test_reversals_and_corrections_are_adjustments(): void {
		$this->row( 'sale', 101, 3, '300.0000', '2026-07-10 10:00:00' );
		$this->row( 'refund', 101, -1, '-100.0000', '2026-07-11 10:00:00' );
		$this->row( 'reversal', 101, -3, '-300.0000', '2026-07-12 10:00:00' );
		$this->row( 'correction', 101, 1, '100.0000', '2026-07-13 10:00:00', array( 'correction_reason' => 'closed_cycle_refund' ) );
		$this->row( 'correction', 102, -1, '-12.3456', '2026-07-14 10:00:00', array( 'correction_reason' => 'line_removed' ) );

		self::assertSame(
			array( array( 'DKK', '300.0000', '-312.3456', '-12.3456', -1, 1 ) ),
			$this->totals( '2026-07-01', '2026-07-31' ),
			'Only the sale is sales; refund, reversal and both corrections are adjustments.'
		);
	}

	public function test_units_are_signed_and_summed_over_every_type(): void {
		$this->row( 'sale', 101, 5, '500.0000', '2026-07-10 10:00:00' );
		$this->row( 'refund', 101, -2, '-200.0000', '2026-07-11 10:00:00' );
		$this->row( 'reversal', 102, -4, '-400.0000', '2026-07-12 10:00:00' );

		self::assertSame( -1, $this->totals( '2026-07-01', '2026-07-31' )[0][4] );
	}

	public function test_orders_are_distinct_orders_with_a_sale_row(): void {
		// Two lines of one order, a second order, and an order with only a refund and a reversal in the period.
		$this->row( 'sale', 101, 1, '100.0000', '2026-07-10 10:00:00', array( 'order_item_id' => 1 ) );
		$this->row( 'sale', 101, 1, '100.0000', '2026-07-10 10:00:00', array( 'order_item_id' => 2 ) );
		$this->row( 'sale', 102, 1, '100.0000', '2026-07-11 10:00:00' );
		$this->row( 'refund', 103, -1, '-100.0000', '2026-07-12 10:00:00' );
		$this->row( 'reversal', 104, -1, '-100.0000', '2026-07-12 10:00:00' );

		self::assertSame( 2, $this->totals( '2026-07-01', '2026-07-31' )[0][5] );
	}

	public function test_several_events_of_one_order(): void {
		// Sold, cancelled (reversal), reopened (sale of cycle 2), partly refunded.
		$this->row( 'sale', 101, 2, '200.0000', '2026-07-10 10:00:00', array( 'cycle' => 1 ) );
		$this->row( 'reversal', 101, -2, '-200.0000', '2026-07-11 10:00:00', array( 'cycle' => 1 ) );
		$this->row( 'sale', 101, 2, '200.0000', '2026-07-12 10:00:00', array( 'cycle' => 2 ) );
		$this->row( 'refund', 101, -1, '-100.0000', '2026-07-13 10:00:00' );

		self::assertSame(
			array( array( 'DKK', '400.0000', '-300.0000', '100.0000', 1, 1 ) ),
			$this->totals( '2026-07-01', '2026-07-31' ),
			'Two sale rows of one order are one order.'
		);
	}

	public function test_currencies_are_never_mixed(): void {
		$this->row( 'sale', 101, 1, '100.0000', '2026-07-10 10:00:00', array( 'currency' => 'SEK' ) );
		$this->row( 'sale', 102, 2, '30.5000', '2026-07-10 10:00:00', array( 'currency' => 'EUR' ) );
		$this->row( 'sale', 103, 1, '75.0000', '2026-07-11 10:00:00' );
		$this->row( 'refund', 102, -1, '-15.2500', '2026-07-12 10:00:00', array( 'currency' => 'EUR' ) );

		self::assertSame(
			array(
				array( 'DKK', '75.0000', '0.0000', '75.0000', 1, 1 ),
				array( 'EUR', '30.5000', '-15.2500', '15.2500', 1, 1 ),
				array( 'SEK', '100.0000', '0.0000', '100.0000', 1, 1 ),
			),
			$this->totals( '2026-07-01', '2026-07-31' )
		);
		self::assertSame(
			array(
				array( 'DKK', 20, '75.0000', 1 ),
				array( 'EUR', 20, '15.2500', 1 ),
				array( 'SEK', 20, '100.0000', 1 ),
			),
			array_map( static fn( array $p ): array => array_slice( $p, 0, 4 ), $this->products( '2026-07-01', '2026-07-31' ) )
		);
	}

	public function test_variations_are_summed_under_their_parent_product(): void {
		$this->row(
			'sale',
			101,
			1,
			'100.0000',
			'2026-07-10 10:00:00',
			array(
				'variation_id' => 21,
				'item_name'    => 'Shirt - S',
			)
		);
		$this->row(
			'sale',
			102,
			2,
			'200.0000',
			'2026-07-11 10:00:00',
			array(
				'variation_id' => 22,
				'item_name'    => 'Shirt - M',
			)
		);
		$this->row(
			'refund',
			102,
			-1,
			'-100.0000',
			'2026-07-12 10:00:00',
			array(
				'variation_id' => 22,
				'item_name'    => 'Shirt - M',
			)
		);
		$this->row(
			'sale',
			103,
			1,
			'250.0000',
			'2026-07-11 10:00:00',
			array(
				'product_id' => 30,
				'item_name'  => 'Poster',
			)
		);
		$this->row(
			'sale',
			104,
			1,
			'50.0000',
			'2026-07-11 10:00:00',
			array(
				'product_id' => 40,
				'item_name'  => 'Sticker',
			)
		);

		self::assertSame(
			array(
				array( 'DKK', 30, '250.0000', 1, 'Poster' ),
				array( 'DKK', 20, '200.0000', 2, 'Shirt - M' ),
				array( 'DKK', 40, '50.0000', 1, 'Sticker' ),
			),
			$this->products( '2026-07-01', '2026-07-31' ),
			'Sorted by net sales; the name is the snapshot of the latest row.'
		);
	}

	public function test_equal_net_sales_are_sorted_by_product_id(): void {
		$this->row( 'sale', 101, 1, '100.0000', '2026-07-10 10:00:00', array( 'product_id' => 50 ) );
		$this->row( 'sale', 102, 1, '100.0000', '2026-07-10 10:00:00', array( 'product_id' => 30 ) );

		self::assertSame( array( 30, 50 ), array_column( $this->products( '2026-07-01', '2026-07-31' ), 1 ) );
	}

	public function test_rows_without_a_product_are_their_own_group_with_product_id_0(): void {
		$this->row(
			'sale',
			101,
			1,
			'100.0000',
			'2026-07-10 10:00:00',
			array(
				'product_id' => 0,
				'item_name'  => 'Deleted product',
			)
		);
		$this->row(
			'sale',
			102,
			1,
			'40.0000',
			'2026-07-10 10:00:00',
			array(
				'product_id' => 0,
				'item_name'  => 'Another deleted',
			)
		);
		$this->row( 'sale', 103, 1, '60.0000', '2026-07-10 10:00:00' );

		$products = $this->products( '2026-07-01', '2026-07-31' );

		self::assertSame(
			array(
				array( 'DKK', 0, '140.0000', 2, 'Another deleted' ),
				array( 'DKK', 20, '60.0000', 1, 'Line' ),
			),
			$products
		);
		self::assertSame( '200.0000', $this->totals( '2026-07-01', '2026-07-31' )[0][3], 'Products add up to the totals.' );
	}

	public function test_the_interval_is_half_open_and_comes_from_local_dates(): void {
		// July 1 to July 31 in Copenhagen is [2026-06-30 22:00, 2026-07-31 22:00) UTC.
		$this->row( 'sale', 101, 1, '1.0000', '2026-06-30 21:59:59' );
		$this->row( 'sale', 102, 1, '2.0000', '2026-06-30 22:00:00' );
		$this->row( 'sale', 103, 1, '4.0000', '2026-07-31 21:59:59' );
		$this->row( 'sale', 104, 1, '8.0000', '2026-07-31 22:00:00' );

		self::assertSame( '6.0000', $this->totals( '2026-07-01', '2026-07-31' )[0][3] );

		$queries = $this->recorded( fn() => $this->totals( '2026-07-01', '2026-07-31' ) );

		self::assertCount( 1, $queries );
		self::assertStringContainsString( "r.occurred_at >= '2026-06-30 22:00:00' AND r.occurred_at < '2026-07-31 22:00:00'", $queries[0] );
		self::assertStringNotContainsStringIgnoringCase( 'CONVERT_TZ', $queries[0] );
	}

	public function test_daily_series_in_the_site_time_zone(): void {
		$this->row( 'sale', 101, 1, '100.0000', '2026-07-01 21:59:59', array( 'order_item_id' => 1 ) ); // July 1, 23:59:59 local.
		$this->row( 'sale', 101, 1, '100.0000', '2026-07-01 22:00:00', array( 'order_item_id' => 2 ) ); // July 2, 00:00 local.
		$this->row( 'refund', 101, -1, '-100.0000', '2026-07-03 10:00:00' );
		$this->row( 'sale', 102, 1, '10.0000', '2026-07-03 11:00:00', array( 'currency' => 'EUR' ) );

		$periods = $this->periods( '2026-07-01', '2026-07-04', SalesInterval::Day );

		self::assertCount( 4, $periods );
		self::assertSame(
			array(
				array( 0, 'DKK', '100.0000', 1, 1 ),
				array( 1, 'DKK', '100.0000', 1, 1 ),
				array( 2, 'DKK', '-100.0000', -1, 0 ),
				array( 2, 'EUR', '10.0000', 1, 1 ),
			),
			self::points( $this->statistics->series( self::MINE, $periods ) ),
			'Only periods with rows; July 4 has none.'
		);
	}

	public function test_series_across_daylight_saving_time(): void {
		// 2026-03-29 is 23 hours long in Copenhagen: [2026-03-28 23:00, 2026-03-29 22:00) UTC.
		$this->row( 'sale', 101, 1, '1.0000', '2026-03-28 22:59:59' );
		$this->row( 'sale', 102, 1, '2.0000', '2026-03-28 23:00:00' );
		$this->row( 'sale', 103, 1, '4.0000', '2026-03-29 21:59:59' );
		$this->row( 'sale', 104, 1, '8.0000', '2026-03-29 22:00:00' );

		self::assertSame(
			array(
				array( 0, 'DKK', '1.0000', 1, 1 ),
				array( 1, 'DKK', '6.0000', 2, 2 ),
				array( 2, 'DKK', '8.0000', 1, 1 ),
			),
			self::points( $this->statistics->series( self::MINE, $this->periods( '2026-03-28', '2026-03-30', SalesInterval::Day ) ) )
		);
	}

	public function test_weekly_and_monthly_series(): void {
		$this->row( 'sale', 101, 1, '100.0000', '2026-07-01 10:00:00' ); // Wednesday, week of June 29.
		$this->row( 'sale', 102, 2, '200.0000', '2026-07-05 21:59:59' ); // Sunday 23:59:59 local.
		$this->row( 'sale', 103, 1, '50.0000', '2026-07-05 22:00:00' );  // Monday 00:00 local.
		$this->row( 'sale', 104, 1, '25.0000', '2026-08-03 10:00:00' );

		self::assertSame(
			array(
				array( 0, 'DKK', '300.0000', 3, 2 ),
				array( 1, 'DKK', '50.0000', 1, 1 ),
				array( 5, 'DKK', '25.0000', 1, 1 ),
			),
			self::points( $this->statistics->series( self::MINE, $this->periods( '2026-07-01', '2026-08-09', SalesInterval::Week ) ) )
		);
		self::assertSame(
			array(
				array( 0, 'DKK', '350.0000', 4, 3 ),
				array( 1, 'DKK', '25.0000', 1, 1 ),
			),
			self::points( $this->statistics->series( self::MINE, $this->periods( '2026-07-01', '2026-08-09', SalesInterval::Month ) ) )
		);
	}

	public function test_series_sum_up_to_the_totals(): void {
		foreach ( range( 1, 40 ) as $i ) {
			$this->row( 0 === $i % 7 ? 'refund' : 'sale', 100 + intdiv( $i, 2 ), 0 === $i % 7 ? -1 : 1, ( 0 === $i % 7 ? '-' : '' ) . $i . '.2500', sprintf( '2026-07-%02d %02d:30:00', 1 + $i % 28, $i % 24 ) );
		}

		$totals = $this->totals( '2026-07-01', '2026-07-31' )[0];

		foreach ( array( SalesInterval::Day, SalesInterval::Week, SalesInterval::Month ) as $interval ) {
			$points = $this->statistics->series( self::MINE, $this->periods( '2026-07-01', '2026-07-31', $interval ) );
			$units  = array_sum( array_map( static fn( SalesSeriesPoint $p ): int => $p->units, $points ) );
			$cents  = array_sum( array_map( static fn( SalesSeriesPoint $p ): int => (int) str_replace( '.', '', $p->net_sales ), $points ) );

			self::assertSame( $totals[4], $units, $interval->value );
			self::assertSame( (int) str_replace( '.', '', $totals[3] ), $cents, $interval->value );
		}
	}

	public function test_an_empty_period_and_other_creators_give_nothing(): void {
		$this->row( 'sale', 101, 1, '100.0000', '2026-07-10 10:00:00' );
		$this->row( 'sale', 102, 1, '100.0000', '2026-08-10 10:00:00', array( 'creator_id' => self::THEIRS ) );

		self::assertSame( array(), $this->totals( '2026-08-01', '2026-08-31' ) );
		self::assertSame( array(), $this->products( '2026-08-01', '2026-08-31' ) );
		self::assertSame( array(), $this->statistics->series( self::MINE, $this->periods( '2026-08-01', '2026-08-31', SalesInterval::Day ) ) );
		self::assertSame( array(), $this->statistics->series( self::MINE, array() ) );
		self::assertSame( array(), $this->statistics->totals( 0, ...SalesPeriods::from_dates( '2026-07-01', '2026-07-31', $this->copenhagen ) ) );
		self::assertSame( array(), $this->statistics->totals( 99, ...SalesPeriods::from_dates( '2026-07-01', '2026-07-31', $this->copenhagen ) ) );

		[ $from ] = SalesPeriods::from_dates( '2026-07-01', '2026-07-31', $this->copenhagen );
		self::assertSame( array(), $this->statistics->totals( self::MINE, $from, $from ), 'An empty interval is not queried.' );
	}

	/**
	 * Only `merchboard_sales` is read: no WooCommerce function, order table
	 * (HPOS `wc_orders`) or post table (legacy), so the order storage does not
	 * matter.
	 */
	public function test_only_the_sales_table_is_read_and_no_woocommerce_is_needed(): void {
		$this->row( 'sale', 101, 1, '100.0000', '2026-07-10 10:00:00' );

		$queries = $this->recorded(
			function (): void {
				$this->totals( '2026-07-01', '2026-07-31' );
				$this->products( '2026-07-01', '2026-07-31' );
				$this->statistics->series( self::MINE, $this->periods( '2026-07-01', '2026-07-31', SalesInterval::Day ) );
			}
		);

		self::assertFalse( function_exists( 'wc_get_order' ), 'No WooCommerce here: a lookup would fail.' );
		self::assertCount( 3, $queries );

		$prefix = $this->wpdb->prefix;

		foreach ( $queries as $query ) {
			preg_match_all( '/`?(' . preg_quote( $prefix, '/' ) . '\w+)`?/', $query, $tables );
			self::assertNotSame( array(), $tables[1], $query );
			// The ledger, its stored tax (ADR-053) and the order exclusions (ADR-068), Merch Dashboard's own tables only.
			self::assertSame( array( $this->table( Tables::SALES ), $this->table( Tables::SALES_TAX ), $this->table( Tables::ORDER_EXCLUSIONS ) ), array_values( array_unique( $tables[1] ) ), $query );
			self::assertStringNotContainsStringIgnoringCase( 'CONVERT_TZ', $query );
		}
	}

	/**
	 * Stores one ledger row of creator MINE, product 20, DKK.
	 *
	 * @param string               $type        Type.
	 * @param int                  $order_id    Order id.
	 * @param int                  $quantity    Signed quantity.
	 * @param string               $amount      Signed net amount.
	 * @param string               $occurred_at UTC time.
	 * @param array<string, mixed> $columns     Other columns.
	 */
	private function row( string $type, int $order_id, int $quantity, string $amount, string $occurred_at, array $columns = array() ): void {
		$inserted = $this->wpdb->insert(
			$this->table( Tables::SALES ),
			array_merge(
				array(
					'event_key'         => 'test:' . ( ++$this->key ),
					'creator_id'        => self::MINE,
					'order_id'          => $order_id,
					'order_item_id'     => $order_id * 10,
					'cycle'             => 1,
					'refund_id'         => 'refund' === $type ? $order_id + 9000 : 0,
					'product_id'        => 20,
					'variation_id'      => 0,
					'item_name'         => 'Line',
					'type'              => $type,
					'correction_reason' => null,
					'quantity'          => $quantity,
					'net_amount'        => $amount,
					'currency'          => 'DKK',
					'occurred_at'       => $occurred_at,
					'created_at'        => '2026-10-01 10:00:00',
				),
				$columns
			)
		);

		self::assertSame( 1, $inserted, $this->wpdb->last_error );
	}

	/**
	 * Stores the actual tax of the latest ledger row.
	 *
	 * @param int    $order_id Order of the row.
	 * @param string $tax      Signed tax.
	 * @param string $currency Currency of the tax.
	 */
	private function tax( int $order_id, string $tax, string $currency = 'DKK' ): void {
		$inserted = $this->wpdb->insert(
			$this->table( Tables::SALES_TAX ),
			array(
				'event_key'     => 'test:' . $this->key,
				'order_id'      => $order_id,
				'order_item_id' => $order_id * 10,
				'tax_amount'    => $tax,
				'currency'      => $currency,
				'created_at'    => '2026-10-01 10:00:00',
			)
		);

		self::assertSame( 1, $inserted, $this->wpdb->last_error );
	}

	/**
	 * Daily amounts incl. VAT of MINE by period index (only periods with rows).
	 *
	 * @param string $from First date.
	 * @param string $to   Last date.
	 * @return array<int, string|null>
	 */
	private function series_incl_vat( string $from, string $to ): array {
		$result = array();

		foreach ( $this->statistics->series( self::MINE, $this->periods( $from, $to, SalesInterval::Day ) ) as $point ) {
			$result[ $point->index ] = $point->net_sales_incl_vat;
		}

		return $result;
	}

	/**
	 * Product amounts incl. VAT of MINE.
	 *
	 * @param string $from First date.
	 * @param string $to   Last date.
	 * @return list<string|null>
	 */
	private function products_incl_vat( string $from, string $to ): array {
		return array_map( static fn( SalesProductTotals $p ): ?string => $p->net_sales_incl_vat, $this->statistics->products( self::MINE, ...SalesPeriods::from_dates( $from, $to, $this->copenhagen ) ) );
	}

	/**
	 * Totals of MINE for inclusive Copenhagen dates as `[currency, sales,
	 * adjustments, net_sales, units, orders]`.
	 *
	 * @param string $from First date.
	 * @param string $to   Last date.
	 * @return list<array{string, string, string, string, int, int}>
	 */
	private function totals( string $from, string $to ): array {
		return array_map(
			static fn( SalesTotals $t ): array => array( $t->currency, $t->sales, $t->adjustments, $t->net_sales, $t->units, $t->orders ),
			$this->statistics->totals( self::MINE, ...SalesPeriods::from_dates( $from, $to, $this->copenhagen ) )
		);
	}

	/**
	 * Products of MINE as `[currency, product_id, net_sales, units, item_name]`.
	 *
	 * @param string $from First date.
	 * @param string $to   Last date.
	 * @return list<array{string, int, string, int, string}>
	 */
	private function products( string $from, string $to ): array {
		return array_map(
			static fn( SalesProductTotals $p ): array => array( $p->currency, $p->product_id, $p->net_sales, $p->units, $p->item_name ),
			$this->statistics->products( self::MINE, ...SalesPeriods::from_dates( $from, $to, $this->copenhagen ) )
		);
	}

	/**
	 * Periods of inclusive Copenhagen dates.
	 *
	 * @param string        $from     First date.
	 * @param string        $to       Last date.
	 * @param SalesInterval $interval Interval.
	 * @return list<\MerchBoard\Modules\WooCommerce\Sales\SalesPeriod>
	 */
	private function periods( string $from, string $to, SalesInterval $interval ): array {
		[ $start, $end ] = SalesPeriods::from_dates( $from, $to, $this->copenhagen );

		return SalesPeriods::between( $start, $end, $interval, $this->copenhagen );
	}

	/**
	 * `[index, currency, net_sales, units, orders]` per point.
	 *
	 * @param SalesSeriesPoint[] $points Points.
	 * @return list<array{int, string, string, int, int}>
	 */
	private static function points( array $points ): array {
		return array_values( array_map( static fn( SalesSeriesPoint $p ): array => array( $p->index, $p->currency, $p->net_sales, $p->units, $p->orders ), $points ) );
	}

	/**
	 * The queries a callback sends.
	 *
	 * @param callable $callback Callback.
	 * @return list<string>
	 */
	private function recorded( callable $callback ): array {
		$queries = array();
		$record  = static function ( string $query ) use ( &$queries ): string {
			$queries[] = $query;

			return $query;
		};

		add_filter( 'query', $record );

		try {
			$callback();
		} finally {
			remove_filter( 'query', $record );
		}

		return $queries;
	}
}
