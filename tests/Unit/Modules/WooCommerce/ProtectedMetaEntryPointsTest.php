<?php
/**
 * Tests for the clear rejections at WooCommerce's entry points.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce;

use Brain\Monkey\Functions;
use Exception;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ProtectedMetaEntryPoints;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Tests\Unit\Support\WcProductStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WC_Product;
use WP_Error;
use WP_REST_Request;

/**
 * Separate processes: the WooCommerce and REST stand-ins define global classes.
 */
#[CoversClass( ProtectedMetaEntryPoints::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ProtectedMetaEntryPointsTest extends TestCase {

	private const LINK = ProductCreatorLink::META_KEY;

	private ProtectedMetaEntryPoints $entry;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/Fixtures/woocommerce-product.php';
		require_once dirname( __DIR__, 3 ) . '/Fixtures/wp-rest.php';

		WcProductStorage::$products     = array();
		WcProductStorage::$meta         = array();
		WcProductStorage::$next_meta_id = 100;

		WcProductStorage::add(
			10,
			array(
				self::LINK => '3',
				'_price'   => '10',
			)
		);
		WcProductStorage::add( 11, array( '_price' => '5' ) );
		WcProductStorage::add( 12, array(), 'variation', 11 );

		$this->entry = new ProtectedMetaEntryPoints();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id ): array {
				$meta = array();

				foreach ( WcProductStorage::$meta[ $id ] ?? array() as $row ) {
					$meta[ $row['key'] ][] = $row['value'];
				}

				return $meta;
			}
		);
	}

	public function test_a_rest_save_that_leaves_the_protected_meta_alone_passes(): void {
		$product = new WC_Product( 10 );
		$product->add_meta_data( '_custom_note', 'hello' );

		self::assertSame( $product, $this->entry->check_rest_object( $product, $this->request( '/wc/v3/products/10' ) ) );
		$unlinked = new WC_Product( 11 );
		self::assertSame( $unlinked, $this->entry->check_rest_object( $unlinked, $this->request( '/wc/v3/products/11' ) ) );
	}

	public function test_a_rest_save_that_adds_changes_renames_or_deletes_the_link_is_rejected(): void {
		$add = new WC_Product( 11 );
		$add->add_meta_data( '_Merchboard_Creator_Link_Source', 'admin' );

		$change                            = new WC_Product( 10 );
		$change->get_meta_data()[0]->value = '4';

		$rename                          = new WC_Product( 10 );
		$rename->get_meta_data()[0]->key = '_other';

		$into                          = new WC_Product( 11 );
		$into->get_meta_data()[0]->key = self::LINK;

		$delete = new WC_Product( 10 );
		$delete->delete_meta_data_by_mid( 100 );

		$variation = new WC_Product( 12 );
		$variation->add_meta_data( self::LINK, '3' );

		foreach ( compact( 'add', 'change', 'rename', 'into', 'delete', 'variation' ) as $case => $product ) {
			$result = $this->entry->check_rest_object( $product, $this->request( '/wc/v3/products/' . $product->get_id() ) );

			self::assertInstanceOf( WP_Error::class, $result, $case );
			self::assertSame( ProtectedMetaEntryPoints::ERROR_CODE, $result->get_error_code() );
			self::assertSame( array( 'status' => 400 ), $result->get_error_data() );
		}
	}

	public function test_the_duplicate_route_and_earlier_errors_are_passed_through(): void {
		$product = new WC_Product( 11 );
		$product->add_meta_data( self::LINK, '3' );
		$error = new WP_Error( 'earlier' );

		self::assertSame( $product, $this->entry->check_rest_object( $product, $this->request( '/wc/v3/products/11/duplicate' ) ), 'WooCommerce does not check errors there; the copy is kept clean by the exclude list and the guard.' );
		self::assertSame( $error, $this->entry->check_rest_object( $error, null ) );
	}

	public function test_generating_variations_with_protected_meta_is_rejected(): void {
		$bad   = $this->request( '/wc/v3/products/11/variations/generate', array( 'meta_data' => array( array( 'key' => '_MERCHBOARD_CREATOR_ID', 'value' => '3' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$good  = $this->request( '/wc/v3/products/11/variations/generate', array( 'meta_data' => array( array( 'key' => '_color', 'value' => 'red' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$other = $this->request( '/wc/v3/products/11', array( 'meta_data' => array( array( 'key' => self::LINK, 'value' => '3' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertInstanceOf( WP_Error::class, $this->entry->check_variation_generation( null, array(), $bad ) );
		self::assertNull( $this->entry->check_variation_generation( null, array(), $good ) );
		self::assertNull( $this->entry->check_variation_generation( null, array(), $other ), 'Other routes are checked by the pre-insert filter.' );
	}

	public function test_a_csv_row_with_protected_meta_fails_and_other_rows_pass(): void {
		$row = array(
			'name'      => 'Tee',
			'meta_data' => array(
				array(
					'key'   => '_colour',
					'value' => 'red',
				),
			),
		);

		self::assertSame( $row, $this->entry->check_import_row( $row ) );

		$row['meta_data'][] = array(
			'key'   => ' _merchboard_creator_link_source',
			'value' => 'category',
		);

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'The creator of a product can only be changed in Merch Dashboard.' );
		$this->entry->check_import_row( $row );
	}

	public function test_duplicates_start_without_the_protected_meta(): void {
		self::assertSame( array( '_sku', self::LINK, ProductCreatorLink::SOURCE_META_KEY ), $this->entry->exclude_from_duplicate( array( '_sku', self::LINK ) ) );
		self::assertSame( ProtectedProductMeta::KEYS, $this->entry->exclude_from_duplicate( null ) );
	}

	public function test_registers_the_entry_point_hooks(): void {
		$this->entry->register();

		self::assertSame( 10, has_filter( 'woocommerce_rest_pre_insert_product_object', array( $this->entry, 'check_rest_object' ) ) );
		self::assertSame( 10, has_filter( 'woocommerce_rest_pre_insert_product_variation_object', array( $this->entry, 'check_rest_object' ) ) );
		self::assertSame( 10, has_filter( 'rest_request_before_callbacks', array( $this->entry, 'check_variation_generation' ) ) );
		self::assertSame( 10, has_filter( 'woocommerce_product_import_process_item_data', array( $this->entry, 'check_import_row' ) ) );
		self::assertSame( 10, has_filter( 'woocommerce_duplicate_product_exclude_meta', array( $this->entry, 'exclude_from_duplicate' ) ) );
	}

	/**
	 * A REST request.
	 *
	 * @param string               $route  Route.
	 * @param array<string, mixed> $params Parameters.
	 */
	private function request( string $route, array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}
}
