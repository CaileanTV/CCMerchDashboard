<?php
/**
 * Tests for the WooCommerce product storage (CRUD writes and verification).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce;

use Brain\Monkey\Functions;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ProductLinkSource;
use MerchBoard\Modules\WooCommerce\ProductRecord;
use MerchBoard\Modules\WooCommerce\WooCommerceProductStore;
use MerchBoard\Tests\Unit\Support\WcProductStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use wpdb;

/**
 * Every test runs in its own process: the WooCommerce stand-in defines global
 * classes and functions.
 */
#[CoversClass( WooCommerceProductStore::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WooCommerceProductStoreTest extends TestCase {

	private const KEY    = ProductCreatorLink::META_KEY;
	private const SOURCE = ProductCreatorLink::SOURCE_META_KEY;

	private WooCommerceProductStore $store;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/Fixtures/woocommerce-product.php';

		WcProductStorage::$products           = array();
		WcProductStorage::$meta               = array();
		WcProductStorage::$drop_writes        = false;
		WcProductStorage::$next_meta_id       = 100;
		WcProductStorage::$invalidated_groups = array();
		WcProductStorage::$queries            = array();
		WcProductStorage::$searches           = array();
		WcProductStorage::$categories         = array();

		Functions\when( 'get_terms' )->alias( array( WcProductStorage::class, 'category_ids_like' ) );

		$this->store = new WooCommerceProductStore( self::database() );
	}

	public function test_find_reports_kind_status_and_every_link_spelling(): void {
		WcProductStorage::add(
			10,
			array(
				self::KEY                => '3',
				'_Merchboard_Creator_ID' => '4',
				'_price'                 => '10',
			)
		);
		WcProductStorage::add( 11, array(), 'variation', 10 );

		$product = $this->store->find( 10 );

		self::assertNotNull( $product );
		self::assertFalse( $product->is_variation );
		self::assertSame( 'publish', $product->status );
		self::assertSame(
			array(
				array(
					'key'   => self::KEY,
					'value' => '3',
				),
				array(
					'key'   => '_Merchboard_Creator_ID',
					'value' => '4',
				),
			),
			$product->link_entries
		);
		self::assertNull( $product->creator_id(), 'Two entries are not one valid link.' );

		$variation = $this->store->find( 11 );
		self::assertNotNull( $variation );
		self::assertTrue( $variation->is_variation );
		self::assertSame( 10, $variation->parent_id );
	}

	public function test_linked_to_lists_parent_products_of_the_creator_in_every_status_but_auto_draft(): void {
		WcProductStorage::add( 10, array( self::KEY => '3' ), 'simple', 0, 'Tee', 'T-1' );
		WcProductStorage::add( 11, array( '_Merchboard_Creator_ID' => '3' ), 'simple', 0, 'Mug', '', 'trash' );
		WcProductStorage::add( 12, array( self::KEY => '4' ) );
		WcProductStorage::add( 13, array( self::KEY => '3' ), 'variation', 10 );
		WcProductStorage::add( 14, array( self::KEY => '3' ), 'simple', 0, '', '', 'auto-draft' );

		$linked = $this->store->linked_to( 3, 50 );

		self::assertSame( array( 10, 11 ), array_map( static fn( $p ): int => $p->id, $linked ) );
		self::assertSame( array( 'Tee', 'T-1' ), array( $linked[0]->name, $linked[0]->sku ) );
		self::assertTrue( $linked[1]->has_invalid_link(), 'An invalid spelling is listed so it can be repaired.' );
		self::assertSame( self::KEY, WcProductStorage::$queries[0]['meta_key'] );
		self::assertSame( '3', WcProductStorage::$queries[0]['meta_value'] );
		self::assertSame( 'ids', WcProductStorage::$queries[0]['return'] );
		self::assertSame( 50, WcProductStorage::$queries[0]['limit'] );
		self::assertNotContains( 'auto-draft', WcProductStorage::$queries[0]['status'] );
		self::assertSame( array(), $this->store->linked_to( 0, 50 ) );
	}

	public function test_search_uses_woocommerce_product_search_for_parents_in_all_statuses(): void {
		WcProductStorage::add( 10, array(), 'simple', 0, 'Alpha Tee', 'AT-1' );
		WcProductStorage::add( 11, array(), 'simple', 0, 'Beta Mug', 'TEE-SKU', 'draft' );
		WcProductStorage::add( 12, array(), 'variation', 10, 'Alpha Tee - S' );

		$found = $this->store->search( ' tee ', 20 );

		self::assertSame( array( 10, 11 ), array_map( static fn( $p ): int => $p->id, $found ) );
		self::assertSame( array( 'tee', '', false, true, 20 ), WcProductStorage::$searches[0] );
		self::assertSame( array(), $this->store->search( '   ', 20 ), 'An empty term does not search.' );
		self::assertCount( 1, WcProductStorage::$searches );
	}

	public function test_search_by_product_name_still_finds_the_product(): void {
		WcProductStorage::add( 10, array(), 'simple', 0, 'Alpha Hoodie', 'AH-1' );
		WcProductStorage::add( 11, array(), 'simple', 0, 'Beta Mug', 'BM-1' );

		self::assertSame( array( 10 ), self::ids( $this->store->search( 'hoodie', 20 ) ) );
	}

	public function test_search_by_sku_still_finds_the_product(): void {
		WcProductStorage::add( 10, array(), 'simple', 0, 'Alpha Hoodie', 'AH-1' );
		WcProductStorage::add( 11, array(), 'simple', 0, 'Beta Mug', 'BM-1' );

		self::assertSame( array( 11 ), self::ids( $this->store->search( 'BM-1', 20 ) ) );
	}

	public function test_search_by_category_finds_parents_directly_in_the_category_in_all_statuses(): void {
		self::categories();
		WcProductStorage::add( 10, array(), 'simple', 0, 'Alpha Tee', '', 'publish', array( 16 ) );
		WcProductStorage::add( 11, array(), 'variable', 0, 'Alpha Hoodie', '', 'draft', array( 16, 18 ) );
		WcProductStorage::add( 12, array(), 'variation', 11, 'Alpha Hoodie - S', '', 'publish', array( 16 ) );
		WcProductStorage::add( 13, array(), 'simple', 0, 'Beta Mug', '', 'publish', array( 17 ) );
		WcProductStorage::add( 14, array(), 'simple', 0, 'Alpha Poster', '', 'publish', array( 20 ) );
		WcProductStorage::add( 15, array(), 'simple', 0, 'Loose Sticker', '', 'publish', array( 15 ) );

		self::assertSame( array( 10, 11 ), self::ids( $this->store->search( 'creator alpha', 20 ) ), 'Only direct members; no variation, no other category, no product only in a child category.' );
		self::assertSame( array( 13 ), self::ids( $this->store->search( 'Creator Beta', 20 ) ) );
		self::assertSame( array( 11 ), self::ids( $this->store->search( 'General Merchandise', 20 ) ) );
		self::assertSame( array( 15 ), self::ids( $this->store->search( 'Uncategorized', 20 ) ), 'Uncategorized is an ordinary product category.' );

		$query = WcProductStorage::$queries[0];
		self::assertSame(
			array(
				array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => array( 16 ),
					'include_children' => false,
				),
			),
			$query['tax_query']
		);
		self::assertSame( 'ids', $query['return'] );
		self::assertSame( 20, $query['limit'] );
		self::assertContains( 'draft', $query['status'] );
		self::assertSame( array(), WcProductStorage::$meta[10], 'Searching writes no link.' );
	}

	public function test_search_without_a_matching_category_makes_no_category_query(): void {
		self::categories();
		WcProductStorage::add( 10, array(), 'simple', 0, 'Alpha Tee', '', 'publish', array( 16 ) );

		self::assertSame( array(), $this->store->search( 'Nothing', 20 ) );
		self::assertSame( array(), WcProductStorage::$queries );
	}

	public function test_a_product_matching_by_name_and_category_is_listed_once_name_matches_first(): void {
		self::categories();
		WcProductStorage::add( 10, array(), 'simple', 0, 'Tee', '', 'publish', array( 16 ) );
		WcProductStorage::add( 11, array(), 'simple', 0, 'Mug for Creator Alpha fans', '', 'publish', array( 16 ) );

		self::assertSame( array( 11, 10 ), self::ids( $this->store->search( 'Creator Alpha', 20 ) ) );
	}

	public function test_combined_search_respects_the_limit(): void {
		self::categories();

		for ( $id = 100; $id < 115; $id++ ) {
			WcProductStorage::add( $id, array(), 'simple', 0, 'Alpha item ' . $id, '', 'publish', array( 18 ) );
		}

		for ( $id = 200; $id < 215; $id++ ) {
			WcProductStorage::add( $id, array(), 'simple', 0, 'Product ' . $id, '', 'publish', array( 16 ) );
		}

		$found = $this->store->search( 'Alpha', 20 );

		self::assertCount( 20, $found );
		self::assertSame( array_merge( range( 100, 114 ), range( 200, 204 ) ), self::ids( $found ) );
		self::assertSame( 20, WcProductStorage::$searches[0][4] );
		self::assertSame( 20, WcProductStorage::$queries[0]['limit'] );
	}

	public function test_find_returns_null_for_unknown_products(): void {
		self::assertNull( $this->store->find( 999 ) );
		self::assertNull( $this->store->find( 0 ) );
	}

	public function test_write_replaces_every_spelling_with_one_exact_entry_and_keeps_other_meta(): void {
		WcProductStorage::add(
			10,
			array(
				'_price'                 => '10',
				'_Merchboard_Creator_ID' => '4',
				' ' . self::KEY          => 'x',
			)
		);

		$this->store->write_creator( 10, 7 );

		self::assertSame( array( '_price' => '10', self::KEY => '7', self::SOURCE => 'admin' ), self::stored( 10 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$product = $this->store->find( 10 );
		self::assertNotNull( $product );
		self::assertSame( 7, $product->creator_id() );
		self::assertFalse( $product->is_category_managed(), 'A manual link.' );
	}

	public function test_a_category_link_stores_its_origin_and_replaces_every_origin_spelling(): void {
		WcProductStorage::add(
			10,
			array(
				self::KEY                         => '3',
				'_Merchboard_Creator_Link_Source' => 'admin',
				'_price'                          => '10',
			),
			'simple',
			0,
			'',
			'',
			'publish',
			array( 16, 19 )
		);

		$this->store->write_creator( 10, 4, ProductLinkSource::Category );

		self::assertSame( array( '_price' => '10', self::KEY => '4', self::SOURCE => 'category' ), self::stored( 10 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$record = $this->store->find( 10 );
		self::assertNotNull( $record );
		self::assertTrue( $record->is_category_managed() );
		self::assertSame( array( 16, 19 ), $record->category_ids );

		$this->store->write_creator( 10, null );
		self::assertSame( array( '_price' => '10' ), self::stored( 10 ), 'Removing a link removes its origin.' );
	}

	public function test_a_link_without_origin_or_with_an_odd_origin_is_manual(): void {
		WcProductStorage::add( 10, array( self::KEY => '3' ) );
		WcProductStorage::add(
			11,
			array(
				self::KEY                         => '3',
				'_Merchboard_Creator_Link_Source' => 'category',
			)
		);
		WcProductStorage::add(
			12,
			array(
				self::KEY    => '3',
				self::SOURCE => 'something',
			)
		);

		foreach ( array( 10, 11, 12 ) as $id ) {
			$record = $this->store->find( $id );
			self::assertNotNull( $record );
			self::assertFalse( $record->is_category_managed(), (string) $id );
			self::assertTrue( $record->has_manual_link(), (string) $id );
		}
	}

	public function test_in_category_lists_parents_directly_in_the_category_without_trash(): void {
		WcProductStorage::$categories = array(
			16 => array(
				'name'   => 'Creator Alpha',
				'parent' => 0,
			),
			20 => array(
				'name'   => 'Posters',
				'parent' => 16,
			),
		);
		WcProductStorage::add( 10, array(), 'simple', 0, '', '', 'publish', array( 16 ) );
		WcProductStorage::add( 11, array(), 'simple', 0, '', '', 'draft', array( 16, 19 ) );
		WcProductStorage::add( 12, array(), 'simple', 0, '', '', 'trash', array( 16 ) );
		WcProductStorage::add( 13, array(), 'variation', 10, '', '', 'publish', array( 16 ) );
		WcProductStorage::add( 14, array(), 'simple', 0, '', '', 'publish', array( 20 ) );

		self::assertSame( array( 10, 11 ), $this->store->in_category( 16, 100, 0 ) );
		$query = WcProductStorage::$queries[0];
		self::assertFalse( $query['tax_query'][0]['include_children'] );
		self::assertNotContains( 'trash', $query['status'] );
		self::assertNotContains( 'auto-draft', $query['status'] );
		self::assertSame( array(), $this->store->in_category( 0, 100, 0 ) );
	}

	public function test_write_null_removes_every_spelling_and_keeps_other_meta(): void {
		WcProductStorage::add(
			10,
			array(
				self::KEY                => '3',
				'_MERCHBOARD_CREATOR_ID' => '4',
				'_sku'                   => 'A1',
			)
		);

		$this->store->write_creator( 10, null );

		self::assertSame( array( '_sku' => 'A1' ), self::stored( 10 ) );
	}

	public function test_a_write_that_is_not_stored_is_reported(): void {
		WcProductStorage::add( 10 );
		WcProductStorage::$drop_writes = true;

		$this->expectException( DatabaseException::class );

		$this->store->write_creator( 10, 7 );
	}

	public function test_writing_a_missing_product_is_reported(): void {
		$this->expectException( DatabaseException::class );

		$this->store->write_creator( 999, 7 );
	}

	public function test_forget_drops_the_post_cache_transients_and_woocommerce_object_meta_cache(): void {
		Functions\expect( 'clean_post_cache' )->once()->with( 10 );

		$this->store->forget( 10 );

		self::assertSame( array( 10 ), $GLOBALS['merchboard_test_deleted_transients'] ?? array() );
		self::assertSame( array( 'object_10' ), WcProductStorage::$invalidated_groups );
	}

	/**
	 * Product ids of records.
	 *
	 * @param ProductRecord[] $records Records.
	 * @return list<int>
	 */
	private static function ids( array $records ): array {
		return array_values( array_map( static fn( ProductRecord $p ): int => $p->id, $records ) );
	}

	/**
	 * The synthetic categories of Merch Dashboard Local, plus a child of
	 * Creator Alpha (20).
	 */
	private static function categories(): void {
		WcProductStorage::$categories = array(
			15 => array(
				'name'   => 'Uncategorized',
				'parent' => 0,
			),
			16 => array(
				'name'   => 'Creator Alpha',
				'parent' => 0,
			),
			17 => array(
				'name'   => 'Creator Beta',
				'parent' => 0,
			),
			18 => array(
				'name'   => 'General Merchandise',
				'parent' => 0,
			),
			20 => array(
				'name'   => 'Posters',
				'parent' => 16,
			),
		);
	}

	/**
	 * Stored meta of a product as key => value.
	 *
	 * @param int $id Product id.
	 * @return array<string, string>
	 */
	private static function stored( int $id ): array {
		return array_column( WcProductStorage::$meta[ $id ] ?? array(), 'value', 'key' );
	}

	/**
	 * A wpdb that answers the verification query from the stand-in storage,
	 * comparing keys like MySQL (case-insensitive, trimmed).
	 */
	private static function database(): wpdb {
		$db           = new class( '', '', '', '' ) extends wpdb {

			/**
			 * Keeps the query and its values.
			 *
			 * @param string $query   Query.
			 * @param mixed  ...$args Values.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				return (string) wp_json_encode( array( $query, $args ) );
			}

			/**
			 * Rows of the verification query.
			 *
			 * @param string|null $query  Prepared query.
			 * @param string      $output Output type.
			 * @return list<array{meta_key: string, meta_value: string}>
			 */
			public function get_results( $query = null, $output = 'OBJECT' ) {
				[ , $args ] = json_decode( (string) $query, true );
				$rows       = array();

				foreach ( WcProductStorage::$meta[ (int) $args[1] ] ?? array() as $row ) {
					if ( in_array( strtolower( trim( $row['key'] ) ), array_slice( $args, 2 ), true ) ) {
						$rows[] = array(
							'meta_key'   => $row['key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test stand-in row, not a query.
							'meta_value' => $row['value'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test stand-in row, not a query.
						);
					}
				}

				return $rows;
			}
		};
		$db->postmeta = 'wp_postmeta';

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		return $db;
	}
}
