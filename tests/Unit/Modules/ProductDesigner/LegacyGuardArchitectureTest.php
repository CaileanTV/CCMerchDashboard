<?php
/**
 * Architecture test of the legacy guard (ADR-080 pkt. 7).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\ProductDesigner;

use MerchBoard\Tests\Unit\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Legacy products must never be touched: the plugin writes WooCommerce
 * prices and creates products in exactly one class, and only the guard can
 * prove a product is a designer product and mark one. This test reads every
 * PHP file under src/ and fails when such code appears anywhere else.
 */
final class LegacyGuardArchitectureTest extends TestCase {

	private const WRITER = 'src/Modules/ProductDesigner/Shop/WooCommerceDesignerWriter.php';

	private const GUARD = 'src/Modules/ProductDesigner/Shop/DesignerProductGuard.php';

	/**
	 * Pattern => the only files allowed to contain it.
	 *
	 * @return array<string, array{string, list<string>}>
	 */
	public static function rules(): array {
		return array(
			'price setters'             => array( '/->\s*set_(?:regular_|sale_)?price\s*\(/i', array( self::WRITER ) ),
			'price meta keys'           => array( '/[\'"]_(?:regular_|sale_)?price[\'"]/i', array() ),
			'bulk price changes'        => array( '/variable_(?:regular|sale)_price|wc_update_product_price|_price_sync/i', array() ),
			'new WooCommerce products'  => array( '/new\s+\\\\?WC_Product(?:_[A-Za-z]+)?\s*\(/', array( self::WRITER ) ),
			'the guard\'s proof'        => array( '/new\s+GuardedProduct\s*\(/', array( self::GUARD ) ),
			'the designer marker write' => array( '/ProtectedMetaWrite::for_designer\s*\(/', array( self::GUARD ) ),
			'the designer marker key'   => array( '/DESIGNER_KEY/', array( self::GUARD, 'src/Core/Security/ProtectedProductMeta.php' ) ),
		);
	}

	public function test_only_the_writer_and_the_guard_can_touch_products_as_designer_products(): void {
		$root  = dirname( __DIR__, 4 );
		$found = array();

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) ) as $file ) {
			if ( ! $file instanceof SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			$path = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
			$code = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the plugin's own source.

			foreach ( self::rules() as $label => [ $pattern, $allowed ] ) {
				if ( 1 === preg_match( $pattern, $code ) && ! in_array( $path, $allowed, true ) ) {
					$found[] = $label . ': ' . $path;
				}
			}
		}

		self::assertSame( array(), $found, 'Only WooCommerceDesignerWriter writes prices and creates products, and only DesignerProductGuard proves and marks designer products.' );
	}

	public function test_the_writer_takes_existing_products_only_as_the_guard_s_proof(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 4 ) . '/' . self::WRITER ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the plugin's own source.

		preg_match_all( '/public\s+function\s+(\w+)\s*\(([^)]*)\)/', $code, $methods, PREG_SET_ORDER );

		$public = array();

		foreach ( $methods as [ , $name, $params ] ) {
			$public[ $name ] = trim( $params );
		}

		self::assertSame( array( '__construct', 'create_draft', 'set_prices' ), array_keys( $public ), 'A new public method needs a review against the legacy guard.' );
		self::assertStringStartsWith( 'GuardedProduct $product', $public['set_prices'] );
	}
}
