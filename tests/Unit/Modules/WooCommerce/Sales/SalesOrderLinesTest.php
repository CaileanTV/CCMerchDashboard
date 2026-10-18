<?php
/**
 * Tests for the creator's order lines read model (query binding and row
 * validation; the SQL itself runs in tests/Integration).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Http\Pagination;
use MerchBoard\Modules\WooCommerce\Sales\SalesOrderLine;
use MerchBoard\Modules\WooCommerce\Sales\SalesOrderLines;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( SalesOrderLines::class )]
#[CoversClass( SalesOrderLine::class )]
final class SalesOrderLinesTest extends TestCase {

	private RecordingWpdb $wpdb;

	private SalesOrderLines $lines;

	protected function setUp(): void {
		parent::setUp();
		// get_results() returns the same rows for the page and the cycles query,
		// so the test rows carry the cycles column (open_cycles) too.
		$this->wpdb  = new RecordingWpdb();
		$this->lines = new SalesOrderLines( $this->wpdb );
		// No WooCommerce function is mocked here: in this shared process a mock
		// would define it for later tests. That only the ledger is read is
		// shown by tests/Integration/WooCommerce/SalesOrderLinesTest.
	}

	public function test_the_creator_id_is_only_the_argument_and_bound_as_an_integer(): void {
		$this->wpdb->var = '7';

		self::assertSame( 7, $this->lines->count( 3 ) );
		$this->lines->page( 3, new Pagination( 2, 25 ) );

		self::assertSame( array( 'wp_merchboard_sales', 'wp_merchboard_order_exclusions', 3, 'wp_merchboard_sales' ), $this->wpdb->prepared[0]['args'] );
		self::assertSame( array( 'wp_merchboard_sales', 'wp_merchboard_sales', 'wp_merchboard_sales', 'wp_merchboard_order_exclusions', 3, 'wp_merchboard_sales', 25, 25 ), $this->wpdb->prepared[1]['args'] );
		self::assertStringContainsString( "WHERE NOT EXISTS ( SELECT 1 FROM %i xo WHERE xo.order_id = sales.order_id AND xo.status IN ( 'excluded', 'open' ) ) AND creator_id = %d", $this->wpdb->prepared[1]['query'] );
		self::assertStringContainsString( 'ORDER BY s.occurred_at DESC, l.order_item_id DESC LIMIT %d OFFSET %d', $this->wpdb->prepared[1]['query'] );
	}

	public function test_no_creator_reads_nothing(): void {
		self::assertSame( 0, $this->lines->count( 0 ) );
		self::assertSame( array(), $this->lines->page( -1, new Pagination( 1, 20 ) ) );
		self::assertSame( array(), $this->wpdb->prepared );
	}

	public function test_rows_become_order_lines_with_utc_dates(): void {
		$this->wpdb->results = array( self::row() );

		$line = $this->lines->page( 3, new Pagination( 1, 20 ) )[0];

		self::assertSame( array( 41, 4101, 2, true, true, 20, 0, 'Tee' ), array( $line->order_id, $line->order_item_id, $line->quantity, $line->refunded, $line->counted, $line->product_id, $line->variation_id, $line->item_name ) );
		self::assertSame( '2026-08-15T08:30:00+00:00', $line->date->format( 'c' ) );
		self::assertStringContainsString( 'WHERE order_id IN ( %d )', $this->wpdb->prepared[1]['query'] );
		self::assertSame( array( 'wp_merchboard_sales_cycles', 41 ), $this->wpdb->prepared[1]['args'] );
	}

	/**
	 * Rows that do not match the schema.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function broken_rows(): array {
		return array(
			'quantity not a number' => array( array( 'quantity' => 'x' ) ),
			'missing order id'      => array( array( 'order_id' => null ) ),
			'empty date'            => array( array( 'occurred_at' => '' ) ),
			'not a datetime'        => array( array( 'occurred_at' => 'yesterday' ) ),
			'cycles not a number'   => array( array( 'open_cycles' => null ) ),
		);
	}

	/**
	 * A row that does not match the schema fails the page.
	 *
	 * @param array<string, mixed> $override Changed columns.
	 */
	#[DataProvider( 'broken_rows' )]
	public function test_a_broken_row_is_a_database_exception( array $override ): void {
		$this->wpdb->results = array( array_merge( self::row(), $override ) );

		$this->expectException( DatabaseException::class );
		$this->lines->page( 3, new Pagination( 1, 20 ) );
	}

	public function test_a_failed_query_is_a_database_exception(): void {
		$this->wpdb->last_error = 'Table is gone';

		$this->expectException( DatabaseException::class );
		$this->lines->count( 3 );
	}

	/**
	 * A valid result row.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(): array {
		return array(
			'order_id'      => '41',
			'order_item_id' => '4101',
			'quantity'      => '2',
			'occurred_at'   => '2026-08-15 08:30:00',
			'product_id'    => '20',
			'variation_id'  => '0',
			'item_name'     => 'Tee',
			'refunded'      => '1',
			'open_cycles'   => '1',
		);
	}
}
