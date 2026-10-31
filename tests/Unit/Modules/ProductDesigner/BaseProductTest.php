<?php
/**
 * Tests for base products and their sides (ADR-080 pkt. 2).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use InvalidArgumentException;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( BaseProduct::class )]
#[CoversClass( BaseView::class )]
final class BaseProductTest extends TestCase {

	public function test_a_side_knows_its_print_file_and_survives_its_stored_form(): void {
		$view = self::view();

		self::assertSame( array( 3543, 4724 ), $view->print_pixels(), '300 × 400 mm at 300 dpi.' );
		self::assertEquals( $view, BaseView::from_array( json_decode( (string) wp_json_encode( $view->to_array() ), true ) ) );
	}

	public function test_a_flat_side_stays_flat_and_a_printed_side_does_not_store_it(): void {
		$flat = new BaseView( 'front', 'Front', 1000, 1000, array( 'background' => 1, 'shading' => 2 ), array( 'x' => 0, 'y' => 0, 'width' => 1000, 'height' => 1000 ), 300, 300, 150, '', true ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertTrue( BaseView::from_array( json_decode( (string) wp_json_encode( $flat->to_array() ), true ) )->flat );
		self::assertArrayNotHasKey( 'flat', self::view()->to_array() );
		self::assertFalse( BaseView::from_array( self::view()->to_array() )->flat, 'A side stored before flat sides.' );
	}

	public function test_invalid_sides_are_refused(): void {
		$invalid = array(
			'no background or mask' => static fn() => self::view( layers: array( 'shading' => 3 ) ),
			'unknown layer'         => static fn() => self::view( layers: array( 'color' => 1, 'logo' => 2 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'area outside'          => static fn() => self::view( area: array( 'x' => 600, 'y' => 0, 'width' => 500, 'height' => 100 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'empty area'            => static fn() => self::view( area: array( 'x' => 0, 'y' => 0, 'width' => 0, 'height' => 100 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'dpi too low'           => static fn() => self::view( dpi: 71 ),
			'dpi too high'          => static fn() => self::view( dpi: 601 ),
			'no millimetres'        => static fn() => self::view( width_mm: 0 ),
			'invalid key'           => static fn() => self::view( key: 'Front side' ),
			'stored form broken'    => static fn() => BaseView::from_array( array( 'key' => 'front' ) ),
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

	public function test_an_active_base_product_is_complete_and_its_lists_are_unique(): void {
		$base = self::base();

		self::assertSame( array( 1, 2 ), $base->layer_assets() );
		self::assertSame( 'draft', self::base( status: BaseProduct::DRAFT, colors: array(), sizes: array(), views: array() )->status, 'A draft may be incomplete.' );

		$invalid = array(
			'active without colours' => static fn() => self::base( colors: array() ),
			'active without sizes'   => static fn() => self::base( sizes: array() ),
			'active without sides'   => static fn() => self::base( views: array() ),
			'repeated size'          => static fn() => self::base( sizes: array( 'S', 'S' ) ),
			'repeated colour'        => static fn() => self::base( colors: array( self::color(), self::color() ) ),
			'invalid colour'         => static fn() => self::base( colors: array( array_merge( self::color(), array( 'hex' => 'black' ) ) ) ),
			'repeated side'          => static fn() => self::base( views: array( self::view(), self::view() ) ),
			'no price'               => static fn() => self::base( price: 0 ),
			'unknown source'         => static fn() => self::base( source: 'etsy' ),
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

	/**
	 * A side of a 1000 px template.
	 *
	 * @param string                                         $key      Key.
	 * @param array<string, int>                             $layers   Layers.
	 * @param array{x: int, y: int, width: int, height: int} $area     Print area.
	 * @param int                                            $width_mm Print width.
	 * @param int                                            $dpi      Resolution.
	 */
	private static function view( string $key = 'front', array $layers = array( 'background' => 1, 'color' => 2 ), array $area = array( 'x' => 250, 'y' => 200, 'width' => 500, 'height' => 600 ), int $width_mm = 300, int $dpi = 300 ): BaseView { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		return new BaseView( $key, 'Front', 1000, 1000, $layers, $area, $width_mm, 400, $dpi );
	}

	/**
	 * A colour.
	 *
	 * @return array{key: string, name: string, hex: string}
	 */
	private static function color(): array {
		return array(
			'key'  => 'black',
			'name' => 'Black',
			'hex'  => '#111111',
		);
	}

	/**
	 * A base product.
	 *
	 * @phpstan-param list<array{key: string, name: string, hex: string}>|null $colors
	 * @phpstan-param list<string> $sizes
	 * @phpstan-param list<BaseView>|null $views
	 *
	 * @param string          $status Status.
	 * @param array[]|null    $colors Colours (default one).
	 * @param string[]        $sizes  Sizes.
	 * @param BaseView[]|null $views  Sides (default one).
	 * @param int             $price  Base price in units.
	 * @param string          $source Source.
	 */
	private static function base( string $status = BaseProduct::ACTIVE, ?array $colors = null, array $sizes = array( 'S', 'M' ), ?array $views = null, int $price = 1499500, string $source = 'own' ): BaseProduct {
		return new BaseProduct( null, $status, $source, 'Tee', '', $price, 'DKK', null, $colors ?? array( self::color() ), $sizes, $views ?? array( self::view() ) );
	}
}
