<?php
/**
 * The product category column in the real Creators list.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\Admin\CreatorsListTable;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Creators\UserAccounts;
use MerchBoard\Modules\WooCommerce\Admin\CreatorListCategories;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Tests\Integration\IntegrationTestCase;

/**
 * WordPress' real WP_List_Table, terms and database. WooCommerce is not
 * installed, so the test registers `product_cat` and the module's column
 * itself. The admin is user 1.
 */
final class CreatorListCategoriesTest extends IntegrationTestCase {

	private CreatorListCategories $column;

	private CreatorCategoryRepository $mappings;

	/**
	 * Terms created by the test.
	 *
	 * @var list<int>
	 */
	private array $terms = array();

	/**
	 * Saved $_GET.
	 *
	 * @var array<mixed>
	 */
	private array $saved_get = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		$this->saved_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture.
		wp_set_current_user( 1 );

		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		set_current_screen( 'merch-dashboard_page_' . CreatorsAdminPage::SLUG );
		register_taxonomy( 'product_cat', 'product', array( 'hierarchical' => true ) );

		$this->mappings = new CreatorCategoryRepository( $this->wpdb );
		$this->column   = new CreatorListCategories( $this->mappings, $this->service( Logger::class ) );
		$this->column->register();
	}

	protected function tearDown(): void {
		remove_filter( 'merchboard_creators_list_columns', array( $this->column, 'columns' ) );
		remove_action( 'merchboard_creators_list_items', array( $this->column, 'load' ) );
		remove_filter( 'merchboard_creators_list_column', array( $this->column, 'render' ) );

		foreach ( $this->terms as $term_id ) {
			wp_delete_term( $term_id, 'product_cat' );
		}

		unregister_taxonomy( 'product_cat' );
		$_GET     = $this->saved_get;
		$_REQUEST = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_the_list_shows_one_several_none_and_deleted_categories(): void {
		$creators = $this->service( CreatorService::class );
		$alpha    = $creators->create( 'List Alpha', CreatorStatus::Active, null )->id;
		$beta     = $creators->create( 'List Beta', CreatorStatus::Active, null )->id;
		$gamma    = $creators->create( 'List Gamma', CreatorStatus::Inactive, null )->id;
		$main     = $this->term( 'List Alpha' );
		$extra    = $this->term( 'List Extra' );
		$gone     = $this->term( 'List Gone' );
		$this->mappings->insert( $main, $alpha, 1 );
		$this->mappings->insert( $extra, $alpha, 1 );
		$this->mappings->insert( $gone, $beta, 1 );
		wp_delete_term( $gone, 'product_cat' ); // As if deleted without the WooCommerce module.

		$html = $this->render();

		self::assertStringContainsString( '>Product categories<', $html );
		self::assertSame( 'List Alpha, List Extra', $this->cell( $html, $alpha ) );
		self::assertSame( '<em>Deleted category (#' . $gone . ')</em>', $this->cell( $html, $beta ) );
		self::assertStringContainsString( 'No product category', $this->cell( $html, $gamma ) );
		self::assertCount( 3, $this->mappings->all(), 'The orphaned mapping is not cleaned up.' );
	}

	public function test_the_number_of_queries_does_not_grow_with_the_rows(): void {
		$creators = $this->service( CreatorService::class );
		$first    = $creators->create( 'Query 1', CreatorStatus::Active, null )->id;
		$this->mappings->insert( $this->term( 'Query cat 1' ), $first, 1 );
		$this->render(); // Warm-up: the first list render in a process does one-off work.
		wp_cache_flush();
		$few = $this->queries();

		for ( $i = 2; $i <= 8; $i++ ) {
			$id = $creators->create( 'Query ' . $i, CreatorStatus::Active, null )->id;
			$this->mappings->insert( $this->term( 'Query cat ' . $i ), $id, 1 );
		}
		wp_cache_flush();
		$many = $this->queries();

		self::assertSame( $few, $many, 'The column needs the same queries for 1 and 8 creators with categories.' );
		self::assertLessThanOrEqual( 3, $many, 'Mappings, terms and their meta cache at most.' );
	}

	public function test_rendering_changes_no_data(): void {
		$creators = $this->service( CreatorService::class );
		$alpha    = $creators->create( 'Readonly Alpha', CreatorStatus::Active, null )->id;
		$this->mappings->insert( $this->term( 'Readonly cat' ), $alpha, 1 );
		$product = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Readonly tee' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, $product, ProductCreatorLink::META_KEY, (string) $alpha );
		$before = $this->snapshot();

		$this->render();

		self::assertSame( $before, $this->snapshot() );
		$this->execute( 'DELETE FROM %i WHERE post_id = %d', $this->wpdb->postmeta, $product );
		$this->execute( 'DELETE FROM %i WHERE ID = %d', $this->wpdb->posts, $product );
	}

	public function test_without_the_capability_there_is_no_column(): void {
		wp_set_current_user( 0 );

		self::assertStringNotContainsString( 'Product categories', $this->render() );
	}

	/**
	 * Renders the Creators list like CreatorsAdminPage.
	 */
	private function render(): string {
		$get      = array( 'page' => CreatorsAdminPage::SLUG );
		$_GET     = $get;
		$_REQUEST = $get; // WP_List_Table reads the page number from $_REQUEST, as in a real GET request.
		$table    = new CreatorsListTable( $this->service( CreatorRepository::class ), $this->service( UserAccounts::class ) );
		ob_start();
		$table->prepare_items();
		$table->display();

		return (string) ob_get_clean();
	}

	/**
	 * Queries the column adds to one render (with minus without the column,
	 * each with a cold cache).
	 */
	private function queries(): int {
		wp_cache_flush();
		$before = $this->wpdb->num_queries;
		$this->render();
		$with = $this->wpdb->num_queries - $before;

		remove_filter( 'merchboard_creators_list_columns', array( $this->column, 'columns' ) );
		remove_action( 'merchboard_creators_list_items', array( $this->column, 'load' ) );
		remove_filter( 'merchboard_creators_list_column', array( $this->column, 'render' ) );
		wp_cache_flush();
		$before = $this->wpdb->num_queries;
		$this->render();
		$without = $this->wpdb->num_queries - $before;
		$this->column->register();

		return $with - $without;
	}

	/**
	 * The column's cell in the row of a creator.
	 *
	 * @param string $html       Rendered list.
	 * @param int    $creator_id Creator id.
	 */
	private function cell( string $html, int $creator_id ): string {
		$pattern = '#creator=' . $creator_id . '".*?<td class=\'' . CreatorListCategories::COLUMN . ' column-' . CreatorListCategories::COLUMN . '\'\s*data-colname="Product categories"\s*>(.*?)</td>#s';

		self::assertSame( 1, preg_match( $pattern, $html, $matches ), 'Row of creator ' . $creator_id );

		return $matches[1];
	}

	/**
	 * Creates a `product_cat` term.
	 *
	 * @param string $name Name.
	 */
	private function term( string $name ): int {
		$term = wp_insert_term( $name, 'product_cat' );
		self::assertIsArray( $term );
		$this->terms[] = (int) $term['term_id'];

		return (int) $term['term_id'];
	}

	/**
	 * Every plugin table and all post meta.
	 *
	 * @return array<string, mixed>
	 * @phpstan-impure
	 */
	private function snapshot(): array {
		$snapshot = array( 'meta' => $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY meta_id', $this->wpdb->postmeta ), ARRAY_A ) );

		foreach ( Tables::all() as $table ) {
			$snapshot[ $table ] = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $this->table( $table ) ), ARRAY_A );
		}

		return $snapshot;
	}
}
