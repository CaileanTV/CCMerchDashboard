<?php
/**
 * Tests for reading a paid WooCommerce order for alerts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Alerts;

use Brain\Monkey\Functions;
use MerchBoard\Modules\Alerts\Checkout\CheckoutUsername;
use MerchBoard\Modules\Alerts\PaidOrderLine;
use MerchBoard\Modules\Alerts\WooCommercePaidOrderReader;
use MerchBoard\Tests\Unit\Support\WcOrderStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[CoversClass( WooCommercePaidOrderReader::class )]
#[CoversClass( CheckoutUsername::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WooCommercePaidOrderReaderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/Fixtures/woocommerce-order.php';
		WcOrderStorage::reset();

		$images = array(
			10 => 501,
			11 => 0,
			20 => false,
			21 => 502,
		);
		Functions\when( 'get_post_thumbnail_id' )->alias( static fn( int $id ): int|false => $images[ $id ] ?? false );
	}

	public function test_reads_the_order_with_edit_context_and_skips_lines_without_product_or_quantity(): void {
		WcOrderStorage::add_order(
			7,
			'processing',
			array(
				WcOrderStorage::item( 701, 10, 0, 'Hoodie', 2, '200.00' ),
				WcOrderStorage::item( 702, 20, 21, 'Shirt - <b>L</b>', '1', '100.00' ),
				WcOrderStorage::item( 703, 0, 0, 'Deleted product', 1, '50.00' ),
				WcOrderStorage::item( 704, 10, 0, 'Removed', 0, '0.00' ),
			)
		);
		$stored                       = WcOrderStorage::$orders[7];
		$stored['number']             = 'CC-1007';
		$stored['billing_first_name'] = ' Anna ';
		WcOrderStorage::$orders[7]    = $stored;

		$order = ( new WooCommercePaidOrderReader() )->read( 7 );

		self::assertNotNull( $order );
		self::assertSame( 7, $order->order_id );
		self::assertSame( 'CC-1007', $order->order_number );
		self::assertSame( '', $order->username );
		self::assertEquals(
			array(
				new PaidOrderLine( 701, 10, 0, 'Hoodie', '', 2, 501 ),
				new PaidOrderLine( 702, 20, 21, 'Shirt - L', '', 1, 502 ),
			),
			$order->lines,
			'The variation image wins; tags are stripped; lines without a product or quantity are skipped.'
		);
	}

	public function test_the_checkout_username_wins_over_merchalerts(): void {
		WcOrderStorage::add_order( 7, 'completed' );
		WcOrderStorage::$meta[7] = array(
			WooCommercePaidOrderReader::USERNAME_META => 'nova_fan',
			WooCommercePaidOrderReader::LEGACY_USERNAME_META => 'old_name',
		);
		self::assertSame( 'nova_fan', ( new WooCommercePaidOrderReader() )->read( 7 )?->username );

		WcOrderStorage::$meta[7] = array( WooCommercePaidOrderReader::LEGACY_USERNAME_META => 'old_name' );
		self::assertSame( 'old_name', ( new WooCommercePaidOrderReader() )->read( 7 )->username, 'MerchAlert orders keep their username (ADR-065 pkt. 5).' );

		WcOrderStorage::$meta[7] = array( WooCommercePaidOrderReader::USERNAME_META => '   ' );
		self::assertSame( '', ( new WooCommercePaidOrderReader() )->read( 7 )->username );
	}

	public function test_the_block_checkout_field_is_read_only_with_its_box_ticked_cleaned_and_cut(): void {
		WcOrderStorage::add_order( 7, 'completed' );
		WcOrderStorage::$meta[7] = array(
			CheckoutUsername::BLOCK_META => 'Nova',
			WooCommercePaidOrderReader::LEGACY_USERNAME_META => 'old_name',
		);
		self::assertSame( 'old_name', ( new WooCommercePaidOrderReader() )->read( 7 )?->username, 'Not without the box ticked.' );

		WcOrderStorage::$meta[7] = array(
			CheckoutUsername::BLOCK_SHOW_META => '0',
			CheckoutUsername::BLOCK_META      => 'Nova',
		);
		self::assertSame( '', ( new WooCommercePaidOrderReader() )->read( 7 )->username, 'Not with the box left empty.' );

		WcOrderStorage::$meta[7] = array(
			CheckoutUsername::BLOCK_SHOW_META => '1',
			CheckoutUsername::BLOCK_META      => " <b>Nova</b>\n  Fan ",
			WooCommercePaidOrderReader::LEGACY_USERNAME_META => 'old_name',
		);
		self::assertSame( 'Nova Fan', ( new WooCommercePaidOrderReader() )->read( 7 )->username, 'Before MerchAlert\'s; one plain line.' );

		WcOrderStorage::$meta[7] = array(
			WooCommercePaidOrderReader::USERNAME_META => 'classic',
			CheckoutUsername::BLOCK_SHOW_META         => '1',
			CheckoutUsername::BLOCK_META              => 'block',
		);
		self::assertSame( 'classic', ( new WooCommercePaidOrderReader() )->read( 7 )->username );

		WcOrderStorage::$meta[7] = array( WooCommercePaidOrderReader::LEGACY_USERNAME_META => str_repeat( 'æ', 60 ) );
		self::assertSame( str_repeat( 'æ', CheckoutUsername::MAX_LENGTH ), ( new WooCommercePaidOrderReader() )->read( 7 )->username, 'At most 50 characters.' );
	}

	public function test_refunds_unknown_orders_and_other_types_are_not_read(): void {
		WcOrderStorage::add_order( 7, 'processing' );
		WcOrderStorage::add_refund( 8, 7 );
		WcOrderStorage::add_order( 9, 'active', array(), null, 'shop_subscription' );

		$reader = new WooCommercePaidOrderReader();

		self::assertNull( $reader->read( 8 ) );
		self::assertNull( $reader->read( 9 ) );
		self::assertNull( $reader->read( 404 ) );
		self::assertNull( $reader->read( 0 ) );
		self::assertNotNull( $reader->read( 7 ) );
	}
}
