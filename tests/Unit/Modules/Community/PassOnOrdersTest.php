<?php
/**
 * Gift and giveaway orders are completed when paid.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Community;

use MerchBoard\Modules\Community\Gifting\GiftPurchase;
use MerchBoard\Modules\Community\Giveaways\GiveawayCheckout;
use MerchBoard\Modules\Community\PassOnOrders;
use MerchBoard\Tests\Unit\Support\WcOrderStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[CoversClass( PassOnOrders::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PassOnOrdersTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/Fixtures/woocommerce-order.php';
		WcOrderStorage::reset();
	}

	public function test_only_an_order_of_gift_or_giveaway_lines_is_completed_when_paid(): void {
		WcOrderStorage::add_order( 7, 'pending', array( WcOrderStorage::item( 701, 10, 0, 'Hoodie', 1, '200.00', array( GiftPurchase::LINE_META => '3' ) ) ) );
		WcOrderStorage::add_order( 8, 'pending', array( WcOrderStorage::item( 801, 10, 0, 'Hoodie', 1, '160.00', array( GiveawayCheckout::LINE_META => '12' ) ) ) );
		WcOrderStorage::add_order(
			9,
			'pending',
			array(
				WcOrderStorage::item( 901, 10, 0, 'Hoodie', 1, '200.00', array( GiftPurchase::LINE_META => '3' ) ),
				WcOrderStorage::item( 902, 11, 0, 'Mug', 1, '80.00' ),
			)
		);
		WcOrderStorage::add_order( 10, 'pending', array( WcOrderStorage::item( 1001, 11, 0, 'Mug', 1, '80.00' ) ) );
		WcOrderStorage::add_order( 11, 'pending' );

		$orders = new PassOnOrders();

		self::assertSame( 'completed', $orders->payment_status( 'processing', 7 ), 'A viewer\'s gift.' );
		self::assertSame( 'completed', $orders->payment_status( 'processing', 8 ), 'A creator\'s giveaway.' );
		self::assertSame( 'processing', $orders->payment_status( 'processing', 9 ), 'Something to ship besides.' );
		self::assertSame( 'processing', $orders->payment_status( 'processing', 10 ), 'An ordinary order.' );
		self::assertSame( 'processing', $orders->payment_status( 'processing', 11 ), 'No lines.' );
	}
}
