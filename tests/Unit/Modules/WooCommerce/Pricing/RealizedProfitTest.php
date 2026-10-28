<?php
/**
 * Tests for the realized creator profit and payout of stored ledger rows (ADR-049, ADR-063).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Pricing;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerPrice;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupSource;
use MerchBoard\Modules\WooCommerce\Pricing\RealizedPayout;
use MerchBoard\Modules\WooCommerce\Pricing\RealizedProfit;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricing;
use MerchBoard\Modules\WooCommerce\Pricing\SnapshotStatus;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Modules\WooCommerce\Sales\SalesCorrectionReason;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntry;
use MerchBoard\Modules\WooCommerce\Sales\SalesEntryType;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( RealizedProfit::class )]
#[CoversClass( RealizedPayout::class )]
final class RealizedProfitTest extends TestCase {

	/**
	 * The locked examples on ledger rows: net excl. VAT, tax, markup, VAT registered, profit, payout.
	 *
	 * @return array<string, array{string, string, int, bool, string, string}>
	 */
	public static function examples(): array {
		return array(
			// Sale price 100.00 incl. 25 % VAT = 80.00 + 20.00.
			'100 / 20 % / not VAT registered' => array( '80.0000', '20.0000', 2000, false, '20.0000', '16.0000' ),
			'100 / 20 % / VAT registered'     => array( '80.0000', '20.0000', 2000, true, '20.0000', '20.0000' ),
			'200 / 10 % / not VAT registered' => array( '160.0000', '40.0000', 1000, false, '20.0000', '16.0000' ),
			'200 / 10 % / VAT registered'     => array( '160.0000', '40.0000', 1000, true, '20.0000', '20.0000' ),
			'100 / 0 % / not VAT registered'  => array( '80.0000', '20.0000', 0, false, '0.0000', '0.0000' ),
			// A store without taxes: the sale price is the net amount, nothing is deducted.
			'no tax: 100 / 20 %'              => array( '100.0000', '0.0000', 2000, false, '20.0000', '20.0000' ),
			// Another tax rate: the share is of the paid amount; the deduction is the sale's own VAT (89.2857 × 20 % = 17.86).
			'12 % tax: 100 / 20 %'            => array( '89.2857', '10.7143', 2000, false, '20.0000', '17.8600' ),
		);
	}

	/**
	 * Profit = (net + tax) × share; payout = profit when VAT registered at the
	 * sale, otherwise net × share (ADR-064).
	 *
	 * @param string $net            Net amount.
	 * @param string $tax            Actual tax.
	 * @param int    $bps            Share.
	 * @param bool   $vat_registered VAT status.
	 * @param string $profit         Expected profit.
	 * @param string $payout         Expected payout.
	 */
	#[DataProvider( 'examples' )]
	public function test_profit_is_a_share_of_the_paid_sale_price( string $net, string $tax, int $bps, bool $vat_registered, string $profit, string $payout ): void {
		$snapshot = self::snapshot( bps: $bps, vat_registered: $vat_registered );
		$entry    = self::entry( SalesEntryType::Sale, 1, $net );
		$taxes    = self::tax( $tax );

		self::assertSame( $profit, SalesAmount::format( (int) RealizedProfit::for_entry( $entry, $snapshot, $taxes ) ) );
		self::assertSame( $payout, SalesAmount::format( (int) RealizedPayout::for_entry( $entry, $snapshot, $taxes ) ) );
	}

	public function test_refunds_reversals_and_corrections_have_signed_profit_and_payout(): void {
		$snapshot = self::snapshot( bps: 2000 );

		// Sale of 3 × 100.00 incl. VAT.
		self::assertSame( 600000, RealizedProfit::for_entry( self::entry( SalesEntryType::Sale, 3, '240.0000' ), $snapshot, self::tax( '60.0000' ) ), 'The amount already is the line amount.' );
		// Not VAT registered: the payout is the share of the amount excl. VAT (ADR-064).
		self::assertSame( 480000, RealizedPayout::for_entry( self::entry( SalesEntryType::Sale, 3, '240.0000' ), $snapshot, self::tax( '60.0000' ) ) );
		// Refund of 1 unit.
		self::assertSame( -200000, RealizedProfit::for_entry( self::entry( SalesEntryType::Refund, -1, '-80.0000', 7001 ), $snapshot, self::tax( '-20.0000' ) ) );
		self::assertSame( -160000, RealizedPayout::for_entry( self::entry( SalesEntryType::Refund, -1, '-80.0000', 7001 ), $snapshot, self::tax( '-20.0000' ) ) );
		// Reversal of the remaining 2 units.
		self::assertSame( -400000, RealizedProfit::for_entry( self::entry( SalesEntryType::Reversal, -2, '-160.0000' ), $snapshot, self::tax( '-40.0000' ) ) );
		self::assertSame( -320000, RealizedPayout::for_entry( self::entry( SalesEntryType::Reversal, -2, '-160.0000' ), $snapshot, self::tax( '-40.0000' ) ) );
		// A deleted refund restores its amount.
		self::assertSame( 200000, RealizedProfit::for_entry( self::entry( SalesEntryType::Correction, 0, '80.0000', 7001, SalesCorrectionReason::RefundDeleted ), $snapshot, self::tax( '20.0000' ) ) );
	}

	public function test_a_full_refund_cancels_the_sale_exactly(): void {
		$snapshot = self::snapshot( bps: 1667 );
		$sale     = RealizedPayout::for_entry( self::entry( SalesEntryType::Sale, 1, '79.9920' ), $snapshot, self::tax( '19.9980' ) );
		$refund   = RealizedPayout::for_entry( self::entry( SalesEntryType::Refund, -1, '-79.9920', 7001 ), $snapshot, self::tax( '-19.9980' ) );

		self::assertNotNull( $sale );
		self::assertSame( 0, $sale + (int) $refund );
	}

	public function test_discounts_and_coupons_reduce_the_profit_because_the_paid_amount_is_used(): void {
		// List price 100.00, coupon 25 %: the customer paid 75.00 incl. VAT.
		self::assertSame( 150000, RealizedProfit::for_entry( self::entry( SalesEntryType::Sale, 1, '60.0000' ), self::snapshot( bps: 2000 ), self::tax( '15.0000' ) ) );
	}

	public function test_variation_lines_use_their_snapshot(): void {
		$snapshot = self::snapshot( bps: 2000, variation: 31 );

		self::assertSame( 200000, RealizedProfit::for_entry( self::entry( SalesEntryType::Sale, 1, '80.0000', variation: 31 ), $snapshot, self::tax( '20.0000' ) ) );
		self::assertNull( RealizedProfit::for_entry( self::entry( SalesEntryType::Sale, 1, '80.0000', variation: 32 ), $snapshot, self::tax( '20.0000' ) ) );
	}

	public function test_history_depends_only_on_the_snapshot_and_the_row(): void {
		$entry = self::entry( SalesEntryType::Sale, 1, '80.0000' );
		$tax   = self::tax( '20.0000' );

		// The line was sold at 20 % while not VAT registered; the creator's
		// markup and status changed afterwards. Only the snapshot counts.
		self::assertSame( 160000, RealizedPayout::for_entry( $entry, self::snapshot( bps: 2000, vat_registered: false ), $tax ) );
		self::assertSame( 250000, RealizedPayout::for_entry( $entry, self::snapshot( bps: 2500, vat_registered: true ), $tax ), 'Only a line created after the change has the new values.' );
	}

	public function test_the_vat_is_deducted_only_while_not_vat_registered(): void {
		$entry = self::entry( SalesEntryType::Sale, 1, '80.0000' );
		$tax   = self::tax( '20.0000' );

		// ADR-064: incl. VAT only for a creator who was VAT registered at the sale.
		self::assertSame( 200000, RealizedPayout::for_entry( $entry, self::snapshot( vat_registered: true ), $tax ) );
		self::assertSame( 160000, RealizedPayout::for_entry( $entry, self::snapshot( vat_registered: false ), $tax ) );
		self::assertSame( 200000, RealizedProfit::for_entry( $entry, self::snapshot( vat_registered: false ), $tax ), 'The profit is unchanged.' );
	}

	public function test_unknown_cases(): void {
		$sale = self::entry( SalesEntryType::Sale, 1, '80.0000' );
		$tax  = self::tax( '20.0000' );

		self::assertNull( RealizedProfit::for_entry( $sale, null, $tax ), 'No snapshot.' );
		self::assertNull( RealizedProfit::for_entry( $sale, self::snapshot(), null ), 'No stored tax (a row from before schema version 7).' );
		self::assertNull(
			RealizedProfit::for_entry(
				$sale,
				self::snapshot(),
				array(
					'tax'      => 200000,
					'currency' => 'EUR',
				)
			),
			'Tax in another currency; never converted.'
		);
		self::assertNull( RealizedProfit::for_entry( $sale, self::snapshot( order: 901 ), $tax ), 'Other order.' );
		self::assertNull( RealizedProfit::for_entry( $sale, self::snapshot( item: 502 ), $tax ), 'Other order line.' );
		self::assertNull( RealizedProfit::for_entry( $sale, self::snapshot( creator: 8 ), $tax ), 'Other creator.' );
		self::assertNull( RealizedProfit::for_entry( $sale, self::snapshot( product: 21 ), $tax ), 'Other product.' );
		self::assertNull( RealizedProfit::for_entry( $sale, self::snapshot( currency: 'EUR' ), $tax ), 'Other currency.' );

		foreach ( array( SnapshotStatus::Priced, SnapshotStatus::Unpriced ) as $legacy ) {
			$snapshot = new SalesLinePricing( 501, 900, 7, 20, 0, $legacy, 'DKK', self::time(), null, null, null, false );

			self::assertNull( RealizedProfit::for_entry( $sale, $snapshot, $tax ), 'Withdrawn model: no share.' );
			self::assertNull( RealizedPayout::for_entry( $sale, $snapshot, $tax ) );
		}

		$no_status = self::snapshot( vat_registered: null );

		self::assertSame( 200000, RealizedProfit::for_entry( $sale, $no_status, $tax ) );
		self::assertNull( RealizedPayout::for_entry( $sale, $no_status, $tax ), 'Unknown VAT status: unknown payout.' );
	}

	/**
	 * Designer product (ADR-080 pkt. 8), base 150 + profit 50 = 200 incl. 25 % VAT:
	 * the company keeps the base price, a discount comes off the profit, and the
	 * payout without VAT registration is the profit's share excl. VAT.
	 */
	public function test_a_designer_row_keeps_the_base_price_for_the_company(): void {
		$snapshot = self::designer( false );

		self::assertSame( '50.0000', SalesAmount::format( (int) RealizedProfit::for_entry( self::entry( SalesEntryType::Sale, 1, '160.0000' ), $snapshot, self::tax( '40.0000' ) ) ) );
		self::assertSame( '40.0000', SalesAmount::format( (int) RealizedPayout::for_entry( self::entry( SalesEntryType::Sale, 1, '160.0000' ), $snapshot, self::tax( '40.0000' ) ) ) );
		// A coupon of 20 incl. VAT: paid 180, profit 30.
		self::assertSame( '30.0000', SalesAmount::format( (int) RealizedProfit::for_entry( self::entry( SalesEntryType::Sale, 1, '144.0000' ), $snapshot, self::tax( '36.0000' ) ) ) );
		self::assertSame( '24.0000', SalesAmount::format( (int) RealizedPayout::for_entry( self::entry( SalesEntryType::Sale, 1, '144.0000' ), $snapshot, self::tax( '36.0000' ) ) ) );
		// A coupon of 80: paid 120, below the base price; the profit is 0, never negative.
		self::assertSame( 0, RealizedProfit::for_entry( self::entry( SalesEntryType::Sale, 1, '96.0000' ), $snapshot, self::tax( '24.0000' ) ) );
		// Refund of the discounted unit: the profit is taken back exactly.
		self::assertSame( '-30.0000', SalesAmount::format( (int) RealizedProfit::for_entry( self::entry( SalesEntryType::Refund, -1, '-144.0000', 7001 ), $snapshot, self::tax( '-36.0000' ) ) ) );
		// VAT registered: the payout is the profit.
		self::assertSame( '50.0000', SalesAmount::format( (int) RealizedPayout::for_entry( self::entry( SalesEntryType::Sale, 1, '160.0000' ), self::designer( true ), self::tax( '40.0000' ) ) ) );
		self::assertNull( RealizedPayout::for_entry( self::entry( SalesEntryType::Sale, 1, '160.0000' ), self::designer( null ), self::tax( '40.0000' ) ), 'Unknown VAT status: unknown payout.' );
	}

	/**
	 * A designer snapshot of order line 501: base 150, profit 50.
	 *
	 * @param bool|null $vat_registered VAT status.
	 */
	private static function designer( ?bool $vat_registered ): SalesLinePricing {
		return SalesLinePricing::capture_designer( 501, 900, 20, 0, 'DKK', new DesignerPrice( 7, SalesAmount::parse( '150' ), SalesAmount::parse( '50' ), 'DKK' ), 2, $vat_registered, self::time() );
	}

	/**
	 * A share snapshot of order line 501.
	 *
	 * @param int       $order          Order.
	 * @param int       $item           Order line.
	 * @param int       $creator        Creator.
	 * @param int       $product        Parent product.
	 * @param int       $variation      Variation.
	 * @param string    $currency       Currency.
	 * @param int       $bps            Share.
	 * @param bool|null $vat_registered VAT status.
	 */
	private static function snapshot( int $order = 900, int $item = 501, int $creator = 7, int $product = 20, int $variation = 0, string $currency = 'DKK', int $bps = 2000, ?bool $vat_registered = false ): SalesLinePricing {
		return new SalesLinePricing( $item, $order, $creator, $product, $variation, SnapshotStatus::Share, $currency, self::time(), $bps, MarkupSource::Creator, 2, $vat_registered );
	}

	/**
	 * A ledger row of order line 501.
	 *
	 * @param SalesEntryType             $type       Type.
	 * @param int                        $quantity   Signed quantity.
	 * @param string                     $net        Signed amount.
	 * @param int                        $refund_id  Refund.
	 * @param SalesCorrectionReason|null $correction Correction reason.
	 * @param int                        $variation  Variation.
	 */
	private static function entry( SalesEntryType $type, int $quantity, string $net, int $refund_id = 0, ?SalesCorrectionReason $correction = null, int $variation = 0 ): SalesEntry {
		return new SalesEntry( $type->value . ':501', 7, 900, 501, 1, $refund_id, 20, $variation, 'Tee', $type, $correction, $quantity, $net, 'DKK', self::time() );
	}

	/**
	 * A stored tax of a row.
	 *
	 * @param string $tax Signed tax.
	 * @return array{tax: int, currency: string}
	 */
	private static function tax( string $tax ): array {
		return array(
			'tax'      => SalesAmount::parse( $tax ),
			'currency' => 'DKK',
		);
	}

	/**
	 * Fixed time.
	 */
	private static function time(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-10-09 12:00:00', new DateTimeZone( 'UTC' ) );
	}
}
