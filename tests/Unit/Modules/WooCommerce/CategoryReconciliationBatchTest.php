<?php
/**
 * Tests for the full category reconciliation in batches.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce;

use InvalidArgumentException;
use LogicException;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\ProtectedMetaPurpose;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationBatch;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationTrigger;
use MerchBoard\Modules\WooCommerce\CategorySyncReport;
use MerchBoard\Modules\WooCommerce\CreatorCategoryReconciler;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ReconciliationOutcome;
use MerchBoard\Modules\WooCommerce\ReconciliationPlan;
use MerchBoard\Modules\WooCommerce\ReconciliationRunId;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\FakeProductStore;
use MerchBoard\Tests\Unit\Support\FakeReconciliationCandidates;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass( CategoryReconciliationBatch::class )]
#[CoversClass( CategorySyncReport::class )]
#[CoversClass( ReconciliationRunId::class )]
#[CoversClass( ReconciliationPlan::class )]
final class CategoryReconciliationBatchTest extends TestCase {

	use CreatorAdminEnvironment;

	private const ALPHA_CAT   = 16;
	private const BETA_CAT    = 17;
	private const GAMMA_CAT   = 18;
	private const GENERAL_CAT = 19;

	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	/**
	 * Counts of the seeded state.
	 */
	private const SEEDED_COUNTS = array(
		'linked'           => 1,
		'moved'            => 1,
		'unlinked'         => 1,
		'unchanged'        => 1,
		'skipped_manual'   => 2,
		'ambiguous'        => 2,
		'skipped_inactive' => 1,
		'errors'           => 0,
	);

	private FakeProductStore $products;

	private FakeReconciliationCandidates $candidates;

	private CreatorCategoryRepository $mappings;

	private ProtectedProductMeta $guard;

	private RecordingLogger $logger;

	private CategoryReconciliationBatch $batch;

	private int $alpha;

	private int $beta;

	private int $gamma;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_creator_admin();

		$this->alpha = $this->service->create( 'Alpha', CreatorStatus::Active, null )->id;
		$this->beta  = $this->service->create( 'Beta', CreatorStatus::Active, null )->id;
		$this->gamma = $this->service->create( 'Gamma', CreatorStatus::Inactive, null )->id;

		$this->mappings = new CreatorCategoryRepository( $this->db );
		$this->mappings->insert( self::ALPHA_CAT, $this->alpha, 7 );
		$this->mappings->insert( self::BETA_CAT, $this->beta, 7 );
		$this->mappings->insert( self::GAMMA_CAT, $this->gamma, 7 );

		// System work: no capability is needed or checked.
		$this->caps = array();

		$this->products   = new FakeProductStore();
		$this->candidates = new FakeReconciliationCandidates(
			$this->products,
			fn(): array => array_map( 'intval', array_column( $this->db->rows( Tables::CREATOR_CATEGORIES ), 'term_id' ) )
		);
		$this->guard      = new ProtectedProductMeta( new RecordingLogger() );
		$this->logger     = new RecordingLogger();
		$creators         = new CreatorRepository( $this->db );
		$audit            = new AuditTrail( new AuditRepository( $this->db ) );

		$this->batch = new CategoryReconciliationBatch(
			$this->candidates,
			new CreatorCategoryReconciler(
				$this->products,
				$this->mappings,
				$creators,
				new ProductCreatorLink( $this->products, $creators, $audit, new Transaction( $this->db, new TransactionSupport( $this->db ) ), $this->guard ),
				$this->logger
			),
			$audit,
			$this->logger
		);
	}

	public function test_a_full_run_finds_products_through_mapped_categories_and_category_links(): void {
		$this->seed();

		$report = $this->batch->execute();

		self::assertSame( self::SEEDED_COUNTS, $report->result->to_array() );
		self::assertSame( 9, $report->result->total() );
		self::assertSame( array( array( 0, 100 ) ), $this->candidates->calls, 'One page.' );
		self::assertSame( 1, $report->pages );
		self::assertFalse( $report->dry_run );
		self::assertSame( CategoryReconciliationTrigger::FullSync, $report->trigger );
		self::assertSame( array( '10:' . $this->alpha, '11:' . $this->beta, '12:null', '16:null' ), $this->products->writes );
	}

	public function test_products_that_left_a_mapped_category_or_lost_their_mapping_are_reconciled(): void {
		$this->products->add_linked( 30, $this->alpha, 'category', array( self::GENERAL_CAT ) );
		$this->products->add_linked( 31, $this->beta, 'category', array( self::BETA_CAT ) );
		$this->products->add_linked( 32, $this->beta, 'category', array() );
		$this->mappings->delete( self::BETA_CAT, $this->beta );

		$report = $this->batch->execute();

		self::assertSame( 3, $report->result->count( ReconciliationOutcome::Unlinked ) );
		foreach ( array( 30, 31, 32 ) as $id ) {
			self::assertNull( $this->products->find( $id )?->creator_id(), (string) $id );
			self::assertSame( array(), $this->products->sources( $id ) );
		}
		self::assertSame( array( 'category_removed', 'category_removed', 'category_removed' ), array_map( static fn( array $row ): string => $row['context']['reason'], $this->product_audit() ) );
	}

	public function test_manual_links_are_never_changed(): void {
		$this->seed();
		$before = array( $this->products->entries( 13 ), $this->products->sources( 13 ), $this->products->entries( 14 ), $this->products->sources( 14 ) );

		$this->batch->execute();

		self::assertSame( $before, array( $this->products->entries( 13 ), $this->products->sources( 13 ), $this->products->entries( 14 ), $this->products->sources( 14 ) ) );
		self::assertNotContains( 13, $this->audited_products() );
		self::assertNotContains( 14, $this->audited_products() );
	}

	public function test_ambiguous_products_get_no_category_link(): void {
		$this->seed();

		$this->batch->execute();

		self::assertNull( $this->products->find( 15 )?->creator_id() );
		self::assertNull( $this->products->find( 16 )?->creator_id(), 'Category link removed.' );
		$removed = array_values( array_filter( $this->product_audit(), static fn( array $row ): bool => 16 === $row['context']['product_id'] ) );
		self::assertSame( array( 'ambiguous' ), array_column( array_column( $removed, 'context' ), 'reason' ) );
	}

	public function test_an_inactive_creator_gets_no_new_link(): void {
		$this->seed();

		$this->batch->execute();

		self::assertNull( $this->products->find( 17 )?->creator_id() );
		self::assertNotContains( 17, $this->audited_products() );
	}

	public function test_variations_trash_and_auto_drafts_get_no_link_and_are_not_candidates(): void {
		$this->seed();
		self::assertSame( 9, $this->batch->preview()->result->total(), 'Not counted either.' );

		$this->batch->execute();

		foreach ( array( 19, 20, 21 ) as $id ) {
			self::assertSame( array(), $this->products->entries( $id ), (string) $id );
			self::assertNotContains( $id, $this->audited_products() );
		}
	}

	public function test_a_dry_run_writes_nothing_and_reports_the_expected_changes(): void {
		$this->seed();
		$tables   = $this->tables();
		$products = $this->product_state();

		$report = $this->batch->preview();

		self::assertTrue( $report->dry_run );
		self::assertSame( self::SEEDED_COUNTS, $report->result->to_array() );
		self::assertSame( array(), $this->products->writes, 'No product write.' );
		self::assertSame( $products, $this->product_state() );
		self::assertSame( $tables, $this->tables(), 'No audit row, no mapping or creator change.' );
		self::assertSame(
			array(
				array( 10, 'linked', null, $this->alpha, 'category_added' ),
				array( 11, 'moved', $this->alpha, $this->beta, 'category_moved' ),
				array( 12, 'unlinked', $this->alpha, null, 'category_removed' ),
				array( 16, 'ambiguous', $this->alpha, null, 'ambiguous' ),
			),
			array_map( static fn( array $c ): array => array_values( $c ), $report->changes )
		);
		self::assertFalse( $report->changes_truncated );
	}

	public function test_a_dry_run_lists_deviating_manual_links_as_attention_without_changing_the_result(): void {
		$this->seed();
		$this->products->add_linked( 23, $this->alpha, 'admin', array( self::ALPHA_CAT ) ); // Manual, same creator: no attention.
		$this->products->add_linked( 24, $this->alpha, 'admin', array( self::GAMMA_CAT ) ); // Manual, inactive other creator.
		$tables   = $this->tables();
		$products = $this->product_state();

		$report = $this->batch->preview();

		self::assertSame( array_replace( self::SEEDED_COUNTS, array( 'skipped_manual' => 4 ) ), $report->result->to_array(), 'Still counted as skipped_manual only.' );
		self::assertSame( array( 10, 11, 12, 16 ), array_column( $report->changes, 'product_id' ), 'No change planned for a manual link.' );
		self::assertSame(
			array(
				array(
					'product_id'         => 13,
					'outcome'            => 'skipped_manual',
					'conflict'           => 'manual_other_creator',
					'creator_id'         => $this->beta,
					'mapped_creator_ids' => array( $this->alpha ),
				),
				array(
					'product_id'         => 14,
					'outcome'            => 'skipped_manual',
					'conflict'           => 'manual_other_creator',
					'creator_id'         => $this->beta,
					'mapped_creator_ids' => array( $this->alpha ),
				),
				array(
					'product_id' => 15,
					'outcome'    => 'ambiguous',
				),
				array(
					'product_id' => 16,
					'outcome'    => 'ambiguous',
				),
				array(
					'product_id' => 17,
					'outcome'    => 'skipped_inactive',
				),
				array(
					'product_id'         => 24,
					'outcome'            => 'skipped_manual',
					'conflict'           => 'manual_inactive_creator',
					'creator_id'         => $this->alpha,
					'mapped_creator_ids' => array( $this->gamma ),
				),
			),
			$report->attention
		);
		self::assertSame( array(), $this->products->writes, 'No product write.' );
		self::assertSame( $products, $this->product_state() );
		self::assertSame( $tables, $this->tables(), 'No audit row, no mapping or creator change.' );
	}

	public function test_the_fingerprint_covers_manual_conflicts(): void {
		$this->seed();
		$before = $this->batch->preview();

		// Same counts and changes; only product 13's manual conflict differs.
		$this->products->add_linked( 13, $this->beta, 'admin', array( self::GAMMA_CAT ) );
		$inactive = $this->batch->preview();
		$this->products->add_linked( 13, $this->beta, 'admin', array( self::BETA_CAT ) );
		$resolved = $this->batch->preview();

		self::assertSame( $before->result->to_array(), $inactive->result->to_array() );
		self::assertSame( $before->changes, $resolved->changes );
		self::assertNotSame( $before->fingerprint(), $inactive->fingerprint() );
		self::assertNotSame( $before->fingerprint(), $resolved->fingerprint() );
		self::assertNotSame( $inactive->fingerprint(), $resolved->fingerprint() );
		self::assertNotContains( 13, array_column( $resolved->attention, 'product_id' ) );

		$this->products->add_linked( 13, $this->beta, 'admin', array( self::ALPHA_CAT ) );
		self::assertSame( $before->fingerprint(), $this->batch->preview()->fingerprint(), 'Same state, same fingerprint.' );
	}

	public function test_a_dry_run_and_a_real_run_evaluate_the_same_state_the_same_way(): void {
		$this->seed();

		$preview = $this->batch->preview();
		$real    = $this->batch->execute();

		self::assertSame( $preview->result->to_array(), $real->result->to_array() );
		self::assertSame( array_column( $preview->changes, 'product_id' ), array_values( array_unique( $this->audited_products() ) ) );
	}

	public function test_a_dry_run_lists_a_bounded_number_of_changes(): void {
		for ( $id = 1000; $id <= 1000 + CategoryReconciliationBatch::MAX_CHANGES; $id++ ) {
			$this->products->add( $id, array(), 'publish', 0, '', '', array(), array( self::ALPHA_CAT ) );
		}

		$report = $this->batch->preview();

		self::assertSame( CategoryReconciliationBatch::MAX_CHANGES + 1, $report->result->count( ReconciliationOutcome::Linked ) );
		self::assertCount( CategoryReconciliationBatch::MAX_CHANGES, $report->changes );
		self::assertTrue( $report->changes_truncated );
	}

	public function test_a_real_run_records_one_category_sync_completed_after_all_batches(): void {
		$this->seed();

		$report = $this->batch->execute( CategoryReconciliationTrigger::FullSync, 5 );

		$rows = $this->audit_rows();
		$last = end( $rows );
		self::assertIsArray( $last );
		self::assertSame( array( 'category_sync_completed', null, null ), array( $last['action'], $last['actor_user_id'], $last['creator_id'] ) );
		self::assertSame(
			array(
				'run_id'          => $report->run_id->value,
				'dry_run'         => false,
				'trigger'         => 'full_sync',
				'trigger_user_id' => 5,
				'scope'           => 'all',
			) + self::SEEDED_COUNTS + array( 'total' => 9 ),
			$last['context']
		);
		self::assertCount( 1, array_filter( $this->audit_actions(), static fn( ?string $a ): bool => 'category_sync_completed' === $a ) );
	}

	public function test_an_incomplete_run_records_no_completed_event_and_rethrows(): void {
		$this->many( 250 );
		$this->candidates->fail_on_call = 2;

		try {
			$this->batch->execute();
			self::fail( 'Expected the run to stop.' );
		} catch ( DatabaseException ) {
			self::assertNotContains( 'category_sync_completed', $this->audit_actions() );
		}

		self::assertCount( 100, $this->products->writes, 'The first page kept its per-product results.' );
		$error = $this->logger->at( 'error' )[0];
		self::assertSame( 'Category reconciliation run aborted.', $error['message'] );
		self::assertMatchesRegularExpression( self::UUID, (string) $error['context']['run_id'] );
		self::assertSame( 199, $error['context']['after_id'] );

		self::assertSame( 150, $this->batch->execute()->result->count( ReconciliationOutcome::Linked ), 'A new run picks up the rest.' );
	}

	public function test_paging_that_does_not_advance_stops_the_run(): void {
		$this->many( 150 );
		$this->candidates->stuck = true;

		$this->expectException( LogicException::class );

		try {
			$this->batch->execute();
		} finally {
			self::assertNotContains( 'category_sync_completed', $this->audit_actions() );
			self::assertCount( 100, $this->products->writes, 'Each product at most once.' );
		}
	}

	public function test_one_run_id_is_used_throughout_a_run_and_every_run_gets_a_new_one(): void {
		$this->many( 150 );

		$first  = $this->batch->execute();
		$run_id = $first->run_id->value;

		self::assertMatchesRegularExpression( self::UUID, $run_id );
		self::assertSame( array( $run_id ), array_values( array_unique( array_column( array_column( $this->audit_rows(), 'context' ), 'run_id' ) ) ), 'Every product record and the completed record.' );

		$second  = $this->batch->execute();
		$preview = $this->batch->preview();
		self::assertNotSame( $run_id, $second->run_id->value );
		self::assertNotSame( $second->run_id->value, $preview->run_id->value );
	}

	public function test_250_products_are_processed_in_pages_of_100_each_exactly_once(): void {
		$this->many( 250 );

		$report = $this->batch->execute();

		self::assertSame( 250, $report->result->count( ReconciliationOutcome::Linked ) );
		self::assertSame( 3, $report->pages );
		self::assertSame( array( array( 0, 100 ), array( 199, 100 ), array( 299, 100 ) ), $this->candidates->calls );
		self::assertCount( 250, array_unique( $this->products->writes ) );
		self::assertCount( 250, $this->products->writes, 'No product twice.' );
		self::assertSame( range( 100, 349 ), $this->audited_products() );
	}

	public function test_a_second_run_on_the_same_state_changes_nothing(): void {
		$this->seed();
		$this->batch->execute();
		$writes   = $this->products->writes;
		$products = count( $this->product_audit() );
		$tables   = array( $this->db->rows( Tables::CREATOR_CATEGORIES ), $this->db->rows( Tables::CREATORS ) );

		$second = $this->batch->execute();

		self::assertSame( 0, $second->result->count( ReconciliationOutcome::Linked ) + $second->result->count( ReconciliationOutcome::Moved ) + $second->result->count( ReconciliationOutcome::Unlinked ) );
		self::assertSame( 0, $second->result->count( ReconciliationOutcome::Error ) );
		self::assertSame( $writes, $this->products->writes );
		self::assertCount( $products, $this->product_audit() );
		self::assertSame( $tables, array( $this->db->rows( Tables::CREATOR_CATEGORIES ), $this->db->rows( Tables::CREATORS ) ) );
		self::assertSame( array(), $this->batch->preview()->changes );
	}

	public function test_a_failing_product_is_rolled_back_alone_and_counted(): void {
		$this->seed();
		$this->products->on_write = function ( int $id ): void {
			$this->products->fail_write = 10 === $id;
		};

		$report = $this->batch->execute();

		self::assertSame( 1, $report->result->count( ReconciliationOutcome::Error ) );
		self::assertSame( 0, $report->result->count( ReconciliationOutcome::Linked ) );
		self::assertNull( $this->products->find( 10 )?->creator_id() );
		self::assertNotContains( 10, $this->audited_products(), 'Its audit record was rolled back.' );
		self::assertSame( 1, $this->completed()['errors'], 'The run completed with the error counted.' );
		self::assertSame( $report->run_id->value, $this->logger->at( 'warning' )[0]['context']['run_id'] );
	}

	public function test_mappings_and_creators_are_only_read(): void {
		$this->seed();
		$tables = array( $this->db->rows( Tables::CREATOR_CATEGORIES ), $this->db->rows( Tables::CREATORS ), $this->db->rows( Tables::CREATOR_META ) );

		$this->batch->execute();

		self::assertSame( $tables, array( $this->db->rows( Tables::CREATOR_CATEGORIES ), $this->db->rows( Tables::CREATORS ), $this->db->rows( Tables::CREATOR_META ) ) );
	}

	public function test_links_are_written_only_inside_the_category_grant_for_that_product(): void {
		$this->seed();
		$seen                     = array();
		$guard                    = $this->guard;
		$this->products->on_write = static function ( int $id ) use ( &$seen, $guard ): void {
			$active = ( new ReflectionProperty( ProtectedProductMeta::class, 'active' ) )->getValue( $guard );
			$seen[] = array( $id, $active?->purpose, $active?->product_id );
		};

		$this->batch->execute();

		self::assertSame(
			array(
				array( 10, ProtectedMetaPurpose::CategoryReconciliation, 10 ),
				array( 11, ProtectedMetaPurpose::CategoryReconciliation, 11 ),
				array( 12, ProtectedMetaPurpose::CategoryReconciliation, 12 ),
				array( 16, ProtectedMetaPurpose::CategoryReconciliation, 16 ),
			),
			$seen
		);
		self::assertNull( ( new ReflectionProperty( ProtectedProductMeta::class, 'active' ) )->getValue( $guard ), 'Closed after the run.' );
		self::assertFalse( $guard->filter_add( null, 10, ProductCreatorLink::META_KEY ) );
	}

	public function test_the_run_keeps_no_static_state(): void {
		foreach ( array( CategoryReconciliationBatch::class, CreatorCategoryReconciler::class, ReconciliationRunId::class, CategorySyncReport::class, ReconciliationPlan::class, FakeReconciliationCandidates::class ) as $class ) {
			self::assertSame( array(), ( new ReflectionClass( $class ) )->getStaticProperties(), $class );
		}
	}

	public function test_a_run_id_is_a_lowercase_uuid_v4(): void {
		$ids = array_map( static fn(): string => ReconciliationRunId::generate()->value, range( 1, 20 ) );

		foreach ( $ids as $id ) {
			self::assertMatchesRegularExpression( self::UUID, $id );
		}
		self::assertCount( 20, array_unique( $ids ) );
		self::assertSame( '0f8fad5b-d9cb-469f-a165-70867728950e', ( new ReconciliationRunId( '0f8fad5b-d9cb-469f-a165-70867728950e' ) )->value );

		$this->expectException( InvalidArgumentException::class );
		new ReconciliationRunId( '0F8FAD5B-D9CB-469F-A165-70867728950E' );
	}

	/**
	 * One product per outcome of SEEDED_COUNTS, plus products that are no
	 * candidates.
	 */
	private function seed(): void {
		$this->product( 10, array( self::ALPHA_CAT ) );                                          // Linked.
		$this->products->add_linked( 11, $this->alpha, 'category', array( self::BETA_CAT ) );    // Moved.
		$this->products->add_linked( 12, $this->alpha, 'category', array( self::GENERAL_CAT ) ); // Unlinked: left the category.
		$this->products->add_linked( 13, $this->beta, 'admin', array( self::ALPHA_CAT ) );       // Manual.
		$this->products->add_linked( 14, $this->beta, null, array( self::ALPHA_CAT ) );          // Manual (no origin).
		$this->product( 15, array( self::ALPHA_CAT, self::BETA_CAT ) );                          // Ambiguous.
		$this->products->add_linked( 16, $this->alpha, 'category', array( self::ALPHA_CAT, self::BETA_CAT ) ); // Ambiguous, removed.
		$this->product( 17, array( self::GAMMA_CAT ) );                                          // Inactive creator.
		$this->products->add_linked( 18, $this->alpha, 'category', array( self::ALPHA_CAT ) );   // Unchanged.
		$this->product( 19, array( self::ALPHA_CAT ), 'trash' );
		$this->product( 20, array( self::ALPHA_CAT ), 'auto-draft' );
		$this->products->add( 21, array(), 'publish', 10, '', '', array(), array( self::ALPHA_CAT ) ); // Variation.
		$this->product( 22, array( self::GENERAL_CAT ) );                                        // Unmapped only.
	}

	/**
	 * Unlinked products 100 .. 100 + $count - 1 in the alpha category.
	 *
	 * @param int $count Number of products.
	 */
	private function many( int $count ): void {
		for ( $id = 100; $id < 100 + $count; $id++ ) {
			$this->product( $id, array( self::ALPHA_CAT ) );
		}
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
	 * Product link audit rows with decoded context.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function product_audit(): array {
		return array_values( array_filter( $this->audit_rows(), static fn( array $row ): bool => str_starts_with( (string) $row['action'], 'product_' ) ) );
	}

	/**
	 * Product ids of the product link audit rows, in order.
	 *
	 * @return list<int>
	 */
	private function audited_products(): array {
		return array_map( static fn( array $row ): int => (int) $row['context']['product_id'], $this->product_audit() );
	}

	/**
	 * Context of the last `category_sync_completed`.
	 *
	 * @return array<string, mixed>
	 */
	private function completed(): array {
		$rows = array_values( array_filter( $this->audit_rows(), static fn( array $row ): bool => 'category_sync_completed' === $row['action'] ) );
		self::assertNotSame( array(), $rows );

		return $rows[ count( $rows ) - 1 ]['context'];
	}

	/**
	 * Every plugin table.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function tables(): array {
		$tables = array();

		foreach ( Tables::all() as $table ) {
			$tables[ $table ] = $this->db->rows( $table );
		}

		return $tables;
	}

	/**
	 * Every product as stored.
	 *
	 * @return list<mixed>
	 */
	private function product_state(): array {
		return array_map( fn( int $id ): mixed => $this->products->find( $id ), $this->products->ids() );
	}
}
