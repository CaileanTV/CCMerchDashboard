<?php
/**
 * Tests for a design's prices for free choice.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Community;

use Brain\Monkey\Functions;
use MerchBoard\Modules\Community\Gifting\FreeChoicePrices;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[CoversClass( FreeChoicePrices::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class FreeChoicePricesTest extends TestCase {

	private const PRODUCT = 21;

	private const CREATOR = 7;

	protected function setUp(): void {
		parent::setUp();

		$product = Mockery::mock( 'WC_Product' );
		$product->allows(
			array(
				'get_id'         => self::PRODUCT,
				'is_purchasable' => true,
				'is_in_stock'    => true,
				'get_price'      => '200',
				'get_tax_class'  => '',
				'get_tax_status' => 'taxable',
			)
		);

		$tax = Mockery::mock( 'alias:WC_Tax' );
		$tax->allows( 'get_base_tax_rates' )->andReturn( array( array( 'rate' => '25.0000' ) ) );

		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'wc_tax_enabled' )->justReturn( true );
		// The visitor's address must never be asked: the lock would then differ between buyer and winner.
		Functions\expect( 'wc_get_price_including_tax' )->never();
	}

	private function prices( ?float $profit = 50.0 ): FreeChoicePrices {
		return new FreeChoicePrices( static fn(): int => self::CREATOR, static fn(): ?float => $profit, new RecordingLogger() );
	}

	public function test_prices_entered_with_vat_are_as_entered(): void {
		Functions\when( 'wc_prices_include_tax' )->justReturn( true );

		$read = $this->prices()->read( self::PRODUCT );

		self::assertSame(
			array(
				'attributes' => array(),
				'price'      => 2000000,
				'base'       => 1500000,
			),
			$read['design']['variants'][0] ?? null
		);
		self::assertSame( 2000000, $read['pays'] ?? null );
	}

	public function test_prices_entered_without_vat_get_the_shop_addresss_vat(): void {
		Functions\when( 'wc_prices_include_tax' )->justReturn( false );

		$read = $this->prices()->read( self::PRODUCT );

		self::assertSame(
			array(
				'attributes' => array(),
				'price'      => 2500000,
				'base'       => 1875000,
			),
			$read['design']['variants'][0] ?? null
		);
	}

	public function test_a_design_without_a_known_profit_has_no_prices(): void {
		Functions\when( 'wc_prices_include_tax' )->justReturn( true );

		self::assertNull( $this->prices( null )->read( self::PRODUCT ) );
	}
}
