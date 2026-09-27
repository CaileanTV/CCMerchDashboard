<?php
/**
 * The full category reconciliation view and request on a real database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\CategorySyncActions;
use MerchBoard\Modules\WooCommerce\Admin\CategorySyncScreen;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsActions;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationBatch;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationJobs;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationScheduler;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationTrigger;
use MerchBoard\Modules\WooCommerce\CreatorCategoryReconciler;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ReconciliationCandidateRepository;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Unit\Support\FakeJobQueue;
use MerchBoard\Tests\Unit\Support\RecordingLogger;

/**
 * WooCommerce and Action Scheduler are not installed here: products are
 * `product` posts behind PostMetaProductStore, and a recording queue runs the
 * jobs through do_action_ref_array(), like Action Scheduler's runner.
 * Everything else is real: the core guard, transactions, audit and MariaDB.
 * The admin is user 1; `edit_product` is mapped like WooCommerce does.
 */
final class CategorySyncAdminTest extends IntegrationTestCase {

	private FakeJobQueue $queue;

	private CategoryReconciliationBatch $batch;

	private CategorySyncActions $actions;

	private CategorySyncScreen $screen;

	private CategoryReconciliationJobs $jobs;

	private ProductCreatorLink $link;

	private int $alpha;

	private int $beta;

	private int $gamma;

	private int $alpha_cat;

	private int $beta_cat;

	private int $gamma_cat;

	/**
	 * Products by role.
	 *
	 * @var array<string, int>
	 */
	private array $ids = array();

	/**
	 * Saved $_GET.
	 *
	 * @var array<mixed>
	 */
	private array $saved_get = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		delete_option( Settings::OPTION ); // ADR-036 window closed (see ProtectedProductMetaTest).
		wp_set_current_user( 1 );
		$this->saved_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture.
		add_filter( 'map_meta_cap', array( self::class, 'product_caps' ), 10, 2 );
		register_taxonomy( 'product_cat', 'product', array( 'hierarchical' => true ) );

		$this->alpha_cat = $this->term( 'Sync Alpha' );
		$this->beta_cat  = $this->term( 'Sync Beta' );
		$this->gamma_cat = $this->term( 'Sync Gamma' );

		$creators    = $this->service( CreatorService::class );
		$this->alpha = $creators->create( 'Sync Alpha', CreatorStatus::Active, null )->id;
		$this->beta  = $creators->create( 'Sync Beta', CreatorStatus::Active, null )->id;
		$this->gamma = $creators->create( 'Sync Gamma', CreatorStatus::Inactive, null )->id;

		$mappings = new CreatorCategoryRepository( $this->wpdb );
		$mappings->insert( $this->alpha_cat, $this->alpha, 1 );
		$mappings->insert( $this->beta_cat, $this->beta, 1 );
		$mappings->insert( $this->gamma_cat, $this->gamma, 1 );

		$products   = new PostMetaProductStore();
		$repository = $this->service( CreatorRepository::class );
		$audit      = $this->service( AuditTrail::class );
		$logger     = $this->service( Logger::class );
		$this->link = new ProductCreatorLink( $products, $repository, $audit, $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
		$reconciler = new CreatorCategoryReconciler( $products, $mappings, $repository, $this->link, $logger );

		$this->queue   = new FakeJobQueue();
		$scheduler     = new CategoryReconciliationScheduler( $this->queue, new RecordingLogger() );
		$this->batch   = new CategoryReconciliationBatch( new ReconciliationCandidateRepository( $this->wpdb ), $reconciler, $audit, $logger );
		$this->actions = new CategorySyncActions( $this->batch, $scheduler, $logger );
		$this->screen  = new CategorySyncScreen( $this->batch, $products, $mappings, $repository );
		$this->jobs    = new CategoryReconciliationJobs( $reconciler, $this->batch, $scheduler, $logger );
		$this->jobs->register();

		$this->ids = array(
			'linked'    => $this->product( array( $this->alpha_cat ) ),
			'moved'     => $this->product( array( $this->beta_cat ), $this->alpha, 'category' ),
			'removed'   => $this->product( array(), $this->alpha, 'category' ),
			'manual'    => $this->product( array( $this->alpha_cat ), $this->beta, 'admin' ),
			'ambiguous' => $this->product( array( $this->alpha_cat, $this->beta_cat ) ),
			'inactive'  => $this->product( array( $this->gamma_cat ) ),
		);
	}

	/**
	 * Maps `edit_product` like WooCommerce's product post type does.
	 *
	 * @param mixed $caps Primitive capabilities.
	 * @param mixed $cap  Requested capability.
	 * @return mixed
	 */
	public static function product_caps( mixed $caps, mixed $cap ): mixed {
		return in_array( $cap, array( 'edit_product', 'edit_products' ), true ) ? array( 'edit_posts' ) : $caps;
	}

	protected function tearDown(): void {
		remove_action( CategoryReconciliationScheduler::PRODUCT_HOOK, array( $this->jobs, 'run_product' ) );
		remove_action( CategoryReconciliationScheduler::RUN_HOOK, array( $this->jobs, 'run_page' ) );
		remove_filter( 'map_meta_cap', array( self::class, 'product_caps' ), 10 );

		foreach ( $this->ids as $id ) {
			$this->execute( 'DELETE FROM %i WHERE post_id = %d', $this->wpdb->postmeta, $id );
			$this->execute( 'DELETE FROM %i WHERE object_id = %d', $this->wpdb->term_relationships, $id );
			$this->execute( 'DELETE FROM %i WHERE ID = %d', $this->wpdb->posts, $id );
		}

		foreach ( array( $this->alpha_cat, $this->beta_cat, $this->gamma_cat ) as $term_id ) {
			wp_delete_term( $term_id, 'product_cat' );
		}

		unregister_taxonomy( 'product_cat' );

		if ( post_type_exists( 'product' ) ) {
			unregister_post_type( 'product' );
		}

		$_GET = $this->saved_get;
		wp_set_current_user( 0 );
		wp_cache_flush();
		parent::tearDown();
	}

	public function test_the_dry_run_view_shows_every_outcome_and_needs_attention_and_writes_nothing(): void {
		$before = $this->snapshot();

		$_GET = array( CategorySyncActions::PREVIEW_ARG => '1' );
		ob_start();
		$this->screen->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<tr><th scope="row">Total</th><td>6</td></tr>', $html );
		self::assertStringContainsString( 'Ambiguous: the product is directly in categories mapped to several creators', $html );
		self::assertStringContainsString( 'Sync Gamma → Sync Gamma (inactive)', $html );
		self::assertStringContainsString( 'name="fingerprint"', $html );
		self::assertSame( $before, $this->snapshot(), 'No meta, mapping, creator or audit change.' );
		self::assertSame( array(), $this->queue->queued, 'No job.' );
	}

	public function test_manual_links_that_deviate_from_their_categories_are_shown_and_never_changed(): void {
		register_post_type( 'product', array( 'capability_type' => 'post', 'show_ui' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Edit links need show_ui.
		$this->ids['manual_inactive']  = $this->product( array( $this->gamma_cat ), $this->alpha, null, 'Manual inactive' );
		$this->ids['manual_ambiguous'] = $this->product( array( $this->alpha_cat, $this->beta_cat ), $this->alpha, 'admin', 'Manual ambiguous' );
		$this->ids['manual_same']      = $this->product( array( $this->alpha_cat ), $this->alpha, 'admin', 'Manual same' );
		$before                        = $this->snapshot();

		$html = $this->render_preview();

		self::assertStringContainsString( 'Manual link to another creator than the product&#039;s mapped category. The manual link wins and is not changed.</td><td>Sync Beta<br><span class="description">Manual</span></td><td>Sync Alpha → Sync Alpha</td>', $html );
		self::assertStringContainsString( 'whose creator is inactive. The manual link wins and is not changed.</td><td>Sync Alpha<br><span class="description">Manual</span></td><td>Sync Gamma → Sync Gamma (inactive)</td>', $html, 'No origin stored: manual.' );
		self::assertStringContainsString( 'mapped to several creators. The manual link wins and is not changed.</td><td>Sync Alpha<br><span class="description">Manual</span></td><td>Sync Alpha → Sync Alpha; Sync Beta → Sync Beta</td>', $html );
		self::assertStringContainsString( 'Manual inactive (#' . $this->ids['manual_inactive'] . ')', $html );
		self::assertStringNotContainsString( 'Manual same', $html, 'A manual link to the mapped creator is no conflict.' );
		self::assertStringContainsString( '<tr><td>Manual link, never changed</td><td>4</td></tr>', $html, 'Counted as manual only.' );
		self::assertStringContainsString( 'post.php?post=' . $this->ids['manual_inactive'] . '&#038;action=edit', $html, 'Product link for a user who may edit it.' );
		self::assertSame( $before, $this->snapshot(), 'No meta, mapping, creator or audit change.' );
		self::assertSame( array(), $this->queue->queued, 'No job.' );

		$manager = get_user_by( 'id', $this->create_user( 'sync_manager' ) );
		self::assertInstanceOf( \WP_User::class, $manager );
		$manager->add_cap( Capabilities::MANAGE_CREATORS );
		wp_set_current_user( $manager->ID );
		$html = $this->render_preview();
		self::assertStringContainsString( 'Manual inactive (#' . $this->ids['manual_inactive'] . ')', $html, 'Still listed.' );
		self::assertStringNotContainsString( 'post.php?post=', $html, 'No product link without edit_product.' );

		wp_set_current_user( 1 );
		$this->actions->start( $this->form() );
		$this->drain();

		self::assertSame( array( (string) $this->alpha, null ), $this->link( $this->ids['manual_inactive'] ), 'Manual links win.' );
		self::assertSame( array( (string) $this->alpha, 'admin' ), $this->link( $this->ids['manual_ambiguous'] ) );
		self::assertSame( array( (string) $this->beta, 'admin' ), $this->link( $this->ids['manual'] ) );
	}

	public function test_a_confirmed_run_is_queued_and_the_jobs_apply_the_reconciliation_rules(): void {
		$before = $this->snapshot();

		$url = $this->actions->start( $this->form() );

		self::assertStringContainsString( CategorySyncActions::RESULT_ARG . '=sync_started', $url );
		self::assertSame( $before, $this->snapshot(), 'The request itself changes nothing.' );
		self::assertSame( array( CategoryReconciliationScheduler::RUN_HOOK ), $this->queue->pending_hooks() );

		$this->drain();

		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $this->ids['linked'] ) );
		self::assertSame( array( (string) $this->beta, 'category' ), $this->link( $this->ids['moved'] ) );
		self::assertSame( array( null, null ), $this->link( $this->ids['removed'] ) );
		self::assertSame( array( (string) $this->beta, 'admin' ), $this->link( $this->ids['manual'] ), 'Manual links win.' );
		self::assertSame( array( null, null ), $this->link( $this->ids['ambiguous'] ), 'Ambiguous: never assigned.' );
		self::assertSame( array( null, null ), $this->link( $this->ids['inactive'] ), 'Inactive creator: no new link.' );

		$completed = array_values( array_filter( $this->audit(), static fn( array $row ): bool => 'category_sync_completed' === $row['action'] ) );
		self::assertCount( 1, $completed );
		self::assertSame( array( 'full_sync', 'all', 1, 1 ), array( $completed[0]['context']['trigger'], $completed[0]['context']['scope'], $completed[0]['context']['trigger_user_id'], $completed[0]['context']['ambiguous'] ) );
		self::assertNull( $completed[0]['actor_user_id'] );
	}

	public function test_a_stale_dry_run_starts_nothing(): void {
		$form = $this->form();
		$this->execute( 'DELETE FROM %i WHERE object_id = %d', $this->wpdb->term_relationships, $this->ids['linked'] );
		clean_object_term_cache( $this->ids['linked'], 'product' );

		self::assertStringContainsString( '=preview_outdated', $this->actions->start( $form ) );
		self::assertSame( array(), $this->queue->queued );
	}

	public function test_the_request_has_no_ajax_rest_or_logged_out_route(): void {
		$this->actions->register();

		self::assertSame( 10, has_action( 'admin_post_' . CategorySyncActions::ACTION, array( $this->actions, 'handle_request' ) ) );
		self::assertFalse( has_action( 'admin_post_nopriv_' . CategorySyncActions::ACTION ) );
		self::assertFalse( has_action( 'wp_ajax_' . CategorySyncActions::ACTION ) );
		self::assertFalse( has_action( 'wp_ajax_nopriv_' . CategorySyncActions::ACTION ) );
		self::assertSame( array(), array_filter( array_keys( rest_get_server()->get_routes() ), static fn( string $route ): bool => str_starts_with( $route, '/merchboard' ) && ( str_contains( $route, 'sync' ) || str_contains( $route, 'categor' ) ) ) );

		remove_action( 'admin_post_' . CategorySyncActions::ACTION, array( $this->actions, 'handle_request' ) );
	}

	public function test_after_a_run_remove_keep_as_manual_and_the_guards_are_unchanged(): void {
		$this->actions->start( $this->form() );
		$this->drain();
		$product  = $this->ids['linked'];
		$products = new CreatorProductsActions( $this->link, new RecordingLogger() );

		$unlink = array(
			'creator_id' => (string) $this->alpha,
			'product_id' => (string) $product,
			'_wpnonce'   => wp_create_nonce( CreatorProductsActions::unlink_nonce_action( $this->alpha ) ),
		);
		self::assertStringContainsString( $product . ':category_managed', $products->unlink( $unlink ), 'Remove is still blocked for a category link.' );

		$keep = array(
			'creator_id' => (string) $this->alpha,
			'product_id' => (string) $product,
			'confirm'    => '1',
			'_wpnonce'   => wp_create_nonce( CreatorProductsActions::keep_nonce_action( $this->alpha ) ),
		);
		self::assertStringContainsString( $product . ':kept_manual', $products->keep_as_manual( $keep ) );
		self::assertSame( array( (string) $this->alpha, 'admin' ), $this->link( $product ) );

		self::assertFalse( update_post_meta( $product, ProductCreatorLink::META_KEY, (string) $this->beta ), 'Generic writes stay blocked.' );
		self::assertFalse( delete_post_meta( $product, ProductCreatorLink::SOURCE_META_KEY ), 'The ADR-036 window is closed outside an uninstall.' );
		self::assertSame( 1000, has_filter( 'delete_post_metadata', array( $this->service( ProtectedProductMeta::class ), 'filter_delete' ) ) );
	}

	/**
	 * A confirmed request with a real nonce for the current state.
	 *
	 * @return array<string, string>
	 */
	private function form(): array {
		return array(
			'_wpnonce'    => wp_create_nonce( CategorySyncActions::ACTION ),
			'confirm'     => '1',
			'fingerprint' => $this->batch->preview( CategoryReconciliationTrigger::FullSync, 1 )->fingerprint(),
		);
	}

	/**
	 * The dry run view for the current user.
	 */
	private function render_preview(): string {
		$_GET = array( CategorySyncActions::PREVIEW_ARG => '1' );
		ob_start();
		$this->screen->render();

		return (string) ob_get_clean();
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

		return (int) $term['term_id'];
	}

	/**
	 * A product with categories and optionally a link stored directly, like
	 * an earlier ProductCreatorLink write.
	 *
	 * @phpstan-param list<int> $categories
	 *
	 * @param int[]       $categories Direct categories.
	 * @param int|null    $creator_id Linked creator.
	 * @param string|null $source     Link origin.
	 * @param string      $title      Product name.
	 */
	private function product( array $categories, ?int $creator_id = null, ?string $source = null, string $title = 'Sync tee' ): int {
		$id = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => $title ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		if ( array() !== $categories ) {
			wp_set_object_terms( $id, $categories, 'product_cat' );
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
	 * Audit rows, decoded.
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
	 * All post meta and every plugin table.
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
