<?php
/**
 * Tests for the diagnosis of a ledger row's profit (ADR-051).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Pricing;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupSource;
use MerchBoard\Modules\WooCommerce\Pricing\ProfitDiagnosis;
use MerchBoard\Modules\WooCommerce\Pricing\ProfitGap;
use MerchBoard\Modules\WooCommerce\Pricing\RealizedProfit;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricing;
use MerchBoard\Modules\WooCommerce\Pricing\SnapshotStatus;
use MerchBoard\Modules\WooCommerce\Sales\OrderHistoryFilter;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntry;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntryType;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ProfitDiagnosis::class )]
#[CoversClass( OrderHistoryFilter::class )]
final class ProfitDiagnosisTest extends TestCase {

	private const TAX = array(
		'tax'      => 200000,
		'currency' => 'DKK',
	);

	public function test_a_known_profit_comes_from_realized_profit_without_a_gap(): void {
		$diagnosis = ProfitDiagnosis::of( self::entry(), self::snapshot(), self::TAX );

		self::assertSame( 200000, $diagnosis->profit );
		self::assertSame( RealizedProfit::for_entry( self::entry(), self::snapshot(), self::TAX ), $diagnosis->profit );
		self::assertNull( $diagnosis->gap );
	}

	public function test_each_missing_piece_has_its_own_reason_and_never_a_profit(): void {
		$legacy = new SalesLinePricing( 501, 900, 7, 20, 0, SnapshotStatus::Priced, 'DKK', self::time(), null, null, null, false );
		$cases  = array(
			array( null, self::TAX, false, ProfitGap::NoSnapshot ),
			array( null, self::TAX, true, ProfitGap::InvalidSnapshot ),
			array( $legacy, self::TAX, false, ProfitGap::LegacySnapshot ),
			array( self::snapshot( creator: 8 ), self::TAX, false, ProfitGap::SnapshotMismatch ),
			array( self::snapshot( variation: 31 ), self::TAX, false, ProfitGap::SnapshotMismatch ),
			array( self::snapshot(), null, false, ProfitGap::NoTax ),
			array(
				self::snapshot(),
				array(
					'tax'      => 1,
					'currency' => 'EUR',
				),
				false,
				ProfitGap::TaxCurrency,
			),
		);

		foreach ( $cases as [ $snapshot, $tax, $invalid, $gap ] ) {
			$diagnosis = ProfitDiagnosis::of( self::entry(), $snapshot, $tax, $invalid );

			self::assertNull( $diagnosis->profit, $gap->value );
			self::assertSame( $gap, $diagnosis->gap );
		}
	}

	public function test_the_snapshot_is_checked_before_the_tax(): void {
		self::assertSame( ProfitGap::NoSnapshot, ProfitDiagnosis::of( self::entry(), null, null )->gap );
	}

	public function test_the_order_history_filter_accepts_only_valid_values(): void {
		self::assertSame( 'incomplete', ( new OrderHistoryFilter( 1, 2, 'incomplete' ) )->status );
		self::assertSame( OrderHistoryFilter::WITH_PROFIT_DATA, ( new OrderHistoryFilter( null, null, 'with_data' ) )->status );

		foreach ( array( array( 0, null, null ), array( null, -1, null ), array( null, null, 'partial' ) ) as [ $order, $creator, $status ] ) {
			try {
				new OrderHistoryFilter( $order, $creator, $status );
				self::fail( 'Expected an exception.' );
			} catch ( InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	/**
	 * A share snapshot of line 501 (20 %, creator 7, DKK).
	 *
	 * @param int $creator   Creator.
	 * @param int $variation Variation.
	 */
	private static function snapshot( int $creator = 7, int $variation = 0 ): SalesLinePricing {
		return new SalesLinePricing( 501, 900, $creator, 20, $variation, SnapshotStatus::Share, 'DKK', self::time(), 2000, MarkupSource::Creator, 2, false );
	}

	/**
	 * A sale row of line 501: 80.00 net (100.00 incl. 20.00 tax).
	 */
	private static function entry(): SalesEntry {
		return new SalesEntry( 's:501:1', 7, 900, 501, 1, 0, 20, 0, 'Tee', SalesEntryType::Sale, null, 1, '80.0000', 'DKK', self::time() );
	}

	/**
	 * Fixed time.
	 */
	private static function time(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-10-09 12:00:00', new DateTimeZone( 'UTC' ) );
	}
}
