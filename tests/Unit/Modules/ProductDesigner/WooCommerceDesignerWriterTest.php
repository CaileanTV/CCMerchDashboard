<?php
/**
 * Tests for the only writer of designer products (ADR-080 pkt. 6–7, steps 30.5 and 30.10).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use Brain\Monkey\Functions;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerContent;
use MerchBoard\Modules\ProductDesigner\Shop\GuardedProduct;
use MerchBoard\Modules\ProductDesigner\Shop\WooCommerceDesignerWriter;
use MerchBoard\Tests\Unit\Support\RecordingDesignerGuard;
use MerchBoard\Tests\Unit\Support\WcProductStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[CoversClass( WooCommerceDesignerWriter::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WooCommerceDesignerWriterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/Fixtures/woocommerce-product.php';

		WcProductStorage::$products = array();
		WcProductStorage::$meta     = array();
		WcProductStorage::$setters  = array();
		WcProductStorage::$synced   = array();
		WcProductStorage::$saved    = array();

		Functions\when( 'wc_tax_enabled' )->justReturn( true );
		Functions\when( 'wc_prices_include_tax' )->justReturn( true );
	}

	public function test_the_price_is_written_on_the_designer_product_s_own_variations_only(): void {
		WcProductStorage::add( 10, array(), 'variable', 0, 'Tee' );
		WcProductStorage::add( 11, array(), 'variation', 10 );
		WcProductStorage::add( 12, array(), 'variation', 10 );
		WcProductStorage::set( 12, array( 'regular_price' => '200.00', 'sale_price' => '180', 'price' => '180' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		WcProductStorage::add( 20, array(), 'variable', 0, 'Legacy' );
		WcProductStorage::add( 21, array(), 'variation', 20 );

		$this->writer()->set_prices( new GuardedProduct( 3, 10, 7 ), 2000000, 2 );

		self::assertSame( array( array( 11, 'regular_price', '200.00' ) ), WcProductStorage::$setters, 'Only the variation whose price differs; never a sale price, never another product.' );
		self::assertSame( '180', WcProductStorage::$products[12]['sale_price'] ?? null, 'The company\'s sale price is left alone.' );
		self::assertSame( array( 10 ), WcProductStorage::$synced );
	}

	public function test_nothing_is_written_when_the_store_enters_prices_excluding_vat(): void {
		WcProductStorage::add( 10, array(), 'variable', 0, 'Tee' );
		WcProductStorage::add( 11, array(), 'variation', 10 );
		Functions\when( 'wc_prices_include_tax' )->justReturn( false );

		try {
			$this->writer()->set_prices( new GuardedProduct( 3, 10, 7 ), 2000000, 2 );
			self::fail( 'Base price and profit are incl. VAT.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::PRICES_EXCL_TAX, $e->reason() );
		}

		self::assertSame( array(), WcProductStorage::$setters );
	}

	public function test_a_variation_itself_is_refused(): void {
		WcProductStorage::add( 10, array(), 'variation', 0, 'Tee' );

		$this->expectException( DesignerException::class );
		$this->writer()->set_prices( new GuardedProduct( 3, 10, 7 ), 2000000, 2 );
	}

	public function test_a_simple_product_gets_its_price_on_itself(): void {
		WcProductStorage::add( 10, array(), 'simple', 0, 'Tee' );

		$this->writer()->set_prices( new GuardedProduct( 3, 10, 7 ), 2000000, 2 );

		self::assertSame( '200.00', WcProductStorage::$products[10]['regular_price'] ?? null );
		self::assertSame( array(), WcProductStorage::$synced );
	}

	public function test_one_colour_and_one_size_make_a_simple_product(): void {
		WcProductStorage::add( 10, array(), 'variable', 0, 'Draft', '', 'draft' );
		$this->attribute_stubs();

		$this->writer()->set_content( new GuardedProduct( 3, 10, 7 ), self::content( true, array( 'black' ), array( 'One size' ) ) );

		$product = WcProductStorage::$products[10];

		self::assertSame( array( 'simple', '249.00', 'publish' ), array( $product['type'], $product['regular_price'] ?? null, $product['status'] ) );
		self::assertSame( array( false, false ), array_map( self::variation_flag( ... ), $product['attributes'] ?? array() ) );
		self::assertSame( array( 10 ), array_keys( WcProductStorage::$products ), 'No variations.' );
	}

	public function test_a_choice_with_one_value_is_no_variation(): void {
		WcProductStorage::add( 10, array(), 'variable', 0, 'Draft', '', 'draft' );
		$this->attribute_stubs();

		$this->writer()->set_content( new GuardedProduct( 3, 10, 7 ), self::content( true, array( 'black', 'sand' ), array( 'One size' ) ) );

		$product = WcProductStorage::$products[10];

		self::assertSame( array( true, false ), array_map( self::variation_flag( ... ), $product['attributes'] ?? array() ), 'Only the colours are chosen.' );

		$variations = array_values( array_filter( WcProductStorage::$products, static fn( array $row ): bool => 'variation' === $row['type'] ) );

		self::assertSame(
			array( array( 'colour' => 'Black' ), array( 'colour' => 'Sand' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array_column( $variations, 'attributes' )
		);
	}

	public function test_a_simple_product_with_more_values_becomes_variable_on_the_same_id(): void {
		WcProductStorage::add( 10, array(), 'simple', 0, 'Tee', '', 'publish' );
		$this->attribute_stubs();

		$this->writer()->set_content( new GuardedProduct( 3, 10, 7 ), self::content( false ) );

		self::assertSame( array( 'variable', 'publish' ), array( WcProductStorage::$products[10]['type'], WcProductStorage::$products[10]['status'] ) );
		self::assertCount( 4, array_filter( WcProductStorage::$products, static fn( array $row ): bool => 'variation' === $row['type'] ) );
	}

	public function test_a_variable_product_stays_variable_and_its_extra_variations_are_hidden(): void {
		WcProductStorage::add( 10, array(), 'variable', 0, 'Tee', '', 'publish' );
		WcProductStorage::add( 11, array(), 'variation', 10 );
		WcProductStorage::set( 11, array( 'attributes' => array( 'colour' => 'Black', 'size' => 'S' ), 'regular_price' => '200.00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		WcProductStorage::add( 12, array(), 'variation', 10 );
		WcProductStorage::set( 12, array( 'attributes' => array( 'colour' => 'Black', 'size' => 'M' ), 'regular_price' => '200.00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->attribute_stubs();

		$this->writer()->set_content( new GuardedProduct( 3, 10, 7 ), self::content( false, array( 'black' ), array( 'S' ) ) );

		self::assertSame( 'variable', WcProductStorage::$products[10]['type'] );
		self::assertSame( array( 'publish', 'private' ), array( WcProductStorage::$products[11]['status'], WcProductStorage::$products[12]['status'] ) );
	}

	public function test_a_draft_created_earlier_is_claimed_again_instead_of_a_second_product(): void {
		$guard = new RecordingDesignerGuard( null );
		Functions\expect( 'get_posts' )->once()->andReturn( array( 10 ) );

		$proof = ( new WooCommerceDesignerWriter( $guard ) )->create_draft( 3, 'Tee' );

		self::assertSame( 10, $proof->wc_product_id );
		self::assertSame( array( 'for_row 3', 'token 3', 'claim 3 10' ), $guard->calls );
	}

	public function test_a_row_with_its_product_gets_the_same_product(): void {
		$proof = new GuardedProduct( 3, 10, 7 );
		$guard = new RecordingDesignerGuard( $proof );

		self::assertSame( $proof, ( new WooCommerceDesignerWriter( $guard ) )->create_draft( 3, 'Tee' ) );
		self::assertSame( array( 'for_row 3' ), $guard->calls, 'No new draft, no claim.' );
	}

	public function test_an_approved_version_writes_its_content_and_a_variation_per_colour_and_size(): void {
		WcProductStorage::add( 10, array(), 'variable', 0, 'Draft', '', 'draft' );
		Functions\when( 'sanitize_title' )->alias( static fn( string $title ): string => strtolower( $title ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( '__' )->returnArg();

		$this->writer()->set_content( new GuardedProduct( 3, 10, 7 ), self::content( true ) );

		$product = WcProductStorage::$products[10];
		self::assertSame( array( 'Stream tee', 'Soft.', array( 42 ), 501, array( 502, 503 ), 'publish' ), array( $product['name'], $product['description'] ?? null, $product['categories'], $product['image_id'] ?? null, $product['gallery'] ?? null, $product['status'] ) );
		$data  = static fn( mixed $attribute ): array => is_object( $attribute ) && property_exists( $attribute, 'data' ) && is_array( $attribute->data ) ? $attribute->data : array();
		$color = $data( $product['attributes'][0] ?? null );
		self::assertSame( array( 'Black', 'Sand' ), $color['options'] ?? null );
		self::assertSame( array( 'S', 'M' ), $data( $product['attributes'][1] ?? null )['options'] ?? null );
		self::assertTrue( $color['variation'] ?? null );
		self::assertSame( '["Colour","Size"]', WcProductStorage::$meta[10][0]['value'] ?? null, 'The attribute names are kept on the product.' );

		$variations = array_values( array_filter( WcProductStorage::$products, static fn( array $row ): bool => 'variation' === $row['type'] ) );
		self::assertSame(
			array(
				array(
					array(
						'colour' => 'Black',
						'size'   => 'S',
					),
					'publish',
					501,
					'249.00',
				),
				array(
					array(
						'colour' => 'Black',
						'size'   => 'M',
					),
					'publish',
					501,
					'249.00',
				),
				array(
					array(
						'colour' => 'Sand',
						'size'   => 'S',
					),
					'publish',
					503,
					'249.00',
				),
				array(
					array(
						'colour' => 'Sand',
						'size'   => 'M',
					),
					'publish',
					503,
					'249.00',
				),
			),
			array_map( static fn( array $row ): array => array( $row['attributes'] ?? null, $row['status'], $row['image_id'] ?? null, $row['regular_price'] ?? null ), $variations )
		);
		self::assertSame( array( 10 ), WcProductStorage::$synced );
	}

	public function test_a_change_updates_the_variations_and_hides_those_no_longer_offered(): void {
		WcProductStorage::add( 10, array(), 'variable', 0, 'Tee', '', 'publish' );
		WcProductStorage::add( 11, array(), 'variation', 10 );
		WcProductStorage::set( 11, array( 'attributes' => array( 'colour' => 'Black', 'size' => 'S' ), 'regular_price' => '200.00', 'sale_price' => '180', 'price' => '180' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		WcProductStorage::add( 12, array(), 'variation', 10 );
		WcProductStorage::set( 12, array( 'attributes' => array( 'colour' => 'Red', 'size' => 'S' ), 'regular_price' => '200.00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		WcProductStorage::$meta[10] = array( array( 'id' => 1, 'key' => WooCommerceDesignerWriter::ATTRIBUTES_META, 'value' => '["Colour","Size"]' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		WcProductStorage::add( 20, array(), 'variable', 0, 'Legacy' );
		WcProductStorage::add( 21, array(), 'variation', 20 );
		Functions\when( 'sanitize_title' )->alias( static fn( string $title ): string => strtolower( $title ) );

		$this->writer()->set_content( new GuardedProduct( 3, 10, 7 ), self::content( false ) );

		self::assertSame( 'publish', WcProductStorage::$products[10]['status'], 'A change keeps the product\'s status.' );
		self::assertSame( array( 'publish', '249.00', '180' ), array( WcProductStorage::$products[11]['status'], WcProductStorage::$products[11]['regular_price'] ?? null, WcProductStorage::$products[11]['sale_price'] ?? null ), 'The same variation, a new price; the company\'s sale price is kept.' );
		self::assertSame( 'private', WcProductStorage::$products[12]['status'], 'No longer offered: hidden, never deleted.' );
		self::assertNotContains( 20, WcProductStorage::$saved, 'Another product is never written.' );
		self::assertNotContains( 21, WcProductStorage::$saved );
	}

	public function test_content_is_refused_when_the_store_enters_prices_excluding_vat(): void {
		WcProductStorage::add( 10, array(), 'variable', 0, 'Tee' );
		Functions\when( 'wc_prices_include_tax' )->justReturn( false );

		try {
			$this->writer()->set_content( new GuardedProduct( 3, 10, 7 ), self::content( true ) );
			self::fail( 'Base price and profit are incl. VAT.' );
		} catch ( DesignerException $e ) {
			self::assertSame( DesignerException::PRICES_EXCL_TAX, $e->reason() );
		}

		self::assertSame( array(), WcProductStorage::$saved );
	}

	public function test_unpublish_makes_the_product_a_draft_once(): void {
		WcProductStorage::add( 10, array(), 'variable', 0, 'Tee', '', 'publish' );

		$this->writer()->unpublish( new GuardedProduct( 3, 10, 7 ) );
		$this->writer()->unpublish( new GuardedProduct( 3, 10, 7 ) );

		self::assertSame( 'draft', WcProductStorage::$products[10]['status'] );
		self::assertSame( array( 10 ), WcProductStorage::$saved );
	}

	/**
	 * The content of an approved version.
	 *
	 * @phpstan-param list<string> $colors
	 * @phpstan-param list<string> $sizes
	 *
	 * @param bool               $publish Whether it is the first approval.
	 * @param array<int, string> $colors  Colour keys.
	 * @param array<int, string> $sizes   Sizes.
	 */
	private static function content( bool $publish, array $colors = array( 'black', 'sand' ), array $sizes = array( 'S', 'M' ) ): DesignerContent {
		$names = array(
			'black' => 'Black',
			'sand'  => 'Sand',
		);

		return new DesignerContent(
			'Stream tee',
			'Soft.',
			array( 42 ),
			array_map(
				static fn( string $key ): array => array(
					'key'  => $key,
					'name' => $names[ $key ],
				),
				$colors
			),
			$sizes,
			array( 501, 502, 503 ),
			array(
				'black' => 501,
				'sand'  => 503,
			),
			2490000,
			2,
			$publish
		);
	}

	/**
	 * Whether an attribute is a choice in the shop.
	 *
	 * @param mixed $attribute The fixture's attribute.
	 */
	private static function variation_flag( mixed $attribute ): mixed {
		$data = is_object( $attribute ) && property_exists( $attribute, 'data' ) ? $attribute->data : array();

		return is_array( $data ) ? ( $data['variation'] ?? null ) : null;
	}

	/**
	 * The functions the attributes need.
	 */
	private function attribute_stubs(): void {
		Functions\when( 'sanitize_title' )->alias( static fn( string $title ): string => strtolower( $title ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( '__' )->returnArg();
	}

	/**
	 * A writer whose guard is not used by set_prices().
	 */
	private function writer(): WooCommerceDesignerWriter {
		return new WooCommerceDesignerWriter( new RecordingDesignerGuard( null ) );
	}
}
