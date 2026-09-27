<?php
/**
 * Tests for the queued category reconciliation jobs.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce;

use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\ProtectedMetaPurpose;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationBatch;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationJobs;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationResult;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationScheduler;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationTrigger;
use MerchBoard\Modules\WooCommerce\CategorySyncPage;
use MerchBoard\Modules\WooCommerce\CreatorCategoryReconciler;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ReconciliationOutcome;
use MerchBoard\Modules\WooCommerce\ReconciliationScope;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use MerchBoard\Tests\Unit\Support\FakeProductStore;
use MerchBoard\Tests\Unit\Support\FakeReconciliationCandidates;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;

#[CoversClass( CategoryReconciliationJobs::class )]
#[CoversClass( CategoryReconciliationScheduler::class )]
#[CoversClass( CategorySyncPage::class )]
#[CoversClass( ReconciliationScope::class )]
#[CoversClass( CategoryReconciliationResult::class )]
final class CategoryReconciliationJobsTest extends TestCase {

	use CreatorAdminEnvironment;

	private const ALPHA_CAT   = 16;
	private const BETA_CAT    = 17;
	private const GENERAL_CAT = 19;

	private FakeProductStore $products;

	private FakeReconciliationCandidates $candidates;

	private FakeJobQueue $queue;

	private ProtectedProductMeta $guard;

	private RecordingLogger $logger;

	private CategoryReconciliationScheduler $scheduler;

	private CategoryReconciliationJobs $jobs;

	private int $alpha;

	private int $beta;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_creator_admin();

		$this->alpha = $this->service->create( 'Alpha', CreatorStatus::Active, null )->id;
		$this->beta  = $this->service->create( 'Beta', CreatorStatus::Active, null )->id;

		$mappings = new CreatorCategoryRepository( $this->db );
		$mappings->insert( self::ALPHA_CAT, $this->alpha, 7 );
		$mappings->insert( self::BETA_CAT, $this->beta, 7 );

		// Jobs run as system work, e.g. from WP-Cron: no user, no capabilities.
		$this->caps            = array();
		$this->current_user_id = 0;

		$this->products   = new FakeProductStore();
		$this->candidates = new FakeReconciliationCandidates(
			$this->products,
			fn(): array => array_map( 'intval', array_column( $this->db->rows( Tables::CREATOR_CATEGORIES ), 'term_id' ) )
		);
		$this->queue      = new FakeJobQueue();
		$this->guard      = new ProtectedProductMeta( new RecordingLogger() );
		$this->logger     = new RecordingLogger();
		$creators         = new CreatorRepository( $this->db );
		$audit            = new AuditTrail( new AuditRepository( $this->db ) );
		$reconciler       = new CreatorCategoryReconciler(
			$this->products,
			$mappings,
			$creators,
			new ProductCreatorLink( $this->products, $creators, $audit, new Transaction( $this->db, new TransactionSupport( $this->db ) ), $this->guard ),
			$this->logger
		);

		$this->scheduler = new CategoryReconciliationScheduler( $this->queue, $this->logger );
		$this->jobs      = new CategoryReconciliationJobs(
			$reconciler,
			new CategoryReconciliationBatch( $this->candidates, $reconciler, $audit, $this->logger ),
			$this->scheduler,
			$this->logger
		);
	}

	public function test_a_product_job_reconciles_the_product_as_system_work(): void {
		$this->product( 10, array( self::ALPHA_CAT ) );

		self::assertTrue( $this->scheduler->product( 10, CategoryReconciliationTrigger::CategoryChanged, 5 ) );
		self::assertSame( array( 'merchboard_reconcile_product' ), $this->queue->pending_hooks() );
		$this->drain();

		self::assertSame( $this->alpha, $this->products->find( 10 )?->creator_id() );
		$audit = $this->product_audit();
		self::assertSame( array( null, 'category_added', 5 ), array( $audit[0]['actor_user_id'], $audit[0]['context']['reason'], $audit[0]['context']['trigger_user_id'] ) );
	}

	public function test_identical_product_jobs_are_queued_once_and_repeated_events_are_idempotent(): void {
		$this->product( 10, array( self::ALPHA_CAT ) );

		for ( $i = 0; $i < 3; $i++ ) {
			self::assertTrue( $this->scheduler->product( 10, CategoryReconciliationTrigger::CategoryChanged, 5 ) );
		}
		self::assertCount( 1, $this->queue->pending, 'Deduplicated while pending.' );

		$this->scheduler->product( 10, CategoryReconciliationTrigger::TermDeleted, 5 );
		self::assertCount( 2, $this->queue->pending, 'Another trigger is another job ...' );
		$this->drain();

		self::assertCount( 1, $this->products->writes, '... but only one change.' );
		self::assertCount( 1, $this->product_audit() );

		$this->scheduler->product( 10, CategoryReconciliationTrigger::CategoryChanged, 5 );
		$this->drain();
		self::assertCount( 1, $this->products->writes, 'A later duplicate event changes nothing.' );
	}

	public function test_a_run_job_pages_through_its_scope_in_bounded_batches_and_completes_once(): void {
		for ( $id = 100; $id < 350; $id++ ) {
			$this->product( $id, array( self::ALPHA_CAT ) );
		}
		$this->product( 400, array( self::BETA_CAT ) );

		self::assertTrue( $this->scheduler->run( ReconciliationScope::category( self::ALPHA_CAT ), CategoryReconciliationTrigger::MappingCreated, 5 ) );
		self::assertSame( 3, $this->drain(), 'One job per page of at most 100; the short last page completes the run.' );

		self::assertSame( array( array( 0, 100 ), array( 199, 100 ), array( 299, 100 ) ), $this->candidates->calls );
		self::assertCount( 250, $this->products->writes );
		self::assertCount( 250, array_unique( $this->products->writes ), 'No product twice.' );
		self::assertNull( $this->products->find( 400 )?->creator_id(), 'Only the scope: no full backfill.' );

		$completed = $this->completed();
		self::assertCount( 1, $completed );
		self::assertSame( array( 'category', self::ALPHA_CAT, 'mapping_created', 250, 5 ), array( $completed[0]['scope'], $completed[0]['term_id'], $completed[0]['trigger'], $completed[0]['linked'], $completed[0]['trigger_user_id'] ) );
		self::assertSame( array( $completed[0]['run_id'] ), array_values( array_unique( array_column( array_column( $this->product_audit(), 'context' ), 'run_id' ) ) ), 'One run id across all jobs.' );
	}

	public function test_identical_run_jobs_are_queued_once(): void {
		$scope = ReconciliationScope::category( self::ALPHA_CAT );

		$this->scheduler->run( $scope, CategoryReconciliationTrigger::CreatorActivated, null );
		$this->scheduler->run( $scope, CategoryReconciliationTrigger::CreatorActivated, null );

		self::assertCount( 1, $this->queue->pending );
	}

	public function test_a_failed_page_is_repeated_with_the_same_run_and_no_false_completion(): void {
		for ( $id = 100; $id < 250; $id++ ) {
			$this->product( $id, array( self::ALPHA_CAT ) );
		}
		$this->candidates->fail_on_call = 2;
		$this->scheduler->run( ReconciliationScope::category( self::ALPHA_CAT ), CategoryReconciliationTrigger::MappingCreated, null );

		$this->queue->run_next( $this->runner() ); // Page 1.
		$this->queue->run_next( $this->runner() ); // Page 2 fails.

		self::assertCount( 1, $this->queue->failed, 'Action Scheduler sees the failure.' );
		self::assertSame( array(), $this->completed(), 'No completed record for an incomplete run.' );
		$retry = $this->queue->pending[0];
		self::assertSame( 60, $retry['delay'] );
		self::assertSame( 1, $retry['args'][0]['attempt'] );
		self::assertSame( 199, $retry['args'][0]['run']['after_id'], 'Same cursor.' );
		self::assertSame( 100, $retry['args'][0]['run']['counts']['linked'], 'Counts so far carried.' );
		self::assertSame( 'Category reconciliation page failed; repeat queued.', $this->logger->at( 'warning' )[0]['message'] );

		$this->drain();

		$completed = $this->completed();
		self::assertCount( 1, $completed );
		self::assertSame( 150, $completed[0]['linked'] );
		self::assertSame( $retry['args'][0]['run']['run_id'], $completed[0]['run_id'] );
		self::assertCount( 150, array_unique( $this->products->writes ) );
	}

	public function test_a_run_is_given_up_after_the_retries_without_completion(): void {
		$this->product( 100, array( self::ALPHA_CAT ) );
		$this->candidates->stuck        = true;
		$this->candidates->fail_on_call = 1;
		$this->scheduler->run( ReconciliationScope::category( self::ALPHA_CAT ), CategoryReconciliationTrigger::MappingCreated, null );

		$this->candidates->fail_on_call = null;
		$failing                        = function ( string $hook, array $args ): void {
			$this->candidates->fail_on_call = count( $this->candidates->calls ) + 1;
			( $this->runner() )( $hook, $args );
		};

		self::assertSame( 4, $this->queue->drain( $failing ), 'First attempt and three repeats.' );
		self::assertSame( array( 60, 300, 900 ), array_slice( array_column( $this->queue->queued, 'delay' ), 1 ) );
		self::assertCount( 4, $this->queue->failed );
		self::assertSame( array(), $this->queue->pending );
		self::assertSame( array(), $this->completed() );
		self::assertSame( 'Category reconciliation run given up after retries; not completed.', $this->logger->at( 'error' )[ count( $this->logger->at( 'error' ) ) - 1 ]['message'] );
	}

	public function test_a_failed_product_is_repeated_a_bounded_number_of_times(): void {
		$this->product( 10, array( self::ALPHA_CAT ) );
		$this->products->fail_write = true;
		$this->scheduler->product( 10, CategoryReconciliationTrigger::CategoryChanged, null );

		self::assertSame( 4, $this->drain() );

		self::assertSame( array( 0, 60, 300, 900 ), array_column( $this->queue->queued, 'delay' ) );
		self::assertSame( array( 0, 1, 2, 3 ), array_map( static fn( array $job ): int => $job['args'][0]['attempt'], $this->queue->queued ) );
		self::assertSame( array(), $this->product_audit(), 'Every failed attempt rolled back.' );
		self::assertSame( 'Category reconciliation of a product given up after retries.', $this->logger->at( 'error' )[0]['message'] );

		$this->products->fail_write = false;
		$this->scheduler->product( 10, CategoryReconciliationTrigger::CategoryChanged, null );
		$this->drain();
		self::assertSame( $this->alpha, $this->products->find( 10 )?->creator_id(), 'A later event recovers.' );
	}

	public function test_manual_links_are_never_changed_by_jobs(): void {
		$this->products->add_linked( 10, $this->beta, 'admin', array( self::ALPHA_CAT ) );
		$this->products->add_linked( 11, $this->beta, null, array( self::ALPHA_CAT ) );

		$this->scheduler->product( 10, CategoryReconciliationTrigger::CategoryChanged, null );
		$this->scheduler->run( ReconciliationScope::mapping( self::ALPHA_CAT, $this->beta ), CategoryReconciliationTrigger::MappingRemoved, null );
		$this->drain();

		self::assertSame( array(), $this->products->writes );
		self::assertSame( 2, $this->completed()[0]['skipped_manual'] );
	}

	public function test_a_mapping_run_also_reconciles_category_links_of_products_that_left_the_category(): void {
		$this->products->add_linked( 10, $this->alpha, 'category', array( self::GENERAL_CAT ) ); // Left the category.
		$this->products->add_linked( 11, $this->alpha, 'category', array( self::ALPHA_CAT ) );   // Still in it.
		$this->products->add_linked( 12, $this->beta, 'category', array( self::BETA_CAT ) );     // Another creator.
		( new CreatorCategoryRepository( $this->db ) )->delete( self::ALPHA_CAT, $this->alpha );

		$this->scheduler->run( ReconciliationScope::mapping( self::ALPHA_CAT, $this->alpha ), CategoryReconciliationTrigger::MappingRemoved, 5 );
		$this->drain();

		self::assertNull( $this->products->find( 10 )?->creator_id() );
		self::assertNull( $this->products->find( 11 )?->creator_id() );
		self::assertSame( $this->beta, $this->products->find( 12 )?->creator_id(), 'Out of scope.' );
		self::assertSame( array( 'mapping_removed', 'mapping_removed' ), array_map( static fn( array $row ): string => $row['context']['reason'], $this->product_audit() ) );
	}

	public function test_jobs_write_only_inside_the_category_grant_for_that_product(): void {
		$this->product( 10, array( self::ALPHA_CAT ) );
		$seen                     = array();
		$guard                    = $this->guard;
		$this->products->on_write = static function ( int $id ) use ( &$seen, $guard ): void {
			$active = ( new ReflectionProperty( ProtectedProductMeta::class, 'active' ) )->getValue( $guard );
			$seen[] = array( $id, $active?->purpose, $active?->product_id );
		};

		$this->scheduler->run( ReconciliationScope::category( self::ALPHA_CAT ), CategoryReconciliationTrigger::MappingCreated, null );
		$this->drain();

		self::assertSame( array( array( 10, ProtectedMetaPurpose::CategoryReconciliation, 10 ) ), $seen );
		self::assertNull( ( new ReflectionProperty( ProtectedProductMeta::class, 'active' ) )->getValue( $guard ) );
	}

	public function test_without_action_scheduler_nothing_is_queued_or_run_and_it_is_logged(): void {
		$this->product( 10, array( self::ALPHA_CAT ) );
		$this->queue->is_available = false;

		self::assertFalse( $this->scheduler->product( 10, CategoryReconciliationTrigger::CategoryChanged, null ) );
		self::assertFalse( $this->scheduler->run( ReconciliationScope::category( self::ALPHA_CAT ), CategoryReconciliationTrigger::MappingCreated, null ) );

		self::assertSame( array(), $this->queue->queued );
		self::assertSame( array(), $this->products->writes, 'No synchronous fallback.' );
		$warnings = $this->logger->at( 'warning' );
		self::assertCount( 2, $warnings );
		self::assertSame( 'Category reconciliation not queued: Action Scheduler is not available.', $warnings[0]['message'] );
		self::assertSame( array( 'job' => 'merchboard_reconcile_product', 'product_id' => 10, 'trigger' => 'category_changed' ), $warnings[0]['context'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_a_refused_job_is_logged(): void {
		$this->queue->refuse = true;

		self::assertFalse( $this->scheduler->product( 10, CategoryReconciliationTrigger::CategoryChanged, null ) );
		self::assertSame( 'Category reconciliation could not be queued.', $this->logger->at( 'error' )[0]['message'] );
	}

	public function test_invalid_payloads_are_dropped_and_logged(): void {
		foreach ( array( null, array(), array( 'product_id' => 0, 'trigger' => 'category_changed', 'trigger_user_id' => null, 'attempt' => 0 ), array( 'product_id' => 10, 'trigger' => 'nope', 'trigger_user_id' => null, 'attempt' => 0 ) ) as $payload ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->jobs->run_product( $payload );
		}

		foreach ( array( null, array( 'run' => null, 'attempt' => 0 ), array( 'run' => array( 'run_id' => 'x' ), 'attempt' => 0 ), array( 'run' => null, 'scope' => array( 'scope' => 'all' ), 'trigger' => 'full_sync', 'trigger_user_id' => 'x', 'attempt' => 0 ) ) as $payload ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->jobs->run_page( $payload );
		}

		self::assertCount( 8, $this->logger->at( 'error' ) );
		self::assertSame( array(), $this->queue->queued, 'Never retried.' );
		self::assertSame( array(), $this->products->writes );
	}

	public function test_run_state_survives_a_payload_round_trip_and_rejects_tampering(): void {
		$state = CategorySyncPage::start( ReconciliationScope::mapping( null, 3 ), CategoryReconciliationTrigger::TermDeleted, 5 );
		$state = new CategorySyncPage( $state->run_id, $state->scope, $state->trigger, $state->trigger_user_id, $state->result->with( ReconciliationOutcome::Moved ), 42, 1, false );

		$copy = CategorySyncPage::from_array( $state->to_array() );

		self::assertEquals( $state, $copy );
		self::assertSame(
			array(
				'scope'      => 'mapping',
				'creator_id' => 3,
			),
			$copy->scope->to_array()
		);

		$data           = $state->to_array();
		$data['counts'] = array( 'linked' => -1 ) + $data['counts'];
		$this->expectException( InvalidArgumentException::class );
		CategorySyncPage::from_array( $data );
	}

	public function test_scopes_reject_invalid_data(): void {
		$rejected = 0;

		foreach ( array( array( 'scope' => 'category' ), array( 'scope' => 'all', 'term_id' => 3 ), array( 'scope' => 'category', 'term_id' => '3' ), array( 'scope' => 'mapping', 'creator_id' => 0 ) ) as $data ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			try {
				ReconciliationScope::from_array( $data );
			} catch ( InvalidArgumentException ) {
				++$rejected;
			}
		}

		self::assertSame( 4, $rejected );

		self::assertSame( array( 'scope' => 'all' ), ReconciliationScope::from_array( array( 'scope' => 'all' ) )->to_array() );
	}

	/**
	 * Runs every pending job (and the ones they queue).
	 */
	private function drain(): int {
		return $this->queue->drain( $this->runner() );
	}

	/**
	 * Calls the job callback like Action Scheduler does (hook → callback with
	 * the job's arguments).
	 *
	 * @return callable(string, array<mixed>): void
	 */
	private function runner(): callable {
		return function ( string $hook, array $args ): void {
			if ( CategoryReconciliationScheduler::PRODUCT_HOOK === $hook ) {
				$this->jobs->run_product( ...$args );
			} else {
				$this->jobs->run_page( ...$args );
			}
		};
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
	 * Product link audit rows with decoded context.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function product_audit(): array {
		return array_values( array_filter( $this->audit_rows(), static fn( array $row ): bool => str_starts_with( (string) $row['action'], 'product_' ) ) );
	}

	/**
	 * Contexts of the `category_sync_completed` records.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function completed(): array {
		return array_column( array_filter( $this->audit_rows(), static fn( array $row ): bool => 'category_sync_completed' === $row['action'] ), 'context' );
	}
}
