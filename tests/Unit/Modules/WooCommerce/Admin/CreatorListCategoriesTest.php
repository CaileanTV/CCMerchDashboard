<?php
/**
 * Tests for the product category column in the Creators list.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Admin;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\CreatorListCategories;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WP_Error;
use WP_Term;

/**
 * Separate processes: the WP_Term stand-in defines a global class.
 */
#[CoversClass( CreatorListCategories::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CreatorListCategoriesTest extends TestCase {

	use CreatorAdminEnvironment;

	private const ALPHA_CAT = 16;
	private const EXTRA_CAT = 18;
	private const BETA_CAT  = 17;

	private CreatorCategoryRepository $mappings;

	private CreatorListCategories $column;

	private Creator $alpha;

	private Creator $beta;

	private Creator $gamma;

	/**
	 * Existing `product_cat` terms: id => name.
	 *
	 * @var array<int, string>
	 */
	private array $terms = array(
		self::ALPHA_CAT => 'Alpha <merch>',
		self::BETA_CAT  => 'Beta merch',
		self::EXTRA_CAT => 'Alpha extra',
	);

	/**
	 * Calls to get_terms().
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $term_queries = array();

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 4 ) . '/Fixtures/wp-term.php';
		$this->set_up_creator_admin();

		$this->alpha = $this->service->create( 'Alpha', CreatorStatus::Active, null );
		$this->beta  = $this->service->create( 'Beta', CreatorStatus::Active, null );
		$this->gamma = $this->service->create( 'Gamma', CreatorStatus::Inactive, null );

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'get_terms' )->alias(
			function ( array $args ): array {
				$this->term_queries[] = $args;
				$terms                = array();

				foreach ( $args['include'] as $id ) {
					if ( isset( $this->terms[ $id ] ) ) {
						$terms[] = new WP_Term(
							(object) array(
								'term_id'  => $id,
								'taxonomy' => 'product_cat',
								'name'     => $this->terms[ $id ],
							)
						);
					}
				}

				return $terms;
			}
		);

		$this->mappings = new CreatorCategoryRepository( $this->db );
		$this->column   = new CreatorListCategories( $this->mappings, new RecordingLogger() );
	}

	public function test_the_column_is_hooked_into_the_creators_list(): void {
		Filters\expectAdded( 'merchboard_creators_list_columns' )->once();
		Actions\expectAdded( 'merchboard_creators_list_items' )->once();
		Filters\expectAdded( 'merchboard_creators_list_column' )->once();

		$this->column->register();
	}

	public function test_the_column_is_added_only_for_creator_managers(): void {
		self::assertSame( array( 'other' => 'Other', CreatorListCategories::COLUMN => 'Product categories' ), $this->column->columns( array( 'other' => 'Other' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->caps = array( Capabilities::ACCESS_DASHBOARD );
		self::assertSame( array(), $this->column->columns( array() ) );
		self::assertSame( 'untouched', $this->column->render( 'untouched', CreatorListCategories::COLUMN, $this->alpha ) );
	}

	public function test_one_several_and_no_categories(): void {
		$this->mappings->insert( self::ALPHA_CAT, $this->alpha->id, 7 );
		$this->mappings->insert( self::EXTRA_CAT, $this->alpha->id, 7 );
		$this->mappings->insert( self::BETA_CAT, $this->beta->id, 7 );
		$this->column->load();

		self::assertSame( 'Alpha &lt;merch&gt;, Alpha extra', $this->render( $this->alpha ) );
		self::assertSame( 'Beta merch', $this->render( $this->beta ) );
		self::assertSame( '<span aria-hidden="true">—</span><span class="screen-reader-text">No product category</span>', $this->render( $this->gamma ) );
	}

	public function test_an_orphaned_mapping_is_shown_as_a_deleted_category_and_kept(): void {
		$this->mappings->insert( self::ALPHA_CAT, $this->alpha->id, 7 );
		unset( $this->terms[ self::ALPHA_CAT ] );
		$rows = $this->db->rows( Tables::CREATOR_CATEGORIES );

		self::assertSame( '<em>Deleted category (#16)</em>', $this->render( $this->alpha ) );
		self::assertSame( $rows, $this->db->rows( Tables::CREATOR_CATEGORIES ), 'Not cleaned up from a read-only view.' );
	}

	public function test_all_rows_are_served_from_one_load(): void {
		$this->mappings->insert( self::ALPHA_CAT, $this->alpha->id, 7 );
		$this->mappings->insert( self::BETA_CAT, $this->beta->id, 7 );

		$this->column->load();
		foreach ( array( $this->alpha, $this->beta, $this->gamma, $this->alpha ) as $creator ) {
			$this->render( $creator );
		}

		self::assertCount( 1, $this->term_queries, 'One get_terms() call for the whole page.' );
		self::assertSame( array( self::ALPHA_CAT, self::BETA_CAT ), $this->term_queries[0]['include'] );
		self::assertSame( 'product_cat', $this->term_queries[0]['taxonomy'] );
	}

	public function test_without_mappings_no_terms_are_read(): void {
		$this->column->load();

		self::assertStringContainsString( 'No product category', $this->render( $this->alpha ) );
		self::assertSame( array(), $this->term_queries );
	}

	public function test_unreadable_categories_are_not_shown_as_deleted(): void {
		require_once dirname( __DIR__, 4 ) . '/Fixtures/wp-rest.php';
		$this->mappings->insert( self::ALPHA_CAT, $this->alpha->id, 7 );
		Functions\when( 'get_terms' )->justReturn( new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' ) );

		self::assertSame( 'Could not be loaded', $this->render( $this->alpha ) );
	}

	public function test_rendering_writes_nothing(): void {
		$this->mappings->insert( self::ALPHA_CAT, $this->alpha->id, 7 );
		$tables = $this->tables();
		Actions\expectDone( 'merchboard_creator_category_unlinked' )->never();
		Functions\expect( 'add_post_meta' )->never();
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->never();
		Functions\expect( 'as_enqueue_async_action' )->never();

		$this->column->load();
		$this->render( $this->alpha );
		unset( $this->terms[ self::ALPHA_CAT ] );
		$this->column->load();
		$this->render( $this->alpha );

		self::assertSame( $tables, $this->tables() );
	}

	public function test_other_columns_are_passed_through(): void {
		self::assertSame( 'kept', $this->column->render( 'kept', 'user', $this->alpha ) );
		self::assertSame( 'kept', $this->column->render( 'kept', CreatorListCategories::COLUMN, 'not a creator' ) );
	}

	/**
	 * The cell of the column for a creator.
	 *
	 * @param Creator $creator Creator.
	 */
	private function render( Creator $creator ): string {
		return (string) $this->column->render( '', CreatorListCategories::COLUMN, $creator );
	}

	/**
	 * Every plugin table.
	 *
	 * @return array<string, mixed>
	 */
	private function tables(): array {
		$tables = array();

		foreach ( Tables::all() as $table ) {
			$tables[ $table ] = $this->db->rows( $table );
		}

		return $tables;
	}
}
