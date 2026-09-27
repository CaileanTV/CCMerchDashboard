<?php
/**
 * Tests for mapping dry runs and removal with an explicit choice.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ProtectedMetaPurpose;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CategoryMappingPreview;
use MerchBoard\Modules\WooCommerce\CategoryMappingRemoval;
use MerchBoard\Modules\WooCommerce\CreatorCategoryException;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;
use MerchBoard\Modules\WooCommerce\HypotheticalMappings;
use MerchBoard\Modules\WooCommerce\KeepAsManualResult;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ReconciliationOutcome;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\FakeProductStore;
use MerchBoard\Tests\Unit\Support\FakeReconciliationCandidates;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionProperty;
use WP_Term;

/**
 * Separate processes: the WP_Term stand-in defines a global class.
 */
#[CoversClass( CategoryMappingPreview::class )]
#[CoversClass( CategoryMappingRemoval::class )]
#[CoversClass( HypotheticalMappings::class )]
#[CoversClass( KeepAsManualResult::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CategoryMappingRemovalTest extends TestCase {

	use CreatorAdminEnvironment;

	private const ALPHA_CAT   = 16;
	private const BETA_CAT    = 17;
	private const EXTRA_CAT   = 18;
	private const GENERAL_CAT = 19;
	private const DEFAULT_CAT = 15;

	private FakeProductStore $products;

	private CreatorCategoryRepository $stored;

	private CreatorCategoryService $mappings;

	private CategoryMappingPreview $preview;

	private CategoryMappingRemoval $removal;

	private ProtectedProductMeta $guard;

	private int $alpha;

	private int $beta;

	/**
	 * Existing `product_cat` terms.
	 *
	 * @var list<int>
	 */
	private array $terms = array( self::DEFAULT_CAT, self::ALPHA_CAT, self::BETA_CAT, self::EXTRA_CAT, self::GENERAL_CAT );

	/**
	 * Products the user may not edit.
	 *
	 * @var list<int>
	 */
	private array $not_editable = array();

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/Fixtures/wp-term.php';
		$this->set_up_creator_admin();

		$this->alpha = $this->service->create( 'Alpha', CreatorStatus::Active, null )->id;
		$this->beta  = $this->service->create( 'Beta', CreatorStatus::Active, null )->id;

		Functions\when( 'current_user_can' )->alias(
			fn( string $capability, mixed ...$args ): bool => Capabilities::EDIT_PRODUCT === $capability
				? in_array( Capabilities::MANAGE_CREATORS, $this->caps, true ) && ! in_array( $args[0] ?? null, $this->not_editable, true )
				: in_array( $capability, $this->caps, true )
		);
		Functions\when( 'get_term' )->alias(
			fn( int $id ): ?WP_Term => in_array( $id, $this->terms, true )
				? new WP_Term(
					(object) array(
						'term_id'  => $id,
						'taxonomy' => 'product_cat',
					)
				)
				: null
		);
		Functions\when( 'get_option' )->alias( static fn( string $name, mixed $fallback = false ): mixed => ( 'default_product_cat' === $name ) ? (string) self::DEFAULT_CAT : $fallback );

		$this->products = new FakeProductStore();
		$this->stored   = new CreatorCategoryRepository( $this->db );
		$this->guard    = new ProtectedProductMeta( new RecordingLogger() );
		$creators       = new CreatorRepository( $this->db );
		$audit          = new AuditTrail( new AuditRepository( $this->db ) );
		$transaction    = new Transaction( $this->db, new TransactionSupport( $this->db ) );
		$link           = new ProductCreatorLink( $this->products, $creators, $audit, $transaction, $this->guard );
		$logger         = new RecordingLogger();
		$candidates     = new FakeReconciliationCandidates(
			$this->products,
			fn(): array => array_map( 'intval', array_column( $this->db->rows( Tables::CREATOR_CATEGORIES ), 'term_id' ) )
		);

		$this->mappings = new CreatorCategoryService( $this->stored, $creators, $audit, $transaction );
		$this->preview  = new CategoryMappingPreview( $this->mappings, $this->stored, $this->products, $creators, $link, $candidates, $audit, $logger );
		$this->removal  = new CategoryMappingRemoval( $this->mappings, $this->stored, $this->preview, $link, $logger );
	}

	public function test_a_new_mapping_is_previewed_without_writing_anything(): void {
		$this->product( 10, array( self::ALPHA_CAT ) );
		$this->products->add_linked( 11, $this->beta, 'admin', array( self::ALPHA_CAT ) );
		$this->product( 12, array( self::ALPHA_CAT, self::BETA_CAT ) );
		$this->products->add_linked( 13, $this->beta, 'category', array( self::ALPHA_CAT ) );
		$this->product( 14, array( self::GENERAL_CAT ) );
		$this->stored->insert( self::BETA_CAT, $this->beta, 7 );
		$before = $this->state();
		Actions\expectDone( CreatorCategoryService::LINKED_ACTION )->never();

		$report = $this->preview->for_new_mapping( $this->alpha, self::ALPHA_CAT );

		self::assertSame( 1, $report->result->count( ReconciliationOutcome::Linked ) );
		self::assertSame( 1, $report->result->count( ReconciliationOutcome::SkippedManual ) );
		self::assertSame( 1, $report->result->count( ReconciliationOutcome::Ambiguous ) );
		self::assertSame( 1, $report->result->count( ReconciliationOutcome::Moved ), 'A category link of product 13 would move.' );
		self::assertSame( 4, $report->result->total(), 'Only the category: no full backfill.' );
		self::assertSame( $before, $this->state(), 'No mapping, product meta, audit or creator change.' );
		self::assertNull( $this->mappings->for_category( self::ALPHA_CAT ) );
	}

	public function test_a_preview_is_refused_for_a_mapping_that_could_not_be_created(): void {
		$inactive = $this->service->create( 'Resting', CreatorStatus::Inactive, null )->id;
		$this->stored->insert( self::BETA_CAT, $this->beta, 7 );

		$this->expect_reason( CreatorCategoryException::DEFAULT_CATEGORY, fn() => $this->preview->for_new_mapping( $this->alpha, self::DEFAULT_CAT ) );
		$this->expect_reason( CreatorCategoryException::CREATOR_INACTIVE, fn() => $this->preview->for_new_mapping( $inactive, self::ALPHA_CAT ) );
		$this->expect_reason( CreatorCategoryException::CATEGORY_MAPPED_ELSEWHERE, fn() => $this->preview->for_new_mapping( $this->alpha, self::BETA_CAT ) );
		$this->expect_reason( CreatorCategoryException::ALREADY_MAPPED, fn() => $this->preview->for_new_mapping( $this->beta, self::BETA_CAT ) );
		$this->expect_reason( CreatorCategoryException::TERM_NOT_FOUND, fn() => $this->preview->for_new_mapping( $this->alpha, 999 ) );
		$this->caps = array();
		$this->expect_reason( CreatorCategoryException::NOT_AUTHORIZED, fn() => $this->preview->for_new_mapping( $this->alpha, self::ALPHA_CAT ) );
	}

	public function test_a_removal_is_previewed_for_its_category_and_the_links_that_left_it(): void {
		$this->map_alpha();
		$before = $this->state();

		$report = $this->preview->for_removal( $this->mapping() );

		self::assertSame( 4, $report->result->count( ReconciliationOutcome::Unlinked ), 'In the category (2), left it (2).' );
		self::assertSame( 1, $report->result->count( ReconciliationOutcome::Moved ) );
		self::assertSame( 1, $report->result->count( ReconciliationOutcome::SkippedManual ) );
		self::assertSame( 5, $this->preview->conversion_count( $this->mapping() ), 'The links "Keep as manual" converts.' );
		self::assertSame( $before, $this->state() );
	}

	public function test_links_justified_by_another_mapping_of_the_creator_are_not_converted(): void {
		$this->map_alpha();
		$this->stored->insert( self::EXTRA_CAT, $this->alpha, 7 );
		$this->products->add_linked( 30, $this->alpha, 'category', array( self::ALPHA_CAT, self::EXTRA_CAT ) );

		self::assertNotContains( 30, $this->preview->conversion_targets( $this->mapping(), 0, 100 )['ids'] );
	}

	public function test_remove_links_removes_the_mapping_with_the_choice_and_queues_the_reconciliation(): void {
		$this->map_alpha();
		Actions\expectDone( CreatorCategoryService::UNLINKED_ACTION )->once()->with( self::ALPHA_CAT, $this->alpha, 'admin', 7 );

		self::assertTrue( $this->removal->remove_links( $this->alpha, self::ALPHA_CAT ) );

		self::assertNull( $this->mappings->for_category( self::ALPHA_CAT ) );
		self::assertSame( array(), $this->products->writes, 'The links are removed by the queued reconciliation, not here.' );
		self::assertSame( 'remove_links', $this->last_audit()['context']['choice'] );
		self::assertFalse( $this->removal->remove_links( $this->alpha, self::ALPHA_CAT ), 'Idempotent.' );
	}

	public function test_keep_as_manual_converts_every_link_of_the_mapping_and_then_removes_it(): void {
		$this->map_alpha();
		$manual = $this->products->find( 24 );
		Actions\expectDone( CreatorCategoryService::UNLINKED_ACTION )->once()->with( self::ALPHA_CAT, $this->alpha, 'admin', 7 );

		$result = $this->removal->keep_as_manual( $this->alpha, self::ALPHA_CAT );

		self::assertSame( array( 5, array(), false, true, true ), array( $result->converted, $result->failed, $result->remaining, $result->removed, $result->found ) );
		foreach ( array( 20, 21, 22, 23, 25 ) as $id ) {
			$product = $this->products->find( $id );
			self::assertNotNull( $product );
			self::assertSame( $this->alpha, $product->creator_id(), 'Creator kept: ' . $id );
			self::assertTrue( $product->has_manual_link(), 'Now manual: ' . $id );
		}
		self::assertEquals( $manual, $this->products->find( 24 ), 'The manual link of another creator is untouched.' );
		self::assertSame( $this->beta, $this->products->find( 26 )?->creator_id(), 'Another creator\'s category link is untouched.' );
		self::assertNull( $this->mappings->for_category( self::ALPHA_CAT ) );
		self::assertSame( 'keep_as_manual', $this->last_audit()['context']['choice'] );

		$audit = array_values( array_filter( $this->audit_rows(), static fn( array $row ): bool => 'product_creator_linked' === $row['action'] ) );
		self::assertCount( 5, $audit, 'One record per product.' );
		self::assertSame( array( '7' ), array_values( array_unique( array_column( $audit, 'actor_user_id' ) ) ), 'The admin is the actor.' );
		self::assertSame( array( 'category' ), array_values( array_unique( array_column( array_column( $audit, 'context' ), 'previous_source' ) ) ) );
	}

	public function test_keep_as_manual_writes_only_through_the_manual_grant_for_each_product(): void {
		$this->map_alpha();
		$seen                     = array();
		$guard                    = $this->guard;
		$this->products->on_write = static function ( int $id ) use ( &$seen, $guard ): void {
			$active = ( new ReflectionProperty( ProtectedProductMeta::class, 'active' ) )->getValue( $guard );
			$seen[] = array( $id, $active?->purpose, $active?->product_id );
		};

		$this->removal->keep_as_manual( $this->alpha, self::ALPHA_CAT );

		foreach ( $seen as [ $id, $purpose, $product ] ) {
			self::assertSame( array( ProtectedMetaPurpose::ManualLink, $id ), array( $purpose, $product ) );
		}
		self::assertCount( 5, $seen );
		self::assertNull( ( new ReflectionProperty( ProtectedProductMeta::class, 'active' ) )->getValue( $guard ) );
	}

	public function test_a_product_that_fails_keeps_the_mapping_and_a_repeat_converts_nothing_twice(): void {
		$this->map_alpha();
		$this->not_editable = array( 22 );

		$first = $this->removal->keep_as_manual( $this->alpha, self::ALPHA_CAT );

		self::assertSame( array( 4, array( 22 => 'not_authorized' ), false, false ), array( $first->converted, $first->failed, $first->remaining, $first->removed ) );
		self::assertNotNull( $this->mappings->for_category( self::ALPHA_CAT ), 'The mapping stays while a link is unconverted.' );
		self::assertTrue( $this->products->find( 22 )?->is_category_managed(), 'Nothing half-written.' );

		$this->not_editable = array();
		$second             = $this->removal->keep_as_manual( $this->alpha, self::ALPHA_CAT );

		self::assertSame( array( 1, array(), true ), array( $second->converted, $second->failed, $second->removed ) );
		self::assertCount( 5, $this->products->writes, 'Each link converted once.' );
	}

	public function test_a_failed_write_is_reported_and_leaves_that_product_unchanged(): void {
		$this->map_alpha();
		$this->products->on_write = function ( int $id ): void {
			$this->products->fail_write = 21 === $id;
		};

		$result = $this->removal->keep_as_manual( $this->alpha, self::ALPHA_CAT );

		self::assertSame( array( 21 => 'error' ), $result->failed );
		self::assertFalse( $result->removed );
		self::assertTrue( $this->products->find( 21 )?->is_category_managed() );
		self::assertNotContains( 21, array_column( array_column( array_filter( $this->audit_rows(), static fn( array $row ): bool => 'product_creator_linked' === $row['action'] ), 'context' ), 'product_id' ) );
	}

	public function test_a_large_mapping_is_converted_over_several_runs(): void {
		$this->stored->insert( self::ALPHA_CAT, $this->alpha, 7 );
		for ( $id = 100; $id < 100 + CategoryMappingRemoval::MAX_CONVERSIONS + 5; $id++ ) {
			$this->products->add_linked( $id, $this->alpha, 'category', array( self::ALPHA_CAT ) );
		}

		$first = $this->removal->keep_as_manual( $this->alpha, self::ALPHA_CAT );
		self::assertSame( array( CategoryMappingRemoval::MAX_CONVERSIONS, true, false ), array( $first->converted, $first->remaining, $first->removed ) );
		self::assertNotNull( $this->mappings->for_category( self::ALPHA_CAT ) );

		$second = $this->removal->keep_as_manual( $this->alpha, self::ALPHA_CAT );
		self::assertSame( array( 5, false, true ), array( $second->converted, $second->remaining, $second->removed ) );
	}

	public function test_an_orphaned_mapping_is_removed_with_the_choice_as_a_deleted_category(): void {
		$this->map_alpha();
		$this->terms = array_values( array_diff( $this->terms, array( self::ALPHA_CAT ) ) );
		Actions\expectDone( CreatorCategoryService::UNLINKED_ACTION )->once()->with( self::ALPHA_CAT, $this->alpha, 'term_deleted', 7 );

		$result = $this->removal->keep_as_manual( $this->alpha, self::ALPHA_CAT );

		self::assertTrue( $result->removed );
		self::assertSame( array( 'term_deleted', 'keep_as_manual' ), array( $this->last_audit()['context']['reason'], $this->last_audit()['context']['choice'] ) );
	}

	public function test_nothing_happens_without_the_admin_capability(): void {
		$this->map_alpha();
		$before     = $this->state();
		$this->caps = array();

		$this->expect_reason( CreatorCategoryException::NOT_AUTHORIZED, fn() => $this->removal->keep_as_manual( $this->alpha, self::ALPHA_CAT ) );
		$this->expect_reason( CreatorCategoryException::NOT_AUTHORIZED, fn() => $this->removal->remove_links( $this->alpha, self::ALPHA_CAT ) );
		$this->expect_reason( CreatorCategoryException::NOT_AUTHORIZED, fn() => $this->preview->for_removal( $this->mapping() ) );

		self::assertSame( $before, $this->state() );
	}

	public function test_another_creators_mapping_is_not_removed(): void {
		$this->map_alpha();

		self::assertFalse( $this->removal->remove_links( $this->beta, self::ALPHA_CAT ) );
		self::assertFalse( $this->removal->keep_as_manual( $this->beta, self::ALPHA_CAT )->found );
		self::assertNotNull( $this->mappings->for_category( self::ALPHA_CAT ) );
	}

	/**
	 * The alpha mapping and a product per case.
	 */
	private function map_alpha(): void {
		$this->stored->insert( self::ALPHA_CAT, $this->alpha, 7 );
		$this->stored->insert( self::BETA_CAT, $this->beta, 7 );
		$this->products->add_linked( 20, $this->alpha, 'category', array( self::ALPHA_CAT ) );                    // In the category.
		$this->products->add_linked( 21, $this->alpha, 'category', array() );                                     // Left the category.
		$this->products->add_linked( 22, $this->alpha, 'category', array( self::ALPHA_CAT, self::GENERAL_CAT ) ); // Also unmapped.
		$this->products->add_linked( 23, $this->alpha, 'category', array( self::ALPHA_CAT, self::BETA_CAT ) );    // Would move to beta.
		$this->products->add_linked( 24, $this->beta, 'admin', array( self::ALPHA_CAT ) );                        // Manual.
		$this->products->add_linked( 25, $this->alpha, 'category', array( self::GENERAL_CAT ) );                  // Left for an unmapped category.
		$this->products->add_linked( 26, $this->beta, 'category', array( self::BETA_CAT ) );                      // Another creator.
	}

	/**
	 * The stored alpha mapping.
	 */
	private function mapping(): \MerchBoard\Modules\WooCommerce\CreatorCategory {
		$mapping = $this->mappings->for_category( self::ALPHA_CAT );
		self::assertNotNull( $mapping );

		return $mapping;
	}

	/**
	 * Adds an unlinked parent product.
	 *
	 * @phpstan-param list<int> $categories
	 *
	 * @param int   $id         Product id.
	 * @param int[] $categories Direct categories.
	 */
	private function product( int $id, array $categories ): void {
		$this->products->add( $id, array(), 'publish', 0, '', '', array(), $categories );
	}

	/**
	 * Every plugin table and product.
	 *
	 * @return array<string, mixed>
	 */
	private function state(): array {
		$state = array( 'products' => array_map( fn( int $id ): mixed => $this->products->find( $id ), $this->products->ids() ) );

		foreach ( Tables::all() as $table ) {
			$state[ $table ] = $this->db->rows( $table );
		}

		return $state;
	}

	/**
	 * The last audit row.
	 *
	 * @return array<string, mixed>
	 */
	private function last_audit(): array {
		$rows = $this->audit_rows();

		return $rows[ count( $rows ) - 1 ];
	}

	/**
	 * Asserts a rejection with a reason.
	 *
	 * @param string   $reason    Expected reason.
	 * @param callable $operation Operation.
	 */
	private function expect_reason( string $reason, callable $operation ): void {
		try {
			$operation();
			self::fail( 'Expected rejection: ' . $reason );
		} catch ( CreatorCategoryException $e ) {
			self::assertSame( $reason, $e->reason() );
		}
	}
}
