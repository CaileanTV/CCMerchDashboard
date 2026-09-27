<?php
/**
 * The automatic category reconciliation from real WordPress events.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationBatch;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationHooks;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationJobs;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationScheduler;
use MerchBoard\Modules\WooCommerce\CreatorCategoryReconciler;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;
use MerchBoard\Modules\WooCommerce\MappingRemovalChoice;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ReconciliationCandidateRepository;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use MerchBoard\Tests\Unit\Support\RecordingLogger;

/**
 * WooCommerce (and with it Action Scheduler) is not installed here. The test
 * wires the module's hook and job classes itself on the booted plugin's core
 * services, with a recording queue whose jobs it runs through WordPress'
 * do_action_ref_array(), like Action Scheduler's runner. Everything else is
 * real: WordPress term and post APIs, the core guard, transactions, audit and
 * MariaDB.
 */
final class CategoryReconciliationHooksTest extends IntegrationTestCase {

	private FakeJobQueue $queue;

	private RecordingLogger $logger;

	private CreatorCategoryService $mappings;

	private CategoryReconciliationHooks $hooks;

	private CategoryReconciliationJobs $jobs;

	private int $alpha;

	private int $beta;

	private int $alpha_cat;

	private int $beta_cat;

	private int $general_cat;

	/**
	 * Posts created by the test.
	 *
	 * @var list<int>
	 */
	private array $posts = array();

	/**
	 * Terms created by the test.
	 *
	 * @var list<int>
	 */
	private array $terms = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		delete_option( Settings::OPTION ); // ADR-036 window closed (see ProtectedProductMetaTest).
		wp_set_current_user( 1 );

		register_taxonomy( 'product_cat', 'product', array( 'hierarchical' => true ) );
		$this->alpha_cat   = $this->term( 'Hooks Alpha' );
		$this->beta_cat    = $this->term( 'Hooks Beta' );
		$this->general_cat = $this->term( 'Hooks General' );

		$creators    = $this->service( CreatorService::class );
		$this->alpha = $creators->create( 'Hooks Alpha', CreatorStatus::Active, null )->id;
		$this->beta  = $creators->create( 'Hooks Beta', CreatorStatus::Active, null )->id;

		$products   = new PostMetaProductStore();
		$repository = $this->service( CreatorRepository::class );
		$audit      = $this->service( AuditTrail::class );
		$reconciler = new CreatorCategoryReconciler(
			$products,
			new CreatorCategoryRepository( $this->wpdb ),
			$repository,
			new ProductCreatorLink( $products, $repository, $audit, $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) ),
			$this->service( Logger::class )
		);

		$this->queue    = new FakeJobQueue();
		$this->logger   = new RecordingLogger();
		$scheduler      = new CategoryReconciliationScheduler( $this->queue, $this->logger );
		$this->mappings = new CreatorCategoryService( new CreatorCategoryRepository( $this->wpdb ), $repository, $audit, $this->service( Transaction::class ) );
		$this->hooks    = new CategoryReconciliationHooks( $scheduler, $this->mappings, $this->logger );
		$this->jobs     = new CategoryReconciliationJobs(
			$reconciler,
			new CategoryReconciliationBatch( new ReconciliationCandidateRepository( $this->wpdb ), $reconciler, $audit, $this->logger ),
			$scheduler,
			$this->logger
		);

		$this->hooks->register();
		$this->jobs->register();
	}

	protected function tearDown(): void {
		foreach ( self::callbacks() as $hook => $callback ) {
			remove_action( $hook, array( $this->owner( $hook ), $callback ) );
		}

		foreach ( $this->posts as $id ) {
			$this->execute( 'DELETE FROM %i WHERE post_id = %d', $this->wpdb->postmeta, $id );
			$this->execute( 'DELETE FROM %i WHERE object_id = %d', $this->wpdb->term_relationships, $id );
			$this->execute( 'DELETE FROM %i WHERE ID = %d', $this->wpdb->posts, $id );
		}

		foreach ( $this->terms as $term_id ) {
			wp_delete_term( $term_id, 'product_cat' );
		}

		unregister_taxonomy( 'product_cat' );
		wp_set_current_user( 0 );
		wp_cache_flush();
		parent::tearDown();
	}

	public function test_the_handlers_are_hooked_exactly_once(): void {
		$this->hooks->register();
		$this->jobs->register();

		foreach ( self::callbacks() as $hook => $callback ) {
			$object = $this->owner( $hook );
			self::assertSame( 10, has_action( $hook, array( $object, $callback ) ), $hook );
			self::assertCount( 1, array_filter( $GLOBALS['wp_filter'][ $hook ]->callbacks[10], static fn( array $entry ): bool => is_array( $entry['function'] ) && $entry['function'][0] === $object ), $hook );
		}
	}

	public function test_adding_removing_and_moving_categories_reconciles_the_product(): void {
		$this->map( $this->alpha, $this->alpha_cat );
		$this->map( $this->beta, $this->beta_cat );
		$product = $this->product( array() );

		wp_set_object_terms( $product, array( $this->alpha_cat ), 'product_cat' );
		self::assertSame( array( null, null ), $this->link( $product ), 'Only queued: nothing written during the save.' );
		$this->drain();
		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $product ) );

		wp_set_object_terms( $product, array( $this->beta_cat, $this->general_cat ), 'product_cat' );
		$this->drain();
		self::assertSame( array( (string) $this->beta, 'category' ), $this->link( $product ), 'Moved.' );

		wp_remove_object_terms( $product, array( $this->beta_cat ), 'product_cat' );
		$this->drain();
		self::assertSame( array( null, null ), $this->link( $product ), 'Removed.' );

		self::assertSame(
			array( 'linked:category_added', 'unlinked:category_moved', 'linked:category_moved', 'unlinked:category_removed' ),
			array_map( static fn( array $row ): string => substr( (string) $row['action'], strlen( 'product_creator_' ) ) . ':' . $row['context']['reason'], $this->product_audit() )
		);
		self::assertSame( array( null ), array_values( array_unique( array_column( $this->product_audit(), 'actor_user_id' ) ) ), 'System actor ...' );
		self::assertSame( array( 1 ), array_values( array_unique( array_column( array_column( $this->product_audit(), 'context' ), 'trigger_user_id' ) ) ), '... with the triggering user.' );
	}

	public function test_duplicate_events_queue_once_and_change_nothing_twice(): void {
		$this->map( $this->alpha, $this->alpha_cat );
		$product = $this->product( array() );

		wp_set_object_terms( $product, array( $this->alpha_cat ), 'product_cat' );
		wp_set_object_terms( $product, array( $this->alpha_cat ), 'product_cat' );
		wp_add_object_terms( $product, array( $this->alpha_cat ), 'product_cat' );
		self::assertCount( 1, $this->queue->pending );

		$this->drain();
		wp_set_object_terms( $product, array( $this->alpha_cat, $this->general_cat ), 'product_cat' );
		$this->drain();

		self::assertCount( 1, $this->product_audit() );
	}

	public function test_a_product_restored_from_the_trash_is_reconciled_again(): void {
		$this->map( $this->alpha, $this->alpha_cat );
		$product = $this->product( array() );
		wp_trash_post( $product );
		self::assertSame( array(), $this->queue->pending, 'Trashing queues nothing.' );

		wp_set_object_terms( $product, array( $this->alpha_cat ), 'product_cat' );
		$this->drain();
		self::assertSame( array( null, null ), $this->link( $product ), 'No new link in the trash.' );

		wp_untrash_post( $product );
		self::assertCount( 1, $this->queue->pending );
		$this->drain();
		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $product ) );
	}

	public function test_a_mapping_created_reconciles_only_its_category(): void {
		$in_category = $this->product( array( $this->alpha_cat ) );
		$manual      = $this->product( array( $this->alpha_cat ), $this->beta, 'admin' );
		$elsewhere   = $this->product( array(), $this->beta, 'category' ); // A category link no event touched.
		$this->drain();

		self::assertTrue( $this->mappings->link( $this->alpha, $this->alpha_cat ) );
		self::assertSame( array( CategoryReconciliationScheduler::RUN_HOOK ), $this->queue->pending_hooks() );
		$this->drain();

		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $in_category ) );
		self::assertSame( array( (string) $this->beta, 'admin' ), $this->link( $manual ), 'Manual link untouched.' );
		self::assertSame( array( (string) $this->beta, 'category' ), $this->link( $elsewhere ), 'No full backfill.' );
		self::assertSame( 'mapping_created', $this->product_audit()[0]['context']['reason'] );

		$completed = $this->completed();
		self::assertCount( 1, $completed );
		self::assertSame( array( 'category', $this->alpha_cat, 'mapping_created', 1, 1, 1 ), array( $completed[0]['scope'], $completed[0]['term_id'], $completed[0]['trigger'], $completed[0]['linked'], $completed[0]['skipped_manual'], $completed[0]['trigger_user_id'] ) );
	}

	public function test_a_mapping_removed_also_reconciles_products_that_already_left_the_category(): void {
		$this->map( $this->alpha, $this->alpha_cat );
		$in_category = $this->product( array( $this->alpha_cat ), $this->alpha, 'category' );
		$left        = $this->product( array(), $this->alpha, 'category' ); // Left before; no event since.
		$manual      = $this->product( array( $this->alpha_cat ), $this->alpha, 'admin' );
		$this->drain();

		self::assertTrue( $this->mappings->unlink( $this->alpha, $this->alpha_cat, MappingRemovalChoice::RemoveLinks ) );
		$this->drain();

		self::assertSame( array( null, null ), $this->link( $in_category ) );
		self::assertSame( array( null, null ), $this->link( $left ) );
		self::assertSame( array( (string) $this->alpha, 'admin' ), $this->link( $manual ) );
		self::assertSame( array( 'mapping_removed', 'mapping_removed' ), array_column( array_column( $this->product_audit(), 'context' ), 'reason' ) );
		self::assertSame( 'remove_links', $this->mapping_audit()[1]['context']['choice'] );
	}

	public function test_deleting_a_mapped_category_removes_the_mapping_and_its_category_links(): void {
		$this->map( $this->alpha, $this->alpha_cat );
		$in_category = $this->product( array( $this->alpha_cat, $this->general_cat ) );
		$manual      = $this->product( array( $this->alpha_cat ), $this->alpha, 'admin' );
		$this->drain();
		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $in_category ) );

		self::assertTrue( wp_delete_term( $this->alpha_cat, 'product_cat' ) );
		$this->terms = array_values( array_diff( $this->terms, array( $this->alpha_cat ) ) );

		self::assertNull( $this->mappings->for_category( $this->alpha_cat ), 'Mapping removed right away.' );
		$removal = $this->mapping_audit()[1];
		self::assertSame( array( null, 'term_deleted', 1 ), array( $removal['actor_user_id'], $removal['context']['reason'], $removal['context']['trigger_user_id'] ) );

		$this->drain();

		self::assertSame( array( null, null ), $this->link( $in_category ) );
		self::assertSame( array( (string) $this->alpha, 'admin' ), $this->link( $manual ) );
		self::assertSame( array( 'term_deleted' ), array_values( array_unique( array_column( array_column( array_slice( $this->product_audit(), 1 ), 'context' ), 'reason' ) ) ) );
	}

	public function test_a_reactivated_creator_gets_the_links_of_its_categories(): void {
		$creators = $this->service( CreatorService::class );
		$this->map( $this->alpha, $this->alpha_cat );
		$creators->set_status( $this->alpha, CreatorStatus::Inactive );
		$product = $this->product( array( $this->alpha_cat ) );
		$this->drain();
		self::assertSame( array( null, null ), $this->link( $product ), 'Inactive: no new link.' );

		$creators->set_status( $this->alpha, CreatorStatus::Active );
		self::assertSame( array( CategoryReconciliationScheduler::RUN_HOOK ), $this->queue->pending_hooks() );
		$this->drain();

		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $product ) );
		self::assertSame( 'creator_activated', $this->product_audit()[0]['context']['reason'] );
	}

	public function test_a_failed_page_is_repeated_and_completes_once(): void {
		$this->map( $this->alpha, $this->alpha_cat );
		$product              = $this->product( array( $this->alpha_cat ) );
		$this->queue->pending = array();

		$this->wpdb->suppress_errors( true );
		$failed = false;
		$fail   = static function ( string $query ) use ( &$failed ): string {
			if ( ! $failed && str_starts_with( ltrim( $query ), 'SELECT p.ID FROM' ) ) {
				$failed = true;

				return 'SELECT ID FROM mbtest_this_table_does_not_exist';
			}

			return $query;
		};
		add_filter( 'query', $fail );

		try {
			do_action( CreatorService::STATUS_CHANGED_ACTION, $this->alpha, 'active', 'inactive' );
			$this->drain();
		} finally {
			remove_filter( 'query', $fail );
		}

		self::assertCount( 1, $this->queue->failed );
		$retries = array_values( array_filter( $this->queue->queued, static fn( array $job ): bool => 1 === ( $job['args'][0]['attempt'] ?? null ) ) );
		self::assertCount( 1, $retries );
		self::assertSame( 60, $retries[0]['delay'] );
		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $product ), 'The repeat reconciled it.' );
		self::assertCount( 1, $this->completed(), 'One completed record, after the repeat.' );
		self::assertSame( $retries[0]['args'][0]['run']['run_id'], $this->completed()[0]['run_id'] );
	}

	public function test_without_action_scheduler_nothing_is_queued_or_written(): void {
		$this->map( $this->alpha, $this->alpha_cat );
		$this->queue->is_available = false;
		$product                   = $this->product( array() );

		wp_set_object_terms( $product, array( $this->alpha_cat ), 'product_cat' );
		$this->drain();

		self::assertSame( array( null, null ), $this->link( $product ), 'No synchronous fallback.' );
		self::assertSame( 'Category reconciliation not queued: Action Scheduler is not available.', $this->logger->at( 'warning' )[0]['message'] );
	}

	public function test_generic_writes_stay_blocked_while_jobs_write_through_the_guard(): void {
		$this->map( $this->alpha, $this->alpha_cat );
		$product = $this->product( array( $this->alpha_cat ) );
		$this->drain();

		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $product ), 'Written by the job inside its grant.' );
		self::assertFalse( update_post_meta( $product, ProductCreatorLink::META_KEY, (string) $this->beta ) );
		self::assertFalse( update_post_meta( $product, ProductCreatorLink::SOURCE_META_KEY, 'admin' ) );
		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $product ) );
	}

	/**
	 * Hook => callback method of the module's handlers and jobs.
	 *
	 * @return array<string, string>
	 */
	private static function callbacks(): array {
		return array(
			'set_object_terms'                            => 'terms_set',
			'deleted_term_relationships'                  => 'terms_removed',
			'transition_post_status'                      => 'status_changed',
			'pre_delete_term'                             => 'term_deleting',
			'delete_term'                                 => 'term_deleted',
			CreatorCategoryService::LINKED_ACTION         => 'mapping_created',
			CreatorCategoryService::UNLINKED_ACTION       => 'mapping_removed',
			CreatorService::STATUS_CHANGED_ACTION         => 'creator_status_changed',
			CategoryReconciliationScheduler::PRODUCT_HOOK => 'run_product',
			CategoryReconciliationScheduler::RUN_HOOK     => 'run_page',
		);
	}

	/**
	 * The object whose method handles the hook.
	 *
	 * @param string $hook Hook.
	 */
	private function owner( string $hook ): CategoryReconciliationHooks|CategoryReconciliationJobs {
		return in_array( $hook, array( CategoryReconciliationScheduler::PRODUCT_HOOK, CategoryReconciliationScheduler::RUN_HOOK ), true ) ? $this->jobs : $this->hooks;
	}

	/**
	 * Runs every queued job like Action Scheduler: do_action_ref_array() with
	 * the job's arguments.
	 */
	private function drain(): void {
		$this->queue->drain(
			static function ( string $hook, array $args ): void {
				if ( '' !== $hook ) {
					do_action_ref_array( $hook, $args );
				}
			}
		);
	}

	/**
	 * Maps a category through the service and runs its (empty) first run.
	 *
	 * @param int $creator_id Creator id.
	 * @param int $term_id    Term id.
	 */
	private function map( int $creator_id, int $term_id ): void {
		$this->mappings->link( $creator_id, $term_id );
		$this->drain();
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
	 * Creates a product with categories and optionally a link stored
	 * directly, like an earlier ProductCreatorLink write.
	 *
	 * @phpstan-param list<int> $categories
	 *
	 * @param int[]       $categories Direct categories.
	 * @param int|null    $creator_id Linked creator.
	 * @param string|null $source     Link origin.
	 */
	private function product( array $categories, ?int $creator_id = null, ?string $source = null ): int {
		$id            = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Hooked tee' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->posts[] = $id;

		if ( null !== $creator_id ) {
			$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, $id, ProductCreatorLink::META_KEY, (string) $creator_id );
		}

		if ( null !== $source ) {
			$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, $id, ProductCreatorLink::SOURCE_META_KEY, $source );
		}

		wp_cache_delete( $id, 'post_meta' );

		if ( array() !== $categories ) {
			wp_set_object_terms( $id, $categories, 'product_cat' );
		}

		return $id;
	}

	/**
	 * The stored link and origin, straight from the database.
	 *
	 * @param int $product_id Product id.
	 * @return array{0: string|null, 1: string|null}
	 * @phpstan-impure
	 */
	private function link( int $product_id ): array {
		$get = fn( string $key ): ?string => $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s', $this->wpdb->postmeta, $product_id, $key ) );

		return array( $get( ProductCreatorLink::META_KEY ), $get( ProductCreatorLink::SOURCE_META_KEY ) );
	}

	/**
	 * Audit rows with a given action prefix, decoded, in order.
	 *
	 * @param string $prefix Action prefix.
	 * @return list<array<string, mixed>>
	 * @phpstan-impure
	 */
	private function audit( string $prefix ): array {
		$rows = array();

		foreach ( $this->rows( Tables::AUDIT_LOG ) as $row ) {
			if ( str_starts_with( (string) $row['action'], $prefix ) ) {
				$row['context'] = json_decode( (string) $row['context'], true );
				$rows[]         = $row;
			}
		}

		return $rows;
	}

	/**
	 * Product link audit rows.
	 *
	 * @return list<array<string, mixed>>
	 * @phpstan-impure
	 */
	private function product_audit(): array {
		return $this->audit( 'product_creator_' );
	}

	/**
	 * Mapping audit rows.
	 *
	 * @return list<array<string, mixed>>
	 * @phpstan-impure
	 */
	private function mapping_audit(): array {
		return $this->audit( 'creator_category_' );
	}

	/**
	 * Contexts of the `category_sync_completed` records with products.
	 *
	 * @return list<array<string, mixed>>
	 * @phpstan-impure
	 */
	private function completed(): array {
		return array_values( array_filter( array_column( $this->audit( 'category_sync_completed' ), 'context' ), static fn( array $c ): bool => $c['total'] > 0 ) );
	}
}
