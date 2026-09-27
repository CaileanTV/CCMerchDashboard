<?php
/**
 * The mapping administration flows on a real database.
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
use MerchBoard\Modules\WooCommerce\Admin\CreatorCategoriesActions;
use MerchBoard\Modules\WooCommerce\CategoryMappingPreview;
use MerchBoard\Modules\WooCommerce\CategoryMappingRemoval;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationBatch;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationHooks;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationJobs;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationScheduler;
use MerchBoard\Modules\WooCommerce\CreatorCategoryReconciler;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ReconciliationCandidateRepository;
use MerchBoard\Modules\WooCommerce\ReconciliationOutcome;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use MerchBoard\Tests\Unit\Support\RecordingLogger;

/**
 * WooCommerce and Action Scheduler are not installed here: the module's
 * services, hooks and jobs are wired on the booted plugin's core services,
 * with a recording queue run through do_action_ref_array(). Everything else
 * is real: WordPress' term, post and meta APIs, the core guard, transactions,
 * audit and MariaDB. The admin is user 1.
 */
final class CategoryMappingAdminTest extends IntegrationTestCase {

	private FakeJobQueue $queue;

	private CreatorCategoryService $mappings;

	private CreatorCategoryRepository $stored;

	private CategoryMappingPreview $preview;

	private CategoryMappingRemoval $removal;

	private CreatorCategoriesActions $actions;

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
		$this->alpha_cat   = $this->term( 'Admin Alpha' );
		$this->beta_cat    = $this->term( 'Admin Beta' );
		$this->general_cat = $this->term( 'Admin General' );

		$creators    = $this->service( CreatorService::class );
		$this->alpha = $creators->create( 'Admin Alpha', CreatorStatus::Active, null )->id;
		$this->beta  = $creators->create( 'Admin Beta', CreatorStatus::Active, null )->id;

		$products       = new PostMetaProductStore();
		$repository     = $this->service( CreatorRepository::class );
		$audit          = $this->service( AuditTrail::class );
		$transaction    = $this->service( Transaction::class );
		$logger         = $this->service( Logger::class );
		$link           = new ProductCreatorLink( $products, $repository, $audit, $transaction, $this->service( ProtectedProductMeta::class ) );
		$candidates     = new ReconciliationCandidateRepository( $this->wpdb );
		$this->stored   = new CreatorCategoryRepository( $this->wpdb );
		$reconciler     = new CreatorCategoryReconciler( $products, $this->stored, $repository, $link, $logger );
		$this->queue    = new FakeJobQueue();
		$scheduler      = new CategoryReconciliationScheduler( $this->queue, new RecordingLogger() );
		$this->mappings = new CreatorCategoryService( $this->stored, $repository, $audit, $transaction );
		$this->preview  = new CategoryMappingPreview( $this->mappings, $this->stored, $products, $repository, $link, $candidates, $audit, $logger );
		$this->removal  = new CategoryMappingRemoval( $this->mappings, $this->stored, $this->preview, $link, $logger );
		$this->actions  = new CreatorCategoriesActions( $this->mappings, $this->removal, $logger );
		$this->hooks    = new CategoryReconciliationHooks( $scheduler, $this->mappings, $logger );
		$this->jobs     = new CategoryReconciliationJobs( $reconciler, new CategoryReconciliationBatch( $candidates, $reconciler, $audit, $logger ), $scheduler, $logger );

		$this->hooks->register();
		$this->jobs->register();

		// Stand-in for WooCommerce's product capabilities: an editor of posts
		// may edit products.
		add_filter( 'map_meta_cap', array( self::class, 'product_caps' ), 10, 2 );
	}

	/**
	 * Maps `edit_product` like WooCommerce's product post type does.
	 *
	 * @param mixed $caps Primitive capabilities.
	 * @param mixed $cap  Requested capability.
	 * @return mixed
	 */
	public static function product_caps( mixed $caps, mixed $cap ): mixed {
		return 'edit_product' === $cap ? array( 'edit_posts' ) : $caps;
	}

	protected function tearDown(): void {
		remove_filter( 'map_meta_cap', array( self::class, 'product_caps' ), 10 );

		foreach ( array( 'set_object_terms' => 'terms_set', 'deleted_term_relationships' => 'terms_removed', 'transition_post_status' => 'status_changed', 'pre_delete_term' => 'term_deleting', 'delete_term' => 'term_deleted', CreatorCategoryService::LINKED_ACTION => 'mapping_created', CreatorCategoryService::UNLINKED_ACTION => 'mapping_removed', CreatorService::STATUS_CHANGED_ACTION => 'creator_status_changed' ) as $hook => $method ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			remove_action( $hook, array( $this->hooks, $method ) );
		}

		remove_action( CategoryReconciliationScheduler::PRODUCT_HOOK, array( $this->jobs, 'run_product' ) );
		remove_action( CategoryReconciliationScheduler::RUN_HOOK, array( $this->jobs, 'run_page' ) );

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

	public function test_a_preview_writes_nothing_and_the_confirmed_mapping_starts_the_reconciliation(): void {
		$product = $this->product( array( $this->alpha_cat ) );
		$manual  = $this->product( array( $this->alpha_cat ), $this->beta, 'admin' );
		$before  = $this->snapshot();

		$report = $this->preview->for_new_mapping( $this->alpha, $this->alpha_cat );

		self::assertSame( array( 1, 1 ), array( $report->result->count( ReconciliationOutcome::Linked ), $report->result->count( ReconciliationOutcome::SkippedManual ) ) );
		self::assertSame( $before, $this->snapshot(), 'No mapping, meta, audit, creator or post change.' );
		self::assertSame( array(), $this->queue->queued );

		self::assertStringContainsString( '=confirmation_required', $this->actions->map( array( 'confirm' => '' ) + $this->map_form( $this->alpha_cat ) ) );
		self::assertSame( $before, $this->snapshot(), 'Nothing without the confirmation.' );

		self::assertStringContainsString( '=mapped', $this->actions->map( $this->map_form( $this->alpha_cat ) ) );
		$this->drain();

		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $product ) );
		self::assertSame( array( (string) $this->beta, 'admin' ), $this->link( $manual ) );
	}

	public function test_remove_links_also_reaches_products_that_left_the_category(): void {
		$this->map( $this->alpha_cat );
		$inside = $this->product( array( $this->alpha_cat ), $this->alpha, 'category' );
		$left   = $this->product( array(), $this->alpha, 'category' );
		$manual = $this->product( array( $this->alpha_cat ), $this->alpha, 'admin' );
		$this->drain();

		self::assertSame( 2, $this->preview->for_removal( $this->mapping() )->result->count( ReconciliationOutcome::Unlinked ) );
		self::assertStringContainsString( '=removed', $this->actions->unmap( $this->unmap_form( $this->alpha_cat, 'remove_links' ) ) );
		$this->drain();

		self::assertSame( array( null, null ), $this->link( $inside ) );
		self::assertSame( array( null, null ), $this->link( $left ) );
		self::assertSame( array( (string) $this->alpha, 'admin' ), $this->link( $manual ) );
	}

	public function test_keep_as_manual_converts_through_the_guard_keeps_the_creator_and_is_idempotent(): void {
		$this->map( $this->alpha_cat );
		$inside = $this->product( array( $this->alpha_cat ), $this->alpha, 'category' );
		$left   = $this->product( array(), $this->alpha, 'category' );
		$other  = $this->product( array( $this->beta_cat ), $this->beta, 'admin' );
		$this->drain();
		$posts    = $this->post_rows();
		$creators = $this->table_rows( Tables::CREATORS );

		$url = $this->actions->unmap( $this->unmap_form( $this->alpha_cat, 'keep_as_manual' ) );
		$this->drain();

		self::assertStringContainsString( '=kept_removed', $url );
		self::assertSame( array( (string) $this->alpha, 'admin' ), $this->link( $inside ), 'Creator kept, origin admin.' );
		self::assertSame( array( (string) $this->alpha, 'admin' ), $this->link( $left ) );
		self::assertSame( array( (string) $this->beta, 'admin' ), $this->link( $other ) );
		self::assertNull( $this->mappings->for_category( $this->alpha_cat ) );
		self::assertSame( $posts, $this->post_rows(), 'No other product data changed.' );
		self::assertSame( $creators, $this->table_rows( Tables::CREATORS ) );

		$converted = array_values( array_filter( $this->audit(), static fn( array $row ): bool => 'product_creator_linked' === $row['action'] && 'category' === ( $row['context']['previous_source'] ?? null ) ) );
		self::assertCount( 2, $converted );
		self::assertSame( array( '1' ), array_values( array_unique( array_column( $converted, 'actor_user_id' ) ) ) );

		wp_set_object_terms( $inside, array( $this->general_cat ), 'product_cat' );
		$this->drain();
		self::assertSame( array( (string) $this->alpha, 'admin' ), $this->link( $inside ), 'Manual now: categories no longer change it.' );

		self::assertFalse( update_post_meta( $inside, ProductCreatorLink::SOURCE_META_KEY, 'category' ), 'Generic writes stay blocked.' );
	}

	public function test_a_product_whose_audit_fails_stays_a_category_link_and_the_mapping_stays(): void {
		$this->map( $this->alpha_cat );
		$first  = $this->product( array( $this->alpha_cat ), $this->alpha, 'category' );
		$second = $this->product( array( $this->alpha_cat ), $this->alpha, 'category' );
		$this->drain();

		$this->wpdb->suppress_errors( true );
		$failed = false;
		$fail   = static function ( string $query ) use ( &$failed ): string {
			if ( ! $failed && str_starts_with( $query, 'INSERT INTO `mbtest_merchboard_audit_log`' ) ) {
				$failed = true;

				return 'INSERT INTO mbtest_this_table_does_not_exist VALUES (1)';
			}

			return $query;
		};
		add_filter( 'query', $fail );

		try {
			$result = $this->removal->keep_as_manual( $this->alpha, $this->alpha_cat );
		} finally {
			remove_filter( 'query', $fail );
		}

		self::assertSame( array( $first => 'error' ), $result->failed );
		wp_cache_delete( $first, 'post_meta' );
		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $first ), 'Rolled back with its audit record.' );
		self::assertSame( array( (string) $this->alpha, 'admin' ), $this->link( $second ) );
		self::assertNotNull( $this->mappings->for_category( $this->alpha_cat ) );

		self::assertTrue( $this->removal->keep_as_manual( $this->alpha, $this->alpha_cat )->removed, 'The repeat finishes the job.' );
		self::assertSame( array( (string) $this->alpha, 'admin' ), $this->link( $first ) );
	}

	public function test_an_orphaned_mapping_is_identified_and_cleaned_up_with_the_choice(): void {
		$this->map( $this->alpha_cat );
		$left = $this->product( array(), $this->alpha, 'category' );
		$this->drain();

		// The category is deleted while the WooCommerce module is not loaded.
		$this->hooks_off();
		wp_delete_term( $this->alpha_cat, 'product_cat' );
		$this->terms = array_values( array_diff( $this->terms, array( $this->alpha_cat ) ) );
		$this->hooks->register();

		$mapping = $this->mapping();
		self::assertTrue( CreatorCategoryService::is_orphaned( $mapping ) );
		self::assertSame( 0, $this->preview->for_removal( $mapping )->result->count( ReconciliationOutcome::Linked ), 'It creates no ownership.' );

		self::assertStringContainsString( '=kept_removed', $this->actions->unmap( $this->unmap_form( $this->alpha_cat, 'keep_as_manual' ) ) );
		$this->drain();

		self::assertNull( $this->mappings->for_category( $this->alpha_cat ) );
		self::assertSame( array( (string) $this->alpha, 'admin' ), $this->link( $left ) );
		$removal = array_values( array_filter( $this->audit(), static fn( array $row ): bool => 'creator_category_unlinked' === $row['action'] ) )[0];
		self::assertSame( array( 'term_deleted', 'keep_as_manual', '1' ), array( $removal['context']['reason'], $removal['context']['choice'], $removal['actor_user_id'] ) );
	}

	/**
	 * Unhooks the event handlers (as if the module were not loaded).
	 */
	private function hooks_off(): void {
		foreach ( array( 'set_object_terms' => 'terms_set', 'deleted_term_relationships' => 'terms_removed', 'pre_delete_term' => 'term_deleting', 'delete_term' => 'term_deleted' ) as $hook => $method ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			remove_action( $hook, array( $this->hooks, $method ) );
		}

		( new \ReflectionProperty( CategoryReconciliationHooks::class, 'registered' ) )->setValue( $this->hooks, false );
	}

	/**
	 * Maps the category to Alpha through the confirmed request.
	 *
	 * @param int $term_id Term id.
	 */
	private function map( int $term_id ): void {
		self::assertStringContainsString( '=mapped', $this->actions->map( $this->map_form( $term_id ) ) );
		$this->drain();
	}

	/**
	 * Alpha's stored mapping of the alpha category.
	 */
	private function mapping(): \MerchBoard\Modules\WooCommerce\CreatorCategory {
		$mapping = $this->stored->find( $this->alpha_cat );
		self::assertNotNull( $mapping );

		return $mapping;
	}

	/**
	 * A confirmed mapping request for Alpha.
	 *
	 * @param int $term_id Term id.
	 * @return array<string, string>
	 */
	private function map_form( int $term_id ): array {
		return array(
			'creator_id' => (string) $this->alpha,
			'term_id'    => (string) $term_id,
			'confirm'    => '1',
			'_wpnonce'   => wp_create_nonce( CreatorCategoriesActions::map_nonce_action( $this->alpha ) ),
		);
	}

	/**
	 * A confirmed removal request for Alpha.
	 *
	 * @param int    $term_id Term id.
	 * @param string $choice  Choice.
	 * @return array<string, string>
	 */
	private function unmap_form( int $term_id, string $choice ): array {
		return array(
			'creator_id' => (string) $this->alpha,
			'term_id'    => (string) $term_id,
			'choice'     => $choice,
			'confirm'    => '1',
			'_wpnonce'   => wp_create_nonce( CreatorCategoriesActions::unmap_nonce_action( $this->alpha ) ),
		);
	}

	/**
	 * Runs every queued job like Action Scheduler.
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
	 * directly, like an earlier ProductCreatorLink write. Categories are set
	 * before the link, so the category change is reconciled on the product's
	 * state without a link.
	 *
	 * @phpstan-param list<int> $categories
	 *
	 * @param int[]       $categories Direct categories.
	 * @param int|null    $creator_id Linked creator.
	 * @param string|null $source     Link origin.
	 */
	private function product( array $categories, ?int $creator_id = null, ?string $source = null ): int {
		$id            = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Mapped tee' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->posts[] = $id;

		// Assigned directly, so no category event fires for the setup.
		foreach ( $categories as $term_id ) {
			$this->execute( 'INSERT INTO %i (object_id, term_taxonomy_id) SELECT %d, term_taxonomy_id FROM %i WHERE taxonomy = %s AND term_id = %d', $this->wpdb->term_relationships, $id, $this->wpdb->term_taxonomy, 'product_cat', $term_id );
		}

		if ( array() !== $categories ) {
			wp_update_term_count_now( $categories, 'product_cat' );
			clean_object_term_cache( $id, 'product' );
		}

		if ( null !== $creator_id ) {
			$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, $id, ProductCreatorLink::META_KEY, (string) $creator_id );
		}

		if ( null !== $source ) {
			$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, $id, ProductCreatorLink::SOURCE_META_KEY, $source );
		}

		wp_cache_delete( $id, 'post_meta' );

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
	 * Audit rows, decoded, in order.
	 *
	 * @return list<array<string, mixed>>
	 * @phpstan-impure
	 */
	private function audit(): array {
		return array_map(
			static function ( array $row ): array {
				$row['context'] = json_decode( (string) $row['context'], true );

				return $row;
			},
			$this->rows( Tables::AUDIT_LOG )
		);
	}

	/**
	 * The test products' post rows.
	 *
	 * @return list<array<string, mixed>>
	 * @phpstan-impure
	 */
	private function post_rows(): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i WHERE post_type = %s ORDER BY ID', $this->wpdb->posts, 'product' ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every row of a plugin table.
	 *
	 * @param string $table Unprefixed plugin table.
	 * @return list<array<string, mixed>>
	 * @phpstan-impure
	 */
	private function table_rows( string $table ): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $this->table( $table ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Posts, all post meta, term relationships and every plugin table.
	 *
	 * @return array<string, mixed>
	 * @phpstan-impure
	 */
	private function snapshot(): array {
		$snapshot = array(
			'posts' => $this->post_rows(),
			'meta'  => $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY meta_id', $this->wpdb->postmeta ), ARRAY_A ),
			'terms' => $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY object_id, term_taxonomy_id', $this->wpdb->term_relationships ), ARRAY_A ),
		);

		foreach ( Tables::all() as $table ) {
			$snapshot[ $table ] = $this->table_rows( $table );
		}

		return $snapshot;
	}
}
