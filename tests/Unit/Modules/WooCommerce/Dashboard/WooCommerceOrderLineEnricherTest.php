<?php
/**
 * Tests for the order line enrichment through WooCommerce CRUD.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Dashboard;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Modules\WooCommerce\Dashboard\OrderLineView;
use MerchBoard\Modules\WooCommerce\Dashboard\PlainText;
use MerchBoard\Modules\WooCommerce\Dashboard\WooCommerceOrderLineEnricher;
use MerchBoard\Modules\WooCommerce\Sales\SalesOrderLine;
use MerchBoard\Tests\Unit\Support\WcOrderStorage;
use MerchBoard\Tests\Unit\Support\WcProductStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Every test runs in its own process: the WooCommerce stand-ins define global
 * classes and functions.
 */
#[CoversClass( WooCommerceOrderLineEnricher::class )]
#[CoversClass( OrderLineView::class )]
#[CoversClass( PlainText::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WooCommerceOrderLineEnricherTest extends TestCase {

	/**
	 * The only keys a line may have (ADR-039 P3).
	 */
	private const KEYS = array( 'line_id', 'order_number', 'date', 'status', 'status_label', 'product_name', 'quantity', 'refunded', 'counted' );

	private WooCommerceOrderLineEnricher $enricher;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 4 ) . '/Fixtures/woocommerce-order.php';
		require_once dirname( __DIR__, 4 ) . '/Fixtures/woocommerce-product.php';

		WcOrderStorage::reset();
		WcProductStorage::$products = array();
		WcProductStorage::$meta     = array();
		WcProductStorage::$lookups  = array();

		Functions\when( 'wc_get_order_status_name' )->alias(
			static fn( string $status ): string => array(
				'processing' => 'Processing',
				'completed'  => 'Completed',
				'cancelled'  => 'Cancelled',
				'refunded'   => 'Refunded',
				'on-hold'    => 'On <em>hold</em>',
			)[ str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status ] ?? $status
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $text ): string => trim( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text ) ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Stands in for wp_strip_all_tags() itself.
		);
		Functions\when( '__' )->returnArg();
		// Only CRUD lookups: no other WooCommerce query.
		Functions\expect( 'wc_get_orders' )->never();

		$this->enricher = new WooCommerceOrderLineEnricher();
	}

	public function test_an_existing_order_has_its_status_label_and_order_number(): void {
		WcOrderStorage::add_order( 41, 'processing' );
		WcOrderStorage::$orders[41]['number'] = 'A-1041';
		WcProductStorage::add( 20, array(), 'simple', 0, 'Tour tee' );

		self::assertSame(
			array(
				'line_id'      => 4101,
				'order_number' => 'A-1041',
				'date'         => '2026-08-15T08:30:00Z',
				'status'       => 'processing',
				'status_label' => 'Processing',
				'product_name' => 'Tour tee',
				'quantity'     => 2,
				'refunded'     => true,
				'counted'      => true,
			),
			$this->one( self::line( 41, 4101 ) )
		);
	}

	public function test_without_a_custom_number_the_order_number_is_the_order_id(): void {
		WcOrderStorage::add_order( 41, 'completed' );

		$line = $this->one( self::line( 41, 4101 ) );

		self::assertSame( array( '41', 'completed', 'Completed' ), array( $line['order_number'], $line['status'], $line['status_label'] ) );
	}

	public function test_a_status_with_the_wc_prefix_and_a_label_with_tags_are_cleaned(): void {
		WcOrderStorage::add_order( 41, 'wc-on-hold' );

		$line = $this->one( self::line( 41, 4101 ) );

		self::assertSame( array( 'on-hold', 'On hold' ), array( $line['status'], $line['status_label'] ) );
	}

	public function test_a_trashed_order_is_trashed_with_its_order_number(): void {
		WcOrderStorage::add_order( 41, 'trash' );
		WcOrderStorage::$orders[41]['number'] = 'A-1041';

		$line = $this->one( self::line( 41, 4101 ) );

		self::assertSame( array( 'A-1041', 'trashed', 'Trashed' ), array( $line['order_number'], $line['status'], $line['status_label'] ) );
	}

	public function test_a_deleted_order_is_kept_as_deleted_with_the_order_id_as_number(): void {
		$line = $this->one( self::line( 41, 4101 ) );

		self::assertSame( array( '41', 'deleted', 'Deleted' ), array( $line['order_number'], $line['status'], $line['status_label'] ) );
		self::assertSame( 4101, $line['line_id'] );
		self::assertSame( 2, $line['quantity'] );
	}

	public function test_a_refund_id_is_not_an_order(): void {
		WcOrderStorage::add_refund( 41, 40 );

		self::assertSame( 'deleted', $this->one( self::line( 41, 4101 ) )['status'] );
	}

	public function test_each_order_and_product_is_looked_up_once_and_the_order_of_lines_is_kept(): void {
		WcOrderStorage::add_order( 41, 'processing' );
		WcOrderStorage::add_order( 42, 'completed' );
		WcProductStorage::add( 20, array(), 'simple', 0, 'Tour tee' );

		$views = $this->enricher->enrich(
			array(
				self::line( 41, 4101 ),
				self::line( 42, 4201 ),
				self::line( 41, 4102 ),
				self::line( 43, 4301 ),
				self::line( 42, 4202 ),
				self::line( 43, 4302 ),
			)
		);

		self::assertSame( array( 4101, 4201, 4102, 4301, 4202, 4302 ), array_map( static fn( OrderLineView $view ): int => $view->line_id, $views ) );
		self::assertSame( array( 41, 42, 43 ), WcOrderStorage::$lookups, 'One wc_get_order() per order id.' );
		self::assertSame( array( 20 ), WcProductStorage::$lookups, 'One wc_get_product() per product id.' );
	}

	public function test_lines_of_a_mixed_creator_order_share_the_order_data_but_nothing_is_added(): void {
		WcOrderStorage::add_order(
			41,
			'processing',
			array(
				WcOrderStorage::item( 4101, 20, 0, 'Mine', 1, '50' ),
				WcOrderStorage::item( 4102, 30, 0, 'Another creator\'s hoodie', 3, '900' ),
			)
		);
		WcProductStorage::add( 20, array(), 'simple', 0, 'Mine' );

		$views = $this->enricher->enrich( array( self::line( 41, 4101 ) ) );
		$json  = (string) wp_json_encode( array_map( static fn( OrderLineView $view ): array => $view->to_array(), $views ) );

		self::assertCount( 1, $views );
		self::assertSame( 4101, $views[0]->line_id );
		self::assertStringNotContainsString( 'hoodie', $json );
		self::assertStringNotContainsString( '4102', $json );
	}

	public function test_the_current_simple_product_name_is_used(): void {
		WcProductStorage::add( 20, array(), 'simple', 0, 'New name' );

		self::assertSame( 'New name', $this->one( self::line( 41, 4101, 20, 0, 'Old snapshot' ) )['product_name'] );
	}

	public function test_the_current_variation_name_is_used(): void {
		WcProductStorage::add( 20, array(), 'variable', 0, 'Hoodie' );
		WcProductStorage::add( 21, array(), 'variation', 20, 'Hoodie - L' );

		self::assertSame( 'Hoodie - L', $this->one( self::line( 41, 4101, 20, 21, 'Hoodie - M' ) )['product_name'] );
		self::assertSame( array( 21 ), WcProductStorage::$lookups, 'The parent is not looked up.' );
	}

	public function test_a_deleted_product_falls_back_to_the_ledger_snapshot(): void {
		self::assertSame( 'Old snapshot', $this->one( self::line( 41, 4101, 20, 0, 'Old snapshot' ) )['product_name'] );
	}

	public function test_a_deleted_variation_falls_back_to_the_snapshot_never_to_its_parent(): void {
		WcProductStorage::add( 20, array(), 'variable', 0, 'Hoodie' );

		self::assertSame( 'Hoodie - M', $this->one( self::line( 41, 4101, 20, 21, 'Hoodie - M' ) )['product_name'] );
	}

	public function test_names_are_plain_text(): void {
		WcOrderStorage::add_order( 41, 'processing' );
		WcOrderStorage::$orders[41]['number'] = '<b>A</b>-1041';
		WcProductStorage::add( 20, array(), 'simple', 0, '<b>Tour</b> tee &amp; <script>alert(1)</script>cap' );

		$line = $this->one( self::line( 41, 4101 ) );

		self::assertSame( 'Tour tee & cap', $line['product_name'] );
		self::assertSame( 'A-1041', $line['order_number'] );
		self::assertSame( 'Snap & shot', $this->one( self::line( 42, 4201, 99, 0, 'Snap &amp; <i>shot</i>' ) )['product_name'] );
	}

	public function test_the_response_has_only_the_allowed_fields_and_no_customer_data(): void {
		WcOrderStorage::add_order( 41, 'processing', array( WcOrderStorage::item( 4101, 20, 0, 'Tee', 2, '100' ) ), '2026-09-01T10:05:00+02:00' );
		WcOrderStorage::$orders[41]['billing'] = array(
			'first_name' => 'Karen',
			'last_name'  => 'Kunde',
			'email'      => 'karen@example.org',
			'phone'      => '+45 12345678',
			'address_1'  => 'Hovedgaden 1',
			'city'       => 'Aarhus',
			'postcode'   => '8000',
			'country'    => 'DK',
		);
		WcProductStorage::add( 20, array(), 'simple', 0, 'Tee' );

		$line = $this->one( self::line( 41, 4101 ) );
		$json = (string) wp_json_encode( $line );

		self::assertSame( self::KEYS, array_keys( $line ) );

		foreach ( array( 'Karen', 'Kunde', 'karen@example.org', '12345678', 'Hovedgaden', 'Aarhus', '8000', '"DK"', '100', 'DKK', 'creator', 'product_id', 'variation_id', 'order_id', 'customer', 'email', 'country', 'total', 'amount' ) as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $json );
		}
	}

	/**
	 * The REST representation of a single enriched line.
	 *
	 * @param SalesOrderLine $line Line.
	 * @return array<string, mixed>
	 */
	private function one( SalesOrderLine $line ): array {
		$views = $this->enricher->enrich( array( $line ) );
		self::assertCount( 1, $views );

		return $views[0]->to_array();
	}

	/**
	 * A ledger order line.
	 *
	 * @param int    $order_id     Order id.
	 * @param int    $item_id      Order item id.
	 * @param int    $product_id   Product id.
	 * @param int    $variation_id Variation id.
	 * @param string $item_name    Item name snapshot.
	 */
	private static function line( int $order_id, int $item_id, int $product_id = 20, int $variation_id = 0, string $item_name = 'Snapshot' ): SalesOrderLine {
		return new SalesOrderLine( $order_id, $item_id, new DateTimeImmutable( '2026-08-15 08:30:00', new DateTimeZone( 'UTC' ) ), 2, true, true, $product_id, $variation_id, $item_name );
	}
}
