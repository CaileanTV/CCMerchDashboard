<?php
/**
 * Tests for the dashboard's product data read through WooCommerce CRUD.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Dashboard;

use Brain\Monkey\Functions;
use MerchBoard\Modules\WooCommerce\Dashboard\ProductSummary;
use MerchBoard\Modules\WooCommerce\Dashboard\WooCommerceProductDetails;
use MerchBoard\Tests\Unit\Support\WcProductStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Every test runs in its own process: the WooCommerce stand-in defines global
 * classes and functions.
 */
#[CoversClass( WooCommerceProductDetails::class )]
#[CoversClass( ProductSummary::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WooCommerceProductDetailsTest extends TestCase {

	private const HOME = 'https://shop.example';

	private WooCommerceProductDetails $details;

	/**
	 * Attachment URLs by attachment id.
	 *
	 * @var array<int, string>
	 */
	private static array $attachments = array();

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 4 ) . '/Fixtures/woocommerce-product.php';

		WcProductStorage::$products              = array();
		WcProductStorage::$meta                  = array();
		WcProductStorage::$variation_price_calls = array();
		self::$attachments                       = array();

		Functions\when( 'get_woocommerce_currency' )->justReturn( 'DKK' );
		Functions\when( 'wc_format_decimal' )->alias(
			static fn( string|float|int $number ): string => is_string( $number ) ? $number : rtrim( rtrim( number_format( (float) $number, 6, '.', '' ), '0' ), '.' )
		);
		Functions\when( 'home_url' )->justReturn( self::HOME );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		// Like wp_strip_all_tags(): script and style elements go with their content.
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $text ): string => trim( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text ) ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Stands in for wp_strip_all_tags() itself.
		);
		Functions\when( 'wp_get_attachment_image_url' )->alias(
			static function ( int $id, string $size ): string|false {
				self::assertSame( 'thumbnail', $size );

				return self::$attachments[ $id ] ?? false;
			}
		);
		// The price is never calculated for display or tax (ADR-039 P7 clarification).
		Functions\expect( 'wc_get_price_to_display' )->never();
		Functions\expect( 'wc_get_price_including_tax' )->never();
		Functions\expect( 'wc_get_price_excluding_tax' )->never();

		$this->details = new WooCommerceProductDetails();
	}

	public function test_a_simple_product_has_its_active_price_as_min_and_max(): void {
		WcProductStorage::add( 10, array(), 'simple', 0, 'Tour tee' );
		WcProductStorage::set( 10, array( 'price' => '149.00' ) );

		$product = $this->product( 10 );

		self::assertSame( 10, $product['id'] );
		self::assertSame( 'Tour tee', $product['name'] );
		self::assertSame( 'publish', $product['status'] );
		self::assertSame(
			array(
				'min'      => '149.00',
				'max'      => '149.00',
				'currency' => 'DKK',
				'tax'      => 'store_setting',
			),
			$product['price']
		);
	}

	public function test_the_active_sale_price_is_used_not_the_regular_price(): void {
		WcProductStorage::add( 10 );
		WcProductStorage::set(
			10,
			array(
				'price'         => '99.00',
				'regular_price' => '149.00',
			)
		);

		self::assertSame( array( '99.00', '99.00' ), self::range( $this->product( 10 ) ) );
	}

	public function test_a_variable_product_has_the_min_and_max_of_its_active_variation_prices(): void {
		WcProductStorage::add( 20, array(), 'variable', 0, 'Hoodie' );
		WcProductStorage::set(
			20,
			array(
				'variation_prices' => array(
					'price'         => array(
						21 => '250.00',
						22 => '199.50',
						23 => '300',
					),
					'regular_price' => array(
						21 => '250.00',
						22 => '260.00',
						23 => '999.00',
					),
					'sale_price'    => array(
						21 => '',
						22 => '199.50',
						23 => '',
					),
				),
			)
		);

		$product = $this->product( 20 );

		self::assertSame( array( '199.50', '300' ), self::range( $product ) );
		self::assertSame( 'store_setting', $product['price']['tax'] ?? null );
		self::assertSame( array( false ), WcProductStorage::$variation_price_calls, 'Read without display (tax) adjustment.' );
	}

	/**
	 * Products without an active price.
	 *
	 * @return array<string, array{string, array<string, mixed>}>
	 */
	public static function without_price(): array {
		return array(
			'simple, empty price'        => array( 'simple', array( 'price' => '' ) ),
			'simple, regular price only' => array(
				'simple',
				array(
					'price'         => '',
					'regular_price' => '149.00',
				),
			),
			'variable, no variations'    => array( 'variable', array() ),
			'variable, no active prices' => array(
				'variable',
				array(
					'variation_prices' => array(
						'price'         => array( 21 => '' ),
						'regular_price' => array( 21 => '10' ),
						'sale_price'    => array( 21 => '' ),
					),
				),
			),
		);
	}

	/**
	 * No active price gives `price: null`.
	 *
	 * @param string               $type   Product type.
	 * @param array<string, mixed> $fields Product fields.
	 */
	#[DataProvider( 'without_price' )]
	public function test_no_active_price_gives_null( string $type, array $fields ): void {
		WcProductStorage::add( 30, array(), $type );
		WcProductStorage::set( 30, $fields );

		self::assertNull( $this->product( 30 )['price'] );
	}

	public function test_a_float_from_a_price_filter_becomes_a_decimal_string(): void {
		WcProductStorage::add( 10 );
		WcProductStorage::set( 10, array( 'price' => 12.5 ) );

		self::assertSame( array( '12.5', '12.5' ), self::range( $this->product( 10 ) ) );
	}

	public function test_the_response_has_no_floats_and_no_html(): void {
		WcProductStorage::add( 10, array(), 'simple', 0, '<b>Tour</b> tee &amp; <script>x</script>cap' );
		WcProductStorage::set(
			10,
			array(
				'price'    => 12.5,
				'image_id' => '7',
			)
		);
		self::$attachments[7] = self::HOME . '/wp-content/uploads/tee-150x150.jpg';

		$product = $this->product( 10 );

		self::assertSame( 'Tour tee & cap', $product['name'] );
		self::assertSame( 'Tour tee & cap', $product['image']['alt'] ?? null );
		array_walk_recursive(
			$product,
			static function ( mixed $value ): void {
				self::assertIsNotFloat( $value );

				if ( is_string( $value ) ) {
					self::assertStringNotContainsString( '<', $value );
				}
			}
		);
		self::assertSame( array( 'id', 'name', 'status', 'price', 'stock_status', 'image' ), array_keys( $product ) );
	}

	/**
	 * Stock statuses: only WooCommerce's three are shown.
	 *
	 * @return array<string, array{string, string|null}>
	 */
	public static function stock_statuses(): array {
		return array(
			'in stock'     => array( 'instock', 'instock' ),
			'out of stock' => array( 'outofstock', 'outofstock' ),
			'backorder'    => array( 'onbackorder', 'onbackorder' ),
			'custom'       => array( 'preorder', null ),
		);
	}

	/**
	 * Only the stock status, never a stock quantity.
	 *
	 * @param string      $status   Stored stock status.
	 * @param string|null $expected Shown stock status.
	 */
	#[DataProvider( 'stock_statuses' )]
	public function test_stock_status( string $status, ?string $expected ): void {
		WcProductStorage::add( 10 );
		WcProductStorage::set( 10, array( 'stock_status' => $status ) );

		self::assertSame( $expected, $this->product( 10 )['stock_status'] );
	}

	public function test_a_same_origin_thumbnail_is_shown_with_the_name_as_alt(): void {
		WcProductStorage::add( 10, array(), 'simple', 0, 'Tour tee' );
		WcProductStorage::set( 10, array( 'image_id' => '7' ) );
		self::$attachments[7] = self::HOME . '/wp-content/uploads/tee-150x150.jpg';

		self::assertSame(
			array(
				'url' => self::HOME . '/wp-content/uploads/tee-150x150.jpg',
				'alt' => 'Tour tee',
			),
			$this->product( 10 )['image']
		);
	}

	/**
	 * Thumbnail URLs that are not same-origin, or no thumbnail.
	 *
	 * @return array<string, array{string|null}>
	 */
	public static function foreign_images(): array {
		return array(
			'other host'        => array( 'https://cdn.example/tee.jpg' ),
			'subdomain'         => array( 'https://img.shop.example/tee.jpg' ),
			'other scheme'      => array( 'http://shop.example/tee.jpg' ),
			'other port'        => array( 'https://shop.example:8443/tee.jpg' ),
			'protocol-relative' => array( '//shop.example/tee.jpg' ),
			'credentials'       => array( 'https://user:pass@shop.example/tee.jpg' ),
			'data URI'          => array( 'data:image/png;base64,AAAA' ),
			'no attachment'     => array( null ),
		);
	}

	/**
	 * A cross-origin thumbnail (or none) gives `image: null`.
	 *
	 * @param string|null $url Attachment URL, null for a missing attachment.
	 */
	#[DataProvider( 'foreign_images' )]
	public function test_a_cross_origin_or_missing_thumbnail_gives_null( ?string $url ): void {
		WcProductStorage::add( 10 );
		WcProductStorage::set( 10, array( 'image_id' => '7' ) );

		if ( null !== $url ) {
			self::$attachments[7] = $url;
		}

		self::assertNull( $this->product( 10 )['image'] );
	}

	public function test_an_explicit_default_port_is_the_same_origin(): void {
		WcProductStorage::add( 10 );
		WcProductStorage::set( 10, array( 'image_id' => '7' ) );
		self::$attachments[7] = 'https://SHOP.example:443/tee.jpg';

		self::assertSame( 'https://SHOP.example:443/tee.jpg', $this->product( 10 )['image']['url'] ?? null );
	}

	public function test_a_product_without_an_image_has_no_image(): void {
		WcProductStorage::add( 10 );

		self::assertNull( $this->product( 10 )['image'] );
	}

	public function test_private_products_are_shown(): void {
		WcProductStorage::add( 10, array(), 'simple', 0, 'Secret', '', 'private' );

		self::assertSame( 'private', $this->product( 10 )['status'] );
	}

	/**
	 * Products the dashboard never shows.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function hidden(): array {
		return array(
			'draft'     => array( 'simple', 'draft' ),
			'pending'   => array( 'simple', 'pending' ),
			'trash'     => array( 'simple', 'trash' ),
			'variation' => array( 'variation', 'publish' ),
		);
	}

	/**
	 * Changed after the selection: not shown.
	 *
	 * @param string $type   Product type.
	 * @param string $status Status.
	 */
	#[DataProvider( 'hidden' )]
	public function test_other_statuses_and_variations_give_null( string $type, string $status ): void {
		WcProductStorage::add( 10, array(), $type, 'variation' === $type ? 9 : 0, '', '', $status );

		self::assertNull( $this->details->find( 10 ) );
	}

	public function test_a_missing_product_gives_null(): void {
		self::assertNull( $this->details->find( 404 ) );
		self::assertNull( $this->details->find( 0 ) );
	}

	/**
	 * The REST representation of a product that must exist.
	 *
	 * @param int $id Product id.
	 * @return array<string, mixed>
	 */
	private function product( int $id ): array {
		$summary = $this->details->find( $id );
		self::assertNotNull( $summary );

		return $summary->to_array();
	}

	/**
	 * `[min, max]` of a product's price.
	 *
	 * @param array<string, mixed> $product Product.
	 * @return array{mixed, mixed}
	 */
	private static function range( array $product ): array {
		$price = $product['price'];
		self::assertIsArray( $price );

		return array( $price['min'] ?? null, $price['max'] ?? null );
	}
}
