<?php
/**
 * Tests for the category-based ownership reconciliation.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce;

use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\ProtectedMetaPurpose;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationResult;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationTrigger;
use MerchBoard\Modules\WooCommerce\CreatorCategoryReconciler;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\ManualLinkConflict;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ReconciliationOutcome;
use MerchBoard\Modules\WooCommerce\ReconciliationPlan;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\FakeProductStore;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;

#[CoversClass( CreatorCategoryReconciler::class )]
#[CoversClass( CategoryReconciliationResult::class )]
#[CoversClass( CategoryReconciliationTrigger::class )]
#[CoversClass( ReconciliationPlan::class )]
final class CreatorCategoryReconcilerTest extends TestCase {

	use CreatorAdminEnvironment;

	private const ALPHA_CAT   = 16;
	private const BETA_CAT    = 17;
	private const GAMMA_CAT   = 18;
	private const GENERAL_CAT = 19;
	private const ALPHA_EXTRA = 20;

	private FakeProductStore $products;

	private ProtectedProductMeta $guard;

	private RecordingLogger $logger;

	private CreatorCategoryReconciler $reconciler;

	private int $alpha;

	private int $beta;

	private int $gamma;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_creator_admin();

		$this->alpha = $this->service->create( 'Alpha', CreatorStatus::Active, null )->id;
		$this->beta  = $this->service->create( 'Beta', CreatorStatus::Active, null )->id;
		$this->gamma = $this->service->create( 'Gamma', CreatorStatus::Inactive, null )->id;

		// Mappings stored directly: the table allows several categories per
		// creator even though the phase 17 service allows one.
		$mappings = new CreatorCategoryRepository( $this->db );
		$mappings->insert( self::ALPHA_CAT, $this->alpha, 7 );
		$mappings->insert( self::BETA_CAT, $this->beta, 7 );
		$mappings->insert( self::GAMMA_CAT, $this->gamma, 7 );
		$mappings->insert( self::ALPHA_EXTRA, $this->alpha, 7 );

		// No user may edit products: the category path must not need it.
		$this->caps = array();

		$this->products = new FakeProductStore();
		$this->guard    = new ProtectedProductMeta( new RecordingLogger() );
		$this->logger   = new RecordingLogger();
		$creators       = new CreatorRepository( $this->db );

		$this->reconciler = new CreatorCategoryReconciler(
			$this->products,
			$mappings,
			$creators,
			new ProductCreatorLink(
				$this->products,
				$creators,
				new AuditTrail( new AuditRepository( $this->db ) ),
				new Transaction( $this->db, new TransactionSupport( $this->db ) ),
				$this->guard
			),
			$this->logger
		);
	}

	public function test_a_product_in_an_unmapped_category_is_left_alone(): void {
		$this->product( 10, array( self::GENERAL_CAT ) );

		self::assertSame( ReconciliationOutcome::Unchanged, $this->run_product( 10 ) );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_one_active_mapped_creator_links_the_product_as_a_category_link(): void {
		$this->product( 10, array( self::ALPHA_CAT ) );

		self::assertSame( ReconciliationOutcome::Linked, $this->run_product( 10, CategoryReconciliationTrigger::MappingCreated, 5 ) );

		$product = $this->products->find( 10 );
		self::assertNotNull( $product );
		self::assertSame( $this->alpha, $product->creator_id() );
		self::assertTrue( $product->is_category_managed() );
		$audit = $this->product_audit();
		self::assertCount( 1, $audit );
		self::assertSame( array( 'product_creator_linked', (string) $this->alpha, null ), array( $audit[0]['action'], $audit[0]['creator_id'], $audit[0]['actor_user_id'] ) );
		self::assertSame(
			array(
				'product_id'          => 10,
				'previous_creator_id' => null,
				'source'              => 'category',
				'reason'              => 'mapping_created',
				'category_ids'        => array( self::ALPHA_CAT ),
				'trigger_user_id'     => 5,
			),
			$audit[0]['context']
		);
	}

	public function test_the_same_state_again_changes_nothing(): void {
		$this->product( 10, array( self::ALPHA_CAT ) );
		$this->run_product( 10 );
		$audit = count( $this->audit_rows() );

		self::assertSame( ReconciliationOutcome::Unchanged, $this->run_product( 10 ) );

		self::assertCount( 1, $this->products->writes );
		self::assertCount( $audit, $this->audit_rows() );
	}

	public function test_an_inactive_creator_gets_no_new_link(): void {
		$this->product( 10, array( self::GAMMA_CAT ) );

		self::assertSame( ReconciliationOutcome::SkippedInactive, $this->run_product( 10 ) );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_a_manual_link_is_never_changed(): void {
		$this->products->add_linked( 10, $this->alpha, 'admin', array( self::BETA_CAT ) );
		$this->products->add_linked( 11, $this->alpha, null, array( self::BETA_CAT ) );
		$this->products->add_linked( 12, $this->alpha, 'admin', array( self::GENERAL_CAT ) );
		$this->products->add( 13, array( array( 'key' => '_Merchboard_Creator_ID', 'value' => '1' ) ), 'publish', 0, '', '', array(), array( self::ALPHA_CAT ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		foreach ( array( 10, 11, 12, 13 ) as $id ) {
			self::assertSame( ReconciliationOutcome::SkippedManual, $this->run_product( $id ), (string) $id );
		}

		self::assertSame( array(), $this->products->writes );
		self::assertSame( array(), $this->product_audit() );
	}

	public function test_a_dry_run_reports_how_a_manual_link_deviates_from_its_categories(): void {
		$this->products->add_linked( 10, $this->alpha, 'admin', array( self::ALPHA_CAT, self::ALPHA_EXTRA, self::GENERAL_CAT ) ); // Same creator.
		$this->products->add_linked( 11, $this->alpha, 'admin', array( self::BETA_CAT ) );                // Other active creator.
		$this->products->add_linked( 12, $this->alpha, 'admin', array( self::ALPHA_CAT, self::BETA_CAT ) ); // Ambiguous.
		$this->products->add_linked( 13, $this->alpha, 'admin', array( self::GAMMA_CAT ) );               // Other, inactive creator.
		$this->products->add_linked( 14, $this->alpha, 'admin', array( self::GENERAL_CAT ) );             // No mapped creator.
		$this->products->add_linked( 15, $this->alpha, 'admin', array() );                                // No category at all.
		$this->products->add_linked( 16, $this->gamma, 'admin', array( self::GAMMA_CAT ) );               // Same, inactive creator.
		$this->products->add_linked( 17, $this->alpha, null, array( self::BETA_CAT ) );                   // No origin: manual.
		$this->products->add_linked( 18, $this->alpha, 'backfill', array( self::BETA_CAT ) );             // Unknown origin: manual.
		$this->products->add( 19, array( array( 'key' => '_Merchboard_Creator_ID', 'value' => '1' ) ), 'publish', 0, '', '', array(), array( self::BETA_CAT ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Invalid link: manual.

		$expected = array(
			10 => null,
			11 => array( ManualLinkConflict::OtherCreator, $this->alpha, array( $this->beta ) ),
			12 => array( ManualLinkConflict::Ambiguous, $this->alpha, array( $this->alpha, $this->beta ) ),
			13 => array( ManualLinkConflict::InactiveCreator, $this->alpha, array( $this->gamma ) ),
			14 => null,
			15 => null,
			16 => null,
			17 => array( ManualLinkConflict::OtherCreator, $this->alpha, array( $this->beta ) ),
			18 => array( ManualLinkConflict::OtherCreator, $this->alpha, array( $this->beta ) ),
			19 => array( ManualLinkConflict::OtherCreator, null, array( $this->beta ) ),
		);

		foreach ( $expected as $id => $conflict ) {
			$plan = $this->reconciler->plan_product( $id, CategoryReconciliationTrigger::FullSync );

			self::assertSame( ReconciliationOutcome::SkippedManual, $plan->outcome, (string) $id );
			self::assertFalse( $plan->writes, (string) $id );
			self::assertSame( $conflict, null === $plan->conflict ? null : array( $plan->conflict, $plan->from, $plan->mapped ), (string) $id );
		}

		self::assertSame( array(), $this->products->writes, 'Diagnostic only.' );
		self::assertSame( array(), $this->product_audit() );
		self::assertSame( array( 'creator_created' ), array_values( array_unique( array_column( $this->audit_rows(), 'action' ) ) ), 'Only the setup\'s rows.' );
		self::assertFalse( $this->guard->filter_update( null, 11, ProductCreatorLink::META_KEY ), 'No protected meta write was opened.' );
	}

	public function test_a_manual_conflict_never_changes_the_link_or_the_outcome(): void {
		$this->products->add_linked( 11, $this->alpha, 'admin', array( self::BETA_CAT ) );
		$this->products->add_linked( 12, $this->alpha, 'admin', array( self::ALPHA_CAT, self::BETA_CAT ) );
		$this->products->add_linked( 13, $this->alpha, null, array( self::GAMMA_CAT ) );
		$before = array_map( fn( int $id ): array => array( $this->products->entries( $id ), $this->products->sources( $id ) ), array( 11, 12, 13 ) );

		foreach ( array( 11, 12, 13 ) as $id ) {
			self::assertSame( ReconciliationOutcome::SkippedManual, $this->run_product( $id, CategoryReconciliationTrigger::FullSync ), (string) $id );
		}

		self::assertSame( $before, array_map( fn( int $id ): array => array( $this->products->entries( $id ), $this->products->sources( $id ) ), array( 11, 12, 13 ) ) );
		self::assertSame( array(), $this->products->writes );
		self::assertSame( array(), $this->product_audit() );
	}

	public function test_category_links_carry_no_manual_conflict(): void {
		$this->products->add_linked( 10, $this->alpha, 'category', array( self::BETA_CAT ) );
		$this->products->add_linked( 11, $this->alpha, 'category', array( self::ALPHA_CAT, self::BETA_CAT ) );
		$this->products->add_linked( 12, $this->alpha, 'category', array( self::GAMMA_CAT ) );
		$this->product( 13, array( self::ALPHA_CAT, self::BETA_CAT ) );

		$plans = array_map( fn( int $id ): array => array( $this->reconciler->plan_product( $id, CategoryReconciliationTrigger::FullSync )->outcome, $this->reconciler->plan_product( $id, CategoryReconciliationTrigger::FullSync )->conflict ), array( 10, 11, 12, 13 ) );

		self::assertSame(
			array(
				array( ReconciliationOutcome::Moved, null ),
				array( ReconciliationOutcome::Ambiguous, null ),
				array( ReconciliationOutcome::Unlinked, null ),
				array( ReconciliationOutcome::Ambiguous, null ),
			),
			$plans,
			'The existing category rules are unchanged.'
		);
	}

	public function test_a_category_link_to_the_same_creator_is_unchanged(): void {
		$this->products->add_linked( 10, $this->alpha, 'category', array( self::ALPHA_CAT ) );

		self::assertSame( ReconciliationOutcome::Unchanged, $this->run_product( 10 ) );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_a_category_link_to_a_now_inactive_creator_is_kept(): void {
		$this->products->add_linked( 10, $this->gamma, 'category', array( self::GAMMA_CAT ) );

		self::assertSame( ReconciliationOutcome::Unchanged, $this->run_product( 10 ) );
	}

	public function test_a_category_link_moves_when_the_state_now_points_to_another_creator(): void {
		$this->products->add_linked( 10, $this->alpha, 'category', array( self::BETA_CAT ) );

		self::assertSame( ReconciliationOutcome::Moved, $this->run_product( 10 ) );

		$product = $this->products->find( 10 );
		self::assertNotNull( $product );
		self::assertSame( $this->beta, $product->creator_id() );
		self::assertTrue( $product->is_category_managed() );
		$audit = $this->product_audit();
		self::assertSame( array( 'product_creator_unlinked', 'product_creator_linked' ), array_column( $audit, 'action' ) );
		self::assertSame( array( 'category_moved', 'category_moved' ), array( $audit[0]['context']['reason'], $audit[1]['context']['reason'] ) );
		self::assertSame( $this->alpha, $audit[1]['context']['previous_creator_id'] );
	}

	public function test_a_move_to_an_inactive_creator_only_removes_the_old_link(): void {
		$this->products->add_linked( 10, $this->alpha, 'category', array( self::GAMMA_CAT ) );

		self::assertSame( ReconciliationOutcome::Unlinked, $this->run_product( 10 ) );
		self::assertNull( $this->products->find( 10 )?->creator_id() );
		self::assertSame( array( 'product_creator_unlinked' ), array_column( $this->product_audit(), 'action' ) );
	}

	public function test_a_category_link_no_category_justifies_is_removed_with_the_triggers_reason(): void {
		$this->products->add_linked( 10, $this->alpha, 'category', array( self::GENERAL_CAT ) );
		$this->products->add_linked( 11, $this->alpha, 'category', array() );
		$this->products->add_linked( 12, $this->alpha, 'category', array( self::GENERAL_CAT ) );

		self::assertSame( ReconciliationOutcome::Unlinked, $this->run_product( 10 ) );
		self::assertSame( ReconciliationOutcome::Unlinked, $this->run_product( 11, CategoryReconciliationTrigger::MappingRemoved ) );
		self::assertSame( ReconciliationOutcome::Unlinked, $this->run_product( 12, CategoryReconciliationTrigger::TermDeleted ) );

		self::assertSame( array( 'category_removed', 'mapping_removed', 'term_deleted' ), array_map( static fn( array $a ): string => $a['context']['reason'], $this->product_audit() ) );
		self::assertSame( array(), $this->products->sources( 10 ) );
	}

	public function test_every_direct_category_of_the_product_is_considered(): void {
		$this->product( 10, array( self::GENERAL_CAT, self::ALPHA_CAT ) );
		$this->product( 11, array( self::ALPHA_CAT, self::ALPHA_EXTRA ) );
		$this->products->add_linked( 12, $this->alpha, 'category', array( self::ALPHA_CAT, self::ALPHA_EXTRA ) );

		self::assertSame( ReconciliationOutcome::Linked, $this->run_product( 10 ), 'An unmapped category next to a creator category does not matter.' );
		self::assertSame( ReconciliationOutcome::Linked, $this->run_product( 11 ), 'Two categories of the same creator are not ambiguous.' );
		self::assertSame( ReconciliationOutcome::Unchanged, $this->run_product( 12 ) );
		self::assertSame( array( self::ALPHA_CAT, self::ALPHA_EXTRA ), $this->product_audit()[1]['context']['category_ids'] );
	}

	public function test_categories_of_several_creators_are_ambiguous_and_create_nothing(): void {
		$this->product( 10, array( self::ALPHA_CAT, self::BETA_CAT ) );
		$this->product( 11, array( self::ALPHA_CAT, self::GAMMA_CAT ) );

		self::assertSame( ReconciliationOutcome::Ambiguous, $this->run_product( 10 ) );
		self::assertSame( ReconciliationOutcome::Ambiguous, $this->run_product( 11 ), 'An inactive creator still makes it ambiguous.' );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_ambiguity_never_changes_a_manual_link(): void {
		$this->products->add_linked( 10, $this->alpha, 'admin', array( self::ALPHA_CAT, self::BETA_CAT ) );

		self::assertSame( ReconciliationOutcome::SkippedManual, $this->run_product( 10 ) );
		self::assertSame( $this->alpha, $this->products->find( 10 )?->creator_id() );
	}

	public function test_ambiguity_removes_a_category_link_unless_the_state_still_points_to_one_creator(): void {
		$this->products->add_linked( 10, $this->alpha, 'category', array( self::ALPHA_CAT, self::BETA_CAT ) );
		$this->products->add_linked( 11, $this->alpha, 'category', array( self::ALPHA_CAT, self::ALPHA_EXTRA, self::GENERAL_CAT ) );

		self::assertSame( ReconciliationOutcome::Ambiguous, $this->run_product( 10 ) );
		self::assertSame( ReconciliationOutcome::Unchanged, $this->run_product( 11 ) );

		self::assertNull( $this->products->find( 10 )?->creator_id() );
		self::assertSame( $this->alpha, $this->products->find( 11 )?->creator_id() );
		$audit = $this->product_audit();
		self::assertCount( 1, $audit );
		self::assertSame( 'ambiguous', $audit[0]['context']['reason'] );
		self::assertSame( array( self::ALPHA_CAT, self::BETA_CAT ), $audit[0]['context']['category_ids'] );
	}

	public function test_variations_get_no_link(): void {
		$this->product( 20, array( self::ALPHA_CAT ) );
		$this->products->add( 21, array(), 'publish', 20, '', '', array(), array( self::ALPHA_CAT ) );

		self::assertSame( ReconciliationOutcome::Unchanged, $this->run_product( 21 ) );
		$result = $this->reconciler->reconcile_category( self::ALPHA_CAT, CategoryReconciliationTrigger::CategoryChanged );

		self::assertSame( 1, $result->count( ReconciliationOutcome::Linked ), 'Only the parent.' );
		self::assertSame( array( '20:' . $this->alpha ), $this->products->writes );
		self::assertSame( array(), $this->products->entries( 21 ) );
	}

	public function test_trash_and_auto_draft_get_no_new_link(): void {
		$this->product( 30, array( self::ALPHA_CAT ), 'trash' );
		$this->product( 31, array( self::ALPHA_CAT ), 'auto-draft' );

		self::assertSame( ReconciliationOutcome::Unchanged, $this->run_product( 30 ) );
		self::assertSame( ReconciliationOutcome::Unchanged, $this->run_product( 31 ) );
		self::assertSame( 0, $this->reconciler->reconcile_category( self::ALPHA_CAT, CategoryReconciliationTrigger::CategoryChanged )->total() );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_writes_run_inside_a_category_reconciliation_grant_for_that_product(): void {
		$this->product( 10, array( self::ALPHA_CAT ) );
		$seen                     = array();
		$guard                    = $this->guard;
		$this->products->on_write = static function ( int $id ) use ( &$seen, $guard ): void {
			$active = ( new ReflectionProperty( ProtectedProductMeta::class, 'active' ) )->getValue( $guard );
			$seen   = array( $active?->purpose, $active?->product_id, $guard->filter_update( null, $id, ProductCreatorLink::SOURCE_META_KEY ), $guard->filter_update( null, 11, ProductCreatorLink::META_KEY ) );
		};

		$this->run_product( 10 );

		self::assertSame( array( ProtectedMetaPurpose::CategoryReconciliation, 10, null, false ), $seen );
		self::assertFalse( $guard->filter_update( null, 10, ProductCreatorLink::META_KEY ), 'Closed again afterwards.' );
	}

	public function test_a_failed_product_write_rolls_back_its_audit_and_the_run_continues(): void {
		$this->product( 10, array( self::ALPHA_CAT ) );
		$this->product( 11, array( self::ALPHA_CAT ) );
		$this->products->on_write = function ( int $id ): void {
			$this->products->fail_write = 10 === $id;
		};

		$result = $this->reconciler->reconcile_category( self::ALPHA_CAT, CategoryReconciliationTrigger::CategoryChanged );

		self::assertSame( 1, $result->count( ReconciliationOutcome::Error ) );
		self::assertSame( 1, $result->count( ReconciliationOutcome::Linked ) );
		self::assertNull( $this->products->find( 10 )?->creator_id(), 'Nothing stored for the failed product.' );
		self::assertSame( array( 11 ), array_column( array_column( $this->product_audit(), 'context' ), 'product_id' ), 'No audit record for the failed product.' );
		self::assertSame( 'Category reconciliation of a product failed.', $this->logger->at( 'warning' )[0]['message'] );
		self::assertSame( array( 10 ), $this->products->forgotten );
	}

	public function test_a_category_run_reports_counts_and_a_second_run_is_idempotent(): void {
		$this->product( 10, array( self::ALPHA_CAT ) );
		$this->products->add_linked( 11, $this->alpha, 'category', array( self::ALPHA_CAT ) );
		$this->products->add_linked( 12, $this->beta, 'category', array( self::ALPHA_CAT ) );
		$this->products->add_linked( 13, $this->beta, 'admin', array( self::ALPHA_CAT ) );
		$this->product( 14, array( self::ALPHA_CAT, self::BETA_CAT ) );
		$this->products->add_linked( 15, $this->alpha, 'category', array( self::ALPHA_CAT, self::BETA_CAT ) );
		$this->product( 16, array( self::ALPHA_CAT, self::GAMMA_CAT ) );
		$this->product( 17, array( self::BETA_CAT ) );
		$mappings = $this->db->rows( Tables::CREATOR_CATEGORIES );
		$creators = $this->db->rows( Tables::CREATORS );

		$first = $this->reconciler->reconcile_category( self::ALPHA_CAT, CategoryReconciliationTrigger::MappingCreated );

		self::assertSame(
			array(
				'linked'           => 1,
				'moved'            => 1,
				'unlinked'         => 0,
				'unchanged'        => 1,
				'skipped_manual'   => 1,
				'ambiguous'        => 3,
				'skipped_inactive' => 0,
				'errors'           => 0,
			),
			$first->to_array()
		);
		self::assertSame( 7, $first->total(), 'Product 17 is not in the category.' );
		self::assertNull( $this->products->find( 15 )?->creator_id(), 'Ambiguous: category link removed.' );

		$writes = $this->products->writes;
		$audit  = count( $this->audit_rows() );
		$second = $this->reconciler->reconcile_category( self::ALPHA_CAT, CategoryReconciliationTrigger::MappingCreated );

		self::assertSame( 0, $second->count( ReconciliationOutcome::Linked ) + $second->count( ReconciliationOutcome::Moved ) + $second->count( ReconciliationOutcome::Unlinked ) );
		self::assertSame( $writes, $this->products->writes, 'Idempotent: no write.' );
		self::assertCount( $audit, $this->audit_rows(), 'Idempotent: no audit.' );
		self::assertSame( $mappings, $this->db->rows( Tables::CREATOR_CATEGORIES ), 'Mappings are only read.' );
		self::assertSame( $creators, $this->db->rows( Tables::CREATORS ), 'Creators are only read.' );
	}

	public function test_a_category_run_pages_through_every_product(): void {
		for ( $id = 100; $id < 350; $id++ ) {
			$this->product( $id, array( self::ALPHA_CAT ) );
		}

		$result = $this->reconciler->reconcile_category( self::ALPHA_CAT, CategoryReconciliationTrigger::CategoryChanged );

		self::assertSame( 250, $result->count( ReconciliationOutcome::Linked ) );
		self::assertSame( array( 0, 100, 200 ), array_column( $this->products->category_queries, 2 ) );
	}

	public function test_other_product_data_is_left_alone(): void {
		$this->products->add( 10, array(), 'draft', 0, 'Named tee', 'SKU-1', array(), array( self::GENERAL_CAT, self::ALPHA_CAT ) );

		$this->run_product( 10 );

		$product = $this->products->find( 10 );
		self::assertNotNull( $product );
		self::assertSame( array( 'draft', 'Named tee', 'SKU-1', array( self::GENERAL_CAT, self::ALPHA_CAT ) ), array( $product->status, $product->name, $product->sku, $product->category_ids ) );
	}

	public function test_the_triggers_choose_the_reasons(): void {
		self::assertSame( 'category_added', CategoryReconciliationTrigger::CategoryChanged->link_reason() );
		self::assertSame( 'mapping_created', CategoryReconciliationTrigger::MappingCreated->link_reason() );
		self::assertSame( 'creator_activated', CategoryReconciliationTrigger::CreatorActivated->link_reason() );
		self::assertSame( 'category_removed', CategoryReconciliationTrigger::CategoryChanged->unlink_reason() );
		self::assertSame( 'mapping_removed', CategoryReconciliationTrigger::MappingRemoved->unlink_reason() );
		self::assertSame( 'term_deleted', CategoryReconciliationTrigger::TermDeleted->unlink_reason() );
	}

	/**
	 * Adds an unlinked parent product.
	 *
	 * @phpstan-param list<int> $categories
	 *
	 * @param int    $id         Product id.
	 * @param int[]  $categories Direct categories.
	 * @param string $status     Post status.
	 */
	private function product( int $id, array $categories, string $status = 'publish' ): void {
		$this->products->add( $id, array(), $status, 0, '', '', array(), $categories );
	}

	/**
	 * Reconciles one product.
	 *
	 * @param int                           $id      Product id.
	 * @param CategoryReconciliationTrigger $trigger Trigger.
	 * @param int|null                      $user    Triggering user.
	 */
	private function run_product( int $id, CategoryReconciliationTrigger $trigger = CategoryReconciliationTrigger::CategoryChanged, ?int $user = null ): ReconciliationOutcome {
		return $this->reconciler->reconcile_product( $id, $trigger, $user );
	}

	/**
	 * Product link audit rows with decoded context.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function product_audit(): array {
		return array_values( array_filter( $this->audit_rows(), static fn( array $row ): bool => str_starts_with( (string) $row['action'], 'product_' ) ) );
	}
}
