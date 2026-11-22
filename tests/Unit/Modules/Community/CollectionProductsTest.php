<?php
/**
 * Tests for collections as products in giveaways, the panel and the ad.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Community;

use Brain\Monkey\Functions;
use MerchBoard\Modules\Community\CommunityModule;
use MerchBoard\Modules\Community\Giveaways\GiveawayCost;
use MerchBoard\Modules\WooCommerce\Collections\DesignCollections;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorProfitDiscount;
use MerchBoard\Tests\Unit\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass( GiveawayCost::class )]
#[CoversClass( CommunityModule::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CollectionProductsTest extends TestCase {

	private const COLLECTION = 10;

	private const DESIGN = 21;

	private const VARIATION = 30;

	/**
	 * The collections, as the giveaways and the panel ask them.
	 *
	 * @var DesignCollections
	 */
	private DesignCollections $collections;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wc_get_product' )->alias(
			static function ( int $id ): object {
				$product = Mockery::mock( 'WC_Product' );
				$product->allows(
					array(
						'get_status'     => 'publish',
						'is_purchasable' => true,
						'get_parent_id'  => self::VARIATION === $id ? self::DESIGN : 0,
						'get_image_id'   => 0,
						'get_name'       => 'Nova &amp; Tee',
					)
				);

				return $product;
			}
		);

		$collections = $this->createStub( DesignCollections::class );
		$collections->method( 'is_collection' )->willReturnCallback( static fn( int $id ): bool => self::COLLECTION === $id );
		$collections->method( 'shown_in_channels' )->willReturnCallback( static fn( int $id ): bool => self::COLLECTION !== $id );

		$this->collections = $collections;
	}

	public function test_a_collection_is_never_priced_as_a_giveaway(): void {
		// Published and purchasable, as WooCommerce loads one without the plugin: refused before any price is read.
		$cost = new GiveawayCost( ( new ReflectionClass( CreatorProfitDiscount::class ) )->newInstanceWithoutConstructor(), $this->collections );

		self::assertNull( $cost->quote( self::COLLECTION, 7 ) );
	}

	public function test_the_panel_and_the_ad_show_a_collected_design_and_never_the_collection(): void {
		$show = new ReflectionMethod( CommunityModule::class, 'panel_product' );

		self::assertSame(
			array(
				'name'        => 'Nova & Tee',
				'image'       => null,
				'transparent' => false,
			),
			$show->invoke( null, $this->collections, self::DESIGN )
		);
		self::assertNull( $show->invoke( null, $this->collections, self::COLLECTION ) );
		self::assertNull( $show->invoke( null, $this->collections, self::VARIATION ), 'Only parent products.' );
	}
}
