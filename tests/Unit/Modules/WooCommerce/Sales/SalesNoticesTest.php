<?php
/**
 * Unit tests for the notifications about new ledger rows.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntry;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntryType;
use MerchBoard\Modules\WooCommerce\Sales\SalesLedgerWrite;
use MerchBoard\Modules\WooCommerce\Sales\SalesNotices;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SalesNotices::class )]
final class SalesNoticesTest extends TestCase {

	public function test_a_sale_and_its_refund_are_told_incl_vat(): void {
		Functions\when( 'wc_get_order' )->justReturn( false );

		$sent = array();
		Actions\expectDone( 'merchboard_notify' )->twice()->whenHappen(
			static function ( string $type, int $creator, array $data ) use ( &$sent ): void {
				$sent[ $type ] = $data;
			}
		);

		$write = new SalesLedgerWrite(
			array(
				self::entry( 's:501:1', 501, SalesEntryType::Sale, 1, '264.0000' ),
				self::entry( 's:502:1', 502, SalesEntryType::Sale, 2, '100.0000' ),
				self::entry( 'r:501:9', 501, SalesEntryType::Refund, -1, '-264.0000', 9 ),
			),
			array(),
			array(
				's:501:1' => SalesAmount::parse( '66.0000' ),
				'r:501:9' => SalesAmount::parse( '-66.0000' ),
			)
		);

		SalesNotices::send( $write );

		// The owner's order #31506: 264.00 + 66.00 VAT is 330.00, as the customer paid it (2026-11-04).
		self::assertSame( '330.0000', $sent['sale_new']['items'][0]['amount'] );
		self::assertSame( '100.0000', $sent['sale_new']['items'][1]['amount'], 'A line from before the tax was recorded counts its net.' );
		self::assertSame( '430.0000', $sent['sale_new']['total'] );
		self::assertSame( '-330.0000', $sent['sale_reversed']['amount'] );
	}

	/**
	 * A ledger row of order 900 for creator 7.
	 *
	 * @param string         $key      Event key.
	 * @param int            $item     Order item.
	 * @param SalesEntryType $type     Type.
	 * @param int            $quantity Quantity.
	 * @param string         $net      Net amount.
	 * @param int            $refund   Refund id.
	 */
	private static function entry( string $key, int $item, SalesEntryType $type, int $quantity, string $net, int $refund = 0 ): SalesEntry {
		return new SalesEntry( $key, 7, 900, $item, 1, $refund, 20, 0, 'Tee', $type, null, $quantity, $net, 'DKK', new DateTimeImmutable( '2026-11-04 12:00:00', new DateTimeZone( 'UTC' ) ) );
	}
}
