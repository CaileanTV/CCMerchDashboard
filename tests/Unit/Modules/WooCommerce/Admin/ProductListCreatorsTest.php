<?php
/**
 * Tests for the read-only creator column and filter in the product list.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Admin;

use Brain\Monkey\Functions;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\ProductListCreators;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\FakeProductStore;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ProductListCreators::class )]
#[CoversClass( CreatorRepository::class )]
final class ProductListCreatorsTest extends TestCase {

	use CreatorAdminEnvironment;

	private FakeProductStore $products;

	private ProductListCreators $list;

	private Creator $alpha;

	private Creator $beta;

	private Creator $retired;

	private string $screen = 'edit-product';

	/**
	 * Audit rows after the fixture was created.
	 *
	 * @var int
	 */
	private int $audit_before = 0;

	/**
	 * Saved $_GET.
	 *
	 * @var array<mixed>
	 */
	private array $saved_get = array();

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_creator_admin();
		$this->saved_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture.
		$_GET            = array();

		$this->beta    = $this->service->create( 'Beta <Studio>', CreatorStatus::Active, null );
		$this->alpha   = $this->service->create( 'Alpha', CreatorStatus::Active, null );
		$this->retired = $this->service->create( 'Retired', CreatorStatus::Inactive, null );

		$this->products = new FakeProductStore();
		$this->products->add( 10, array(), 'publish', 0, 'Plain tee' );
		$this->products->add_linked( 11, $this->alpha->id );
		$this->products->add_linked( 12, $this->beta->id );
		$this->products->add_linked( 13, 999 );
		$this->products->add( 14, array( array( 'key' => '_Merchboard_Creator_ID', 'value' => (string) $this->alpha->id ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->products->add( 21, array(), 'publish', 11, 'Alpha tee - S' );

		$this->list         = new ProductListCreators( $this->products, new CreatorRepository( $this->db ) );
		$this->audit_before = count( $this->audit_rows() );

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $s ): string => trim( strip_tags( $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'get_current_screen' )->alias( fn(): object => (object) array( 'id' => $this->screen ) );
	}

	protected function tearDown(): void {
		$_GET = $this->saved_get;
		parent::tearDown();
	}

	public function test_a_product_without_creator_shows_an_empty_marker(): void {
		$html = $this->cell( 10 );

		self::assertStringContainsString( '&#8212;', $html );
		self::assertStringContainsString( 'No creator', $html );
		self::assertStringNotContainsString( '<a ', $html );
	}

	public function test_a_linked_product_shows_its_creator_linked_to_the_edit_page(): void {
		$html = $this->cell( 11 );

		self::assertStringStartsWith( '<a href="https://shop.test/wp-admin/admin.php?page=merchboard-creators', $html );
		self::assertStringContainsString( 'creator=' . $this->alpha->id . '">Alpha</a>', $html );
		self::assertStringContainsString( 'Beta &lt;Studio&gt;', $this->cell( 12 ), 'Creator names are escaped.' );
	}

	public function test_invalid_and_dangling_links_are_shown_safely(): void {
		self::assertSame( 'Creator #999 (not found)', $this->cell( 13 ) );
		self::assertSame( 'Invalid link', $this->cell( 14 ), 'Another key spelling is not a creator.' );
		self::assertStringContainsString( 'No creator', $this->cell( 404 ), 'An unknown product shows no creator.' );
	}

	public function test_a_variation_follows_its_parent_and_gets_no_own_relation(): void {
		self::assertSame( $this->cell( 11 ), $this->cell( 21 ) );
		self::assertSame( array(), $this->products->entries( 21 ), 'The variation keeps no link entry of its own.' );
	}

	public function test_the_column_is_added_before_the_date_column(): void {
		$columns = $this->list->columns(
			array(
				'cb'          => '',
				'name'        => 'Name',
				'product_cat' => 'Categories',
				'date'        => 'Date',
			)
		);

		self::assertSame( array( 'cb', 'name', 'product_cat', ProductListCreators::COLUMN, 'date' ), array_keys( $columns ) );
		self::assertSame( 'Creator', $columns[ ProductListCreators::COLUMN ] );
		self::assertSame( array( 'name', ProductListCreators::COLUMN ), array_keys( $this->list->columns( array( 'name' => 'Name' ) ) ) );
	}

	public function test_other_columns_are_left_to_woocommerce(): void {
		ob_start();
		$this->list->render_column( 'sku', 11 );

		self::assertSame( '', ob_get_clean() );
	}

	public function test_the_filter_lists_every_existing_creator_by_name_with_all_creators_first(): void {
		$_GET[ ProductListCreators::FILTER_ARG ] = (string) $this->beta->id;

		$html = $this->filter_html();

		self::assertStringContainsString( '<option value="">All creators</option>', $html );
		self::assertSame( 4, substr_count( $html, '<option ' ) );
		self::assertLessThan( strpos( $html, 'Beta &lt;Studio&gt;' ), strpos( $html, '>Alpha<' ), 'Sorted by name.' );
		self::assertStringContainsString( '<option value="' . $this->beta->id . '" selected="selected">Beta &lt;Studio&gt;</option>', $html );
		self::assertStringContainsString( '<option value="' . $this->retired->id . '">Retired (inactive)</option>', $html, 'Inactive creators may still own products.' );
		self::assertStringContainsString( 'name="' . ProductListCreators::FILTER_ARG . '"', $html );
	}

	public function test_the_filter_is_only_printed_once_above_the_product_list(): void {
		ob_start();
		$this->list->render_filter( 'product', 'bottom' );
		$this->list->render_filter( 'shop_order', 'top' );

		self::assertSame( '', ob_get_clean() );
	}

	public function test_the_filter_limits_the_query_to_the_selected_creator_link(): void {
		$_GET[ ProductListCreators::FILTER_ARG ] = (string) $this->alpha->id;

		$vars = $this->list->filter_request( array( 'post_type' => 'product' ) );

		self::assertSame(
			array(
				array(
					'key'     => ProductCreatorLink::META_KEY,
					'value'   => (string) $this->alpha->id,
					'compare' => '=',
				),
			),
			$vars['meta_query']
		);
		self::assertArrayNotHasKey( 'tax_query', $vars, 'Never filtered by category.' );
	}

	public function test_all_creators_and_invalid_values_leave_the_query_unchanged(): void {
		$vars = array(
			'post_type'   => 'product',
			'post_status' => 'draft',
		);

		foreach ( array( null, '', '0', '-1', 'abc', '999', $this->alpha->id . ' OR 1=1' ) as $value ) {
			$_GET = null === $value ? array() : array( ProductListCreators::FILTER_ARG => $value );
			self::assertSame( $vars, $this->list->filter_request( $vars ), 'Value: ' . var_export( $value, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Assertion message.
		}
	}

	public function test_the_filter_combines_with_existing_woocommerce_filters(): void {
		$_GET[ ProductListCreators::FILTER_ARG ] = (string) $this->beta->id;
		$existing                                = array(
			'relation' => 'OR',
			array(
				'key'   => '_featured',
				'value' => 'yes',
			),
		);

		$vars = $this->list->filter_request(
			array(
				'post_type'    => 'product',
				'product_type' => 'simple',
				'product_cat'  => 'general-merchandise',
				'tax_query'    => array( array( 'taxonomy' => 'product_shipping_class' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Query vars under test.
				'meta_query'   => $existing, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Query vars under test.
			)
		);

		self::assertSame( 'simple', $vars['product_type'] );
		self::assertSame( 'general-merchandise', $vars['product_cat'], 'WooCommerce\'s category filter is kept, not replaced.' );
		self::assertSame( array( array( 'taxonomy' => 'product_shipping_class' ) ), $vars['tax_query'] );
		self::assertSame( 'AND', $vars['meta_query']['relation'] );
		self::assertSame( $existing, $vars['meta_query'][0], 'An existing meta query is kept intact.' );
		self::assertSame( (string) $this->beta->id, $vars['meta_query'][1]['value'] );
	}

	public function test_other_post_types_are_not_filtered(): void {
		$_GET[ ProductListCreators::FILTER_ARG ] = (string) $this->alpha->id;

		self::assertSame( array( 'post_type' => 'shop_order' ), $this->list->filter_request( array( 'post_type' => 'shop_order' ) ) );
	}

	public function test_hooks_are_added_only_on_the_product_list_for_users_who_may_see_creators(): void {
		$this->list->on_screen();

		self::assertSame( 20, has_filter( 'manage_product_posts_columns', array( $this->list, 'columns' ) ) );
		self::assertSame( 10, has_action( 'manage_product_posts_custom_column', array( $this->list, 'render_column' ) ) );
		self::assertSame( 20, has_action( 'restrict_manage_posts', array( $this->list, 'render_filter' ) ) );
		self::assertSame( 20, has_filter( 'request', array( $this->list, 'filter_request' ) ) );
	}

	public function test_users_without_the_creator_capability_get_nothing(): void {
		$this->caps = array( Capabilities::EDIT_PRODUCTS );

		$this->list->on_screen();

		self::assertFalse( has_filter( 'manage_product_posts_columns' ) );
		self::assertFalse( has_action( 'manage_product_posts_custom_column' ) );
		self::assertFalse( has_action( 'restrict_manage_posts' ) );
		self::assertFalse( has_filter( 'request' ), 'The filter parameter cannot be used to probe ownership.' );
	}

	public function test_other_screens_get_nothing(): void {
		$this->screen = 'edit-shop_order';

		$this->list->on_screen();

		self::assertFalse( has_filter( 'manage_product_posts_columns' ) );
		self::assertFalse( has_filter( 'request' ) );
	}

	public function test_reading_the_list_writes_nothing_and_records_no_audit(): void {
		$_GET[ ProductListCreators::FILTER_ARG ] = (string) $this->alpha->id;

		$this->list->on_screen();
		foreach ( array( 10, 11, 12, 13, 14, 21 ) as $id ) {
			$this->cell( $id );
		}
		$this->filter_html();
		$this->list->filter_request( array( 'post_type' => 'product' ) );

		self::assertSame( array(), $this->products->writes );
		self::assertSame( array(), $this->products->forgotten );
		self::assertCount( $this->audit_before, $this->audit_rows() );
	}

	public function test_all_reads_every_creator_across_pages_ordered_by_name(): void {
		for ( $i = 1; $i <= 101; $i++ ) {
			$this->service->create( sprintf( 'Creator %03d', $i ), CreatorStatus::Active, null );
		}

		$all = ( new CreatorRepository( $this->db ) )->all();

		self::assertCount( 104, $all );
		self::assertSame( 'Alpha', $all[0]->display_name );
		self::assertSame( 'Retired', $all[103]->display_name );
	}

	/**
	 * The rendered creator cell of a product.
	 *
	 * @param int $post_id Product id.
	 */
	private function cell( int $post_id ): string {
		ob_start();
		$this->list->render_column( ProductListCreators::COLUMN, $post_id );

		return (string) ob_get_clean();
	}

	/**
	 * The rendered creator filter.
	 */
	private function filter_html(): string {
		ob_start();
		$this->list->render_filter( 'product', 'top' );

		return (string) ob_get_clean();
	}
}
