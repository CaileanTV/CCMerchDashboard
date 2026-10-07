<?php
/**
 * Tests for the current product names through WooCommerce CRUD.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Dashboard;

use Brain\Monkey\Functions;
use MerchBoard\Modules\WooCommerce\Dashboard\PlainText;
use MerchBoard\Modules\WooCommerce\Dashboard\WooCommerceSalesProductNames;
use MerchBoard\Tests\Unit\Support\WcProductStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Every test runs in its own process: the WooCommerce stand-ins define global
 * classes and functions.
 */
#[CoversClass( WooCommerceSalesProductNames::class )]
#[CoversClass( PlainText::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WooCommerceSalesProductNamesTest extends TestCase {

	private WooCommerceSalesProductNames $names;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 4 ) . '/Fixtures/woocommerce-product.php';

		WcProductStorage::$products = array();
		WcProductStorage::$meta     = array();
		WcProductStorage::$lookups  = array();

		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $text ): string => trim( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text ) ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Stands in for wp_strip_all_tags() itself.
		);

		$this->names = new WooCommerceSalesProductNames();
	}

	public function test_existing_products_have_their_current_name_as_plain_text(): void {
		WcProductStorage::add( 20, array(), 'variable', 0, 'Tour tee' );
		WcProductStorage::add( 30, array(), 'simple', 0, 'Poster <b>A2</b> &amp; frame<script>x()</script>' );

		self::assertSame(
			array(
				20 => 'Tour tee',
				30 => 'Poster A2 & frame',
			),
			$this->names->current( array( 20, 30 ) )
		);
	}

	public function test_missing_products_and_empty_names_are_left_out(): void {
		WcProductStorage::add( 20, array(), 'simple', 0, 'Placeholder' );
		WcProductStorage::add( 40, array(), 'simple', 0, '<b></b>' );
		WcProductStorage::$products[20]['name'] = '';

		self::assertSame( array(), $this->names->current( array( 20, 30, 40 ) ) );
	}

	public function test_each_id_is_looked_up_once_and_id_0_never(): void {
		WcProductStorage::add( 20, array(), 'simple', 0, 'Tour tee' );

		self::assertSame( array( 20 => 'Tour tee' ), $this->names->current( array( 0, 20, 30, 20, -1, 0 ) ) );
		self::assertSame( array( 20, 30 ), WcProductStorage::$lookups );
	}

	public function test_no_ids_no_lookups(): void {
		self::assertSame( array(), $this->names->current( array() ) );
		self::assertSame( array(), WcProductStorage::$lookups );
	}
}
