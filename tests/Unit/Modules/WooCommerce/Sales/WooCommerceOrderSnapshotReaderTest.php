<?php
/**
 * Tests for reading WooCommerce orders into planner snapshots.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatuses;
use MerchBoard\Modules\WooCommerce\Sales\OrderLineSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\RefundSnapshot;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\WooCommerceOrderSnapshotReader;
use MerchBoard\Tests\Unit\Support\FakeProductStore;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\Support\WcOrderStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[CoversClass( WooCommerceOrderSnapshotReader::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WooCommerceOrderSnapshotReaderTest extends TestCase {

	private WooCommerceOrderSnapshotReader $reader;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 4 ) . '/Fixtures/woocommerce-order.php';
		WcOrderStorage::reset();

		$products = new FakeProductStore();
		$products->add_linked( 20, 3 );
		$products->add( 21 );
		$db   = new RecordingWpdb();
		$link = new ProductCreatorLink(
			$products,
			new CreatorRepository( $db ),
			new AuditTrail( new AuditRepository( $db ) ),
			new Transaction( $db, new TransactionSupport( $db ) ),
			new ProtectedProductMeta( new RecordingLogger() )
		);

		$this->reader = new WooCommerceOrderSnapshotReader( $link, new CountedOrderStatuses( static fn(): array => CountedOrderStatuses::DEFAULT ) );
	}

	public function test_reads_status_currency_dates_and_lines_with_the_edit_context(): void {
		WcOrderStorage::add_order(
			7,
			'processing',
			array(
				WcOrderStorage::item( 11, 20, 0, 'T-shirt', 2, '100.50' ),
				WcOrderStorage::item( 12, 21, 0, 'Mug', 1, '10' ),
				WcOrderStorage::item( 13, 20, 25, 'Hoodie – L', 1, 40 ),
			),
			'2026-09-01T10:05:00+02:00'
		);

		$snapshot = $this->reader->read( 7 );

		self::assertNotNull( $snapshot );
		self::assertTrue( $snapshot->exists );
		self::assertTrue( $snapshot->is_counted );
		self::assertSame( 'DKK', $snapshot->currency );
		self::assertSame( '2026-09-01 08:00:00 UTC', $snapshot->date_created?->format( 'Y-m-d H:i:s T' ) );
		self::assertSame( '2026-09-01 08:05:00', $snapshot->date_paid?->format( 'Y-m-d H:i:s' ) );

		$lines = $snapshot->lines_by_item();
		self::assertSame( array( 11, 20, 0, 'T-shirt', 2, SalesAmount::parse( '100.50' ), 3 ), self::line( $lines[11] ) );
		self::assertSame( array( 12, 21, 0, 'Mug', 1, SalesAmount::parse( '10' ), null ), self::line( $lines[12] ), 'No creator link.' );
		self::assertSame( array( 13, 20, 25, 'Hoodie – L', 1, SalesAmount::parse( '40' ), 3 ), self::line( $lines[13] ), 'A variation line gets its parent product\'s creator.' );
	}

	public function test_refunds_are_queried_directly_and_the_deleted_refund_is_left_out(): void {
		WcOrderStorage::add_order( 7, 'completed', array( WcOrderStorage::item( 11, 20, 0, 'T-shirt', 2, '100' ) ) );
		WcOrderStorage::add_refund( 100, 7, array( WcOrderStorage::item( 900, 20, 0, 'T-shirt', -1, '-50.25', array( '_refunded_item_id' => '11' ) ) ) );
		WcOrderStorage::add_refund( 101, 7, array( WcOrderStorage::item( 901, 20, 0, 'T-shirt', -1, '-10' ) ) );
		WcOrderStorage::add_refund( 102, 8, array( WcOrderStorage::item( 902, 20, 0, 'Other', -1, '-1', array( '_refunded_item_id' => '99' ) ) ) );

		$snapshot = $this->reader->read( 7, 101 );

		self::assertNotNull( $snapshot );
		self::assertSame(
			array(
				array(
					'type'   => 'shop_order_refund',
					'parent' => 7,
					'limit'  => -1,
				),
			),
			WcOrderStorage::$queries,
			'One direct query; get_refunds() (which throws in the stand-in) is never used.'
		);
		self::assertSame( array( 100, 101 ), array_map( static fn( RefundSnapshot $r ): int => $r->refund_id, $snapshot->refunds ) );
		self::assertSame( 101, $snapshot->deleted_refund_id );
		self::assertSame( array( 100 ), array_keys( $snapshot->current_refunds() ), 'The known deleted refund is absent.' );

		$line = $snapshot->refunds[0]->lines[0];
		self::assertSame( array( 11, -1, SalesAmount::parse( '-50.25' ) ), array( $line->refunded_item_id, $line->quantity, $line->net ) );
		self::assertSame( 0, $snapshot->refunds[1]->lines[0]->refunded_item_id, 'A missing _refunded_item_id becomes 0 (unresolved_refund_item in the planner).' );
		self::assertSame( '2026-09-02 09:00:00', $snapshot->refunds[0]->date_created->format( 'Y-m-d H:i:s' ) );
	}

	public function test_only_synchronizable_orders_are_read(): void {
		WcOrderStorage::add_order( 7, 'pending' );
		WcOrderStorage::add_order( 8, 'trash' );
		WcOrderStorage::add_order( 9, 'active', array(), null, 'shop_subscription' );
		WcOrderStorage::add_refund( 100, 7 );

		self::assertFalse( $this->reader->counts( 7 ) );
		self::assertNotNull( $this->reader->read( 7 ) );

		foreach ( array( 8, 9, 100, 404, 0, -1 ) as $id ) {
			self::assertNull( $this->reader->counts( $id ), 'counts ' . $id );
			self::assertNull( $this->reader->read( $id ), 'read ' . $id );
		}
	}

	public function test_unsupported_values_are_rejected_not_guessed(): void {
		WcOrderStorage::add_order( 7, 'completed', array( WcOrderStorage::item( 11, 20, 0, 'T-shirt', 2, 99.5 ) ) );
		WcOrderStorage::add_order( 8, 'completed', array( WcOrderStorage::item( 12, 20, 0, 'T-shirt', 1.5, '10' ) ) );
		WcOrderStorage::add_order( 9, 'completed', array( WcOrderStorage::item( 13, 20, 0, 'T-shirt', 1, '' ) ) );
		$rejected = 0;

		foreach ( array( 7, 8, 9 ) as $id ) {
			try {
				$this->reader->read( $id );
			} catch ( InvalidArgumentException ) {
				++$rejected;
			}
		}

		self::assertSame( 3, $rejected, 'Float total, fractional quantity and empty total.' );
	}

	/**
	 * A line as a list.
	 *
	 * @param OrderLineSnapshot $line Line.
	 * @return list<mixed>
	 */
	private static function line( OrderLineSnapshot $line ): array {
		return array( $line->item_id, $line->product_id, $line->variation_id, $line->name, $line->quantity, $line->net, $line->creator_id );
	}
}
