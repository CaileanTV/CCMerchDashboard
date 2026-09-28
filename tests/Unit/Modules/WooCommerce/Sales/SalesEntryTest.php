<?php
/**
 * Tests for the row invariants of a sales ledger entry.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\SalesCorrectionReason;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntry;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntryType;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( SalesEntry::class )]
#[CoversClass( SalesEntryType::class )]
#[CoversClass( SalesCorrectionReason::class )]
final class SalesEntryTest extends TestCase {

	public function test_valid_rows_of_every_type(): void {
		self::assertSame( SalesEntryType::Sale, self::entry()->type );

		$refund = self::entry(
			array(
				'event_key'  => 'r:10:100',
				'type'       => SalesEntryType::Refund,
				'refund_id'  => 100,
				'quantity'   => -1,
				'net_amount' => '-30.0000',
			)
		);
		self::assertSame( '-30.0000', $refund->net_amount );

		$reversal = self::entry(
			array(
				'event_key'  => 'v:10:1',
				'type'       => SalesEntryType::Reversal,
				'quantity'   => 0,
				'net_amount' => '0',
			)
		);
		self::assertSame( 0, $reversal->quantity );

		foreach ( array( '12.5', '-12.5' ) as $amount ) {
			$correction = self::entry(
				array(
					'event_key'  => 'c-l:10:1:1',
					'type'       => SalesEntryType::Correction,
					'reason'     => SalesCorrectionReason::LineChanged,
					'quantity'   => str_starts_with( $amount, '-' ) ? -1 : 1,
					'net_amount' => $amount,
				)
			);
			self::assertSame( SalesCorrectionReason::LineChanged, $correction->correction_reason, 'A correction may be positive or negative.' );
		}

		$refund_deleted = self::entry(
			array(
				'event_key'  => 'c-rd:10:100',
				'type'       => SalesEntryType::Correction,
				'reason'     => SalesCorrectionReason::RefundDeleted,
				'refund_id'  => 100,
				'quantity'   => 1,
				'net_amount' => '30',
			)
		);
		self::assertSame( 100, $refund_deleted->refund_id );
	}

	public function test_long_item_names_are_cut_to_the_column_length(): void {
		$name = str_repeat( 'æ', 200 ) . '🎸';

		self::assertSame( str_repeat( 'æ', 191 ), self::entry( array( 'item_name' => $name ) )->item_name );
	}

	/**
	 * Values that break a row invariant.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function invalid_rows(): array {
		$refund = array(
			'type'       => SalesEntryType::Refund,
			'refund_id'  => 100,
			'quantity'   => -1,
			'net_amount' => '-1',
		);

		return array(
			'empty event key'                  => array( array( 'event_key' => '' ) ),
			'event key over 64 characters'     => array( array( 'event_key' => 's:' . str_repeat( '1', 63 ) ) ),
			'event key with invalid chars'     => array( array( 'event_key' => 'S:10 1' ) ),
			'creator id 0'                     => array( array( 'creator_id' => 0 ) ),
			'order id 0'                       => array( array( 'order_id' => 0 ) ),
			'order item id 0'                  => array( array( 'order_item_id' => 0 ) ),
			'cycle 0'                          => array( array( 'cycle' => 0 ) ),
			'product id 0'                     => array( array( 'product_id' => 0 ) ),
			'negative variation'               => array( array( 'variation_id' => -1 ) ),
			'negative sale quantity'           => array( array( 'quantity' => -1 ) ),
			'negative sale amount'             => array( array( 'net_amount' => '-0.0001' ) ),
			'positive refund amount'           => array( array_merge( $refund, array( 'net_amount' => '1' ) ) ),
			'positive refund quantity'         => array( array_merge( $refund, array( 'quantity' => 1 ) ) ),
			'positive reversal quantity'       => array(
				array(
					'type'       => SalesEntryType::Reversal,
					'quantity'   => 1,
					'net_amount' => '-1',
				),
			),
			'refund without refund id'         => array( array_merge( $refund, array( 'refund_id' => 0 ) ) ),
			'sale with refund id'              => array( array( 'refund_id' => 100 ) ),
			'correction without reason'        => array( array( 'type' => SalesEntryType::Correction ) ),
			'sale with reason'                 => array( array( 'reason' => SalesCorrectionReason::LineChanged ) ),
			'line correction with refund id'   => array(
				array(
					'type'      => SalesEntryType::Correction,
					'reason'    => SalesCorrectionReason::LineRemoved,
					'refund_id' => 100,
				),
			),
			'refund correction without refund' => array(
				array(
					'type'   => SalesEntryType::Correction,
					'reason' => SalesCorrectionReason::ClosedCycleRefund,
				),
			),
			'float notation amount'            => array( array( 'net_amount' => '1e3' ) ),
			'five decimals'                    => array( array( 'net_amount' => '1.00001' ) ),
			'lower-case currency'              => array( array( 'currency' => 'dkk' ) ),
			'stored id 0'                      => array( array( 'id' => 0 ) ),
		);
	}

	/**
	 * Rejects rows that break an invariant.
	 *
	 * @param array<string, mixed> $overrides Values to change.
	 */
	#[DataProvider( 'invalid_rows' )]
	public function test_rejects_invalid_rows( array $overrides ): void {
		$this->expectException( InvalidArgumentException::class );

		self::entry( $overrides );
	}

	public function test_amount_sign_without_floats(): void {
		self::assertSame( 0, SalesEntry::amount_sign( '0' ) );
		self::assertSame( 0, SalesEntry::amount_sign( '-0.0000' ) );
		self::assertSame( 1, SalesEntry::amount_sign( '0.0001' ) );
		self::assertSame( -1, SalesEntry::amount_sign( '-123456789012345.9999' ) );
	}

	/**
	 * A valid sale with overrides.
	 *
	 * @param array<string, mixed> $o Overrides.
	 */
	private static function entry( array $o = array() ): SalesEntry {
		$type   = $o['type'] ?? SalesEntryType::Sale;
		$reason = $o['reason'] ?? null;

		self::assertInstanceOf( SalesEntryType::class, $type );
		self::assertTrue( null === $reason || $reason instanceof SalesCorrectionReason );

		return new SalesEntry(
			(string) ( $o['event_key'] ?? 's:10:1' ),
			(int) ( $o['creator_id'] ?? 3 ),
			(int) ( $o['order_id'] ?? 7 ),
			(int) ( $o['order_item_id'] ?? 10 ),
			(int) ( $o['cycle'] ?? 1 ),
			(int) ( $o['refund_id'] ?? 0 ),
			(int) ( $o['product_id'] ?? 20 ),
			(int) ( $o['variation_id'] ?? 0 ),
			(string) ( $o['item_name'] ?? 'T-shirt' ),
			$type,
			$reason,
			(int) ( $o['quantity'] ?? 2 ),
			(string) ( $o['net_amount'] ?? '100.0000' ),
			(string) ( $o['currency'] ?? 'DKK' ),
			new DateTimeImmutable( '2026-09-28 10:00:00' ),
			array_key_exists( 'id', $o ) ? (int) $o['id'] : null
		);
	}
}
