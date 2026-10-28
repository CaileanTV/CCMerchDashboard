<?php
/**
 * Tests for the creator share snapshot of an order line and the share that applies.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Pricing;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerPrice;
use MerchBoard\Modules\WooCommerce\Pricing\EffectiveMarkup;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupBps;
use MerchBoard\Modules\WooCommerce\Pricing\MarkupSource;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkup;
use MerchBoard\Modules\WooCommerce\Pricing\SalesLinePricing;
use MerchBoard\Modules\WooCommerce\Pricing\SnapshotStatus;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;

#[CoversClass( SalesLinePricing::class )]
#[CoversClass( EffectiveMarkup::class )]
#[CoversClass( ProductMarkup::class )]
final class SalesLinePricingTest extends TestCase {

	private const CREATOR_A = 7;

	private const CREATOR_B = 8;

	public function test_the_creator_markup_applies_without_a_product_markup(): void {
		$markup = EffectiveMarkup::resolve( self::CREATOR_A, new MarkupBps( 2000 ), null );

		self::assertSame( self::CREATOR_A, $markup->creator_id );
		self::assertSame( 2000, $markup->markup->bps );
		self::assertSame( MarkupSource::Creator, $markup->source );
		self::assertNull( $markup->ignored );
	}

	public function test_the_product_markup_of_the_owner_replaces_the_creator_markup(): void {
		$markup = EffectiveMarkup::resolve( self::CREATOR_A, new MarkupBps( 2000 ), new ProductMarkup( 20, self::CREATOR_A, new MarkupBps( 1000 ) ) );

		self::assertSame( 1000, $markup->markup->bps );
		self::assertSame( MarkupSource::Product, $markup->source );
	}

	public function test_a_product_markup_of_a_previous_owner_is_ignored(): void {
		$previous = new ProductMarkup( 20, self::CREATOR_A, new MarkupBps( 1000 ) );
		$markup   = EffectiveMarkup::resolve( self::CREATOR_B, new MarkupBps( 2500 ), $previous );

		self::assertSame( self::CREATOR_B, $markup->creator_id );
		self::assertSame( 2500, $markup->markup->bps );
		self::assertSame( MarkupSource::Creator, $markup->source );
		self::assertSame( $previous, $markup->ignored );
	}

	public function test_capture_records_owner_share_source_precision_and_vat_status(): void {
		$snapshot = self::capture( EffectiveMarkup::resolve( self::CREATOR_A, new MarkupBps( 1667 ), null ), 2, false, 31 );

		self::assertSame( SnapshotStatus::Share, $snapshot->status );
		self::assertSame( self::CREATOR_A, $snapshot->creator_id );
		self::assertSame( 1667, $snapshot->share_bps );
		self::assertSame( MarkupSource::Creator, $snapshot->share_source );
		self::assertSame( 2, $snapshot->price_decimals );
		self::assertFalse( $snapshot->vat_registered );
		self::assertSame( 20, $snapshot->product_id );
		self::assertSame( 31, $snapshot->variation_id, 'Variations keep their own id; the share is the parent\'s.' );
		self::assertTrue( $snapshot->has_share() );
	}

	public function test_a_later_change_of_markup_or_vat_status_cannot_reach_a_snapshot(): void {
		$markup   = new MarkupBps( 2000 );
		$snapshot = self::capture( EffectiveMarkup::resolve( self::CREATOR_A, $markup, null ), 2, true );

		// Values are copied, and every property is readonly.
		self::assertSame( 2000, $snapshot->share_bps );
		self::assertTrue( $snapshot->vat_registered );
		self::assertTrue( ( new ReflectionProperty( SalesLinePricing::class, 'share_bps' ) )->isReadOnly() );
		self::assertTrue( ( new ReflectionProperty( SalesLinePricing::class, 'vat_registered' ) )->isReadOnly() );
	}

	public function test_legacy_snapshots_have_no_share(): void {
		foreach ( array( SnapshotStatus::Priced, SnapshotStatus::Unpriced ) as $status ) {
			$snapshot = new SalesLinePricing( 501, 500, self::CREATOR_A, 20, 0, $status, 'DKK', self::time(), null, null, null, false );

			self::assertFalse( $snapshot->has_share() );
		}
	}

	public function test_a_designer_snapshot_keeps_base_price_profit_and_list_price(): void {
		$snapshot = SalesLinePricing::capture_designer( 501, 500, 20, 31, 'DKK', new DesignerPrice( 7, SalesAmount::parse( '150' ), SalesAmount::parse( '50' ), 'DKK' ), 2, false, self::time() );

		self::assertTrue( $snapshot->is_designer() );
		self::assertTrue( $snapshot->has_share() );
		self::assertSame( SnapshotStatus::Designer, $snapshot->status );
		self::assertSame( MarkupSource::Designer, $snapshot->share_source );
		self::assertSame( 2500, $snapshot->share_bps, 'The effective share: profit / list price.' );
		self::assertSame( array( 1500000, 500000, 2000000 ), array( $snapshot->base_units, $snapshot->profit_units, $snapshot->price_units ) );
		self::assertSame( 7, $snapshot->creator_id );
		self::assertFalse( self::capture( new EffectiveMarkup( self::CREATOR_A, new MarkupBps( 2000 ), MarkupSource::Creator ) )->is_designer(), 'A share snapshot is never a designer snapshot.' );
	}

	public function test_snapshot_must_be_coherent(): void {
		$invalid = array(
			'share without source'       => static fn() => new SalesLinePricing( 501, 500, 7, 20, 0, SnapshotStatus::Share, 'DKK', self::time(), 2000, null, 2 ),
			'share without precision'    => static fn() => new SalesLinePricing( 501, 500, 7, 20, 0, SnapshotStatus::Share, 'DKK', self::time(), 2000, MarkupSource::Creator, null ),
			'share status without share' => static fn() => new SalesLinePricing( 501, 500, 7, 20, 0, SnapshotStatus::Share, 'DKK', self::time() ),
			'legacy status with share'   => static fn() => new SalesLinePricing( 501, 500, 7, 20, 0, SnapshotStatus::Priced, 'DKK', self::time(), 2000, MarkupSource::Creator, 2 ),
			'share above 100 %'          => static fn() => new SalesLinePricing( 501, 500, 7, 20, 0, SnapshotStatus::Share, 'DKK', self::time(), 10001, MarkupSource::Creator, 2 ),
			'precision above 4'          => static fn() => new SalesLinePricing( 501, 500, 7, 20, 0, SnapshotStatus::Share, 'DKK', self::time(), 2000, MarkupSource::Creator, 5 ),
			'invalid currency'           => static fn() => new SalesLinePricing( 501, 500, 7, 20, 0, SnapshotStatus::Share, 'dkk', self::time(), 2000, MarkupSource::Creator, 2 ),
			'invalid reference'          => static fn() => new SalesLinePricing( 0, 500, 7, 20, 0, SnapshotStatus::Share, 'DKK', self::time(), 2000, MarkupSource::Creator, 2 ),
			'designer without amounts'   => static fn() => new SalesLinePricing( 501, 500, 7, 20, 0, SnapshotStatus::Designer, 'DKK', self::time(), 2500, MarkupSource::Designer, 2 ),
			'designer with share source' => static fn() => new SalesLinePricing( 501, 500, 7, 20, 0, SnapshotStatus::Designer, 'DKK', self::time(), 2500, MarkupSource::Creator, 2, false, 1500000, 500000, 2000000 ),
			'share with designer source' => static fn() => new SalesLinePricing( 501, 500, 7, 20, 0, SnapshotStatus::Share, 'DKK', self::time(), 2500, MarkupSource::Designer, 2 ),
			'share with amounts'         => static fn() => new SalesLinePricing( 501, 500, 7, 20, 0, SnapshotStatus::Share, 'DKK', self::time(), 2500, MarkupSource::Creator, 2, false, 1500000, 500000, 2000000 ),
			'list price below base'      => static fn() => new SalesLinePricing( 501, 500, 7, 20, 0, SnapshotStatus::Designer, 'DKK', self::time(), 2500, MarkupSource::Designer, 2, false, 1500000, 500000, 1900000 ),
			'negative profit'            => static fn() => new SalesLinePricing( 501, 500, 7, 20, 0, SnapshotStatus::Designer, 'DKK', self::time(), 0, MarkupSource::Designer, 2, false, 1500000, -100000, 1400000 ),
			'designer price in dkk'      => static fn() => new DesignerPrice( 7, 1500000, 500000, 'dkk' ),
			'designer price negative'    => static fn() => new DesignerPrice( 7, 1500000, -1, 'DKK' ),
		);

		foreach ( $invalid as $label => $factory ) {
			try {
				$factory();
				self::fail( 'Expected an exception: ' . $label );
			} catch ( InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_product_markup_needs_positive_ids(): void {
		$this->expectException( InvalidArgumentException::class );

		new ProductMarkup( 0, self::CREATOR_A, new MarkupBps( 1000 ) );
	}

	/**
	 * A share snapshot of order line 501.
	 *
	 * @param EffectiveMarkup $markup         Share.
	 * @param int             $decimals       Store decimals.
	 * @param bool|null       $vat_registered VAT status.
	 * @param int             $variation      Variation.
	 */
	public static function capture( EffectiveMarkup $markup, int $decimals = 2, ?bool $vat_registered = false, int $variation = 0 ): SalesLinePricing {
		return SalesLinePricing::capture( 501, 500, 20, $variation, 'DKK', $markup, $decimals, $vat_registered, self::time() );
	}

	/**
	 * Fixed time.
	 */
	private static function time(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-10-09 12:00:00', new DateTimeZone( 'UTC' ) );
	}
}
