<?php
/**
 * Origin and "Keep as manual" in the creator's product section on a real
 * database.
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
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsActions;
use MerchBoard\Modules\WooCommerce\Admin\CreatorProductsSection;
use MerchBoard\Modules\WooCommerce\CategoryLinkStatus;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationTrigger;
use MerchBoard\Modules\WooCommerce\CreatorCategoryReconciler;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ReconciliationOutcome;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use MerchBoard\Tests\Unit\Support\RecordingLogger;

/**
 * WooCommerce is not installed here: products are `product` posts read and
 * written through PostMetaProductStore on WordPress' APIs, so the booted
 * core guard decides every write. `edit_product` is mapped like WooCommerce
 * does. The admin is user 1.
 */
final class CreatorProductsOriginTest extends IntegrationTestCase {

	private CreatorProductsActions $actions;

	private CreatorProductsSection $section;

	private CreatorCategoryReconciler $reconciler;

	private CreatorCategoryRepository $mappings;

	private Creator $alpha;

	private int $category;

	/**
	 * Posts created by the test.
	 *
	 * @var list<int>
	 */
	private array $posts = array();

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		delete_option( Settings::OPTION ); // ADR-036 window closed (see ProtectedProductMetaTest).
		wp_set_current_user( 1 );
		add_filter( 'map_meta_cap', array( self::class, 'product_caps' ), 10, 2 );
		register_taxonomy( 'product_cat', 'product', array( 'hierarchical' => true ) );
		$term = wp_insert_term( 'Origin Alpha', 'product_cat' );
		self::assertIsArray( $term );
		$this->category = (int) $term['term_id'];

		$this->alpha = $this->service( CreatorService::class )->create( 'Origin Alpha', CreatorStatus::Active, null );

		$products         = new PostMetaProductStore();
		$creators         = $this->service( CreatorRepository::class );
		$logger           = $this->service( Logger::class );
		$link             = new ProductCreatorLink( $products, $creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) );
		$this->mappings   = new CreatorCategoryRepository( $this->wpdb );
		$this->reconciler = new CreatorCategoryReconciler( $products, $this->mappings, $creators, $link, $logger );
		$this->actions    = new CreatorProductsActions( $link, new RecordingLogger() );
		$this->section    = new CreatorProductsSection( $products, $creators, new CategoryLinkStatus( $products, $this->mappings, $creators, $link, $logger ) );
		$this->mappings->insert( $this->category, $this->alpha->id, 1 );
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
		remove_filter( 'map_meta_cap', array( self::class, 'product_caps' ), 10 );

		foreach ( $this->posts as $id ) {
			$this->execute( 'DELETE FROM %i WHERE post_id = %d', $this->wpdb->postmeta, $id );
			$this->execute( 'DELETE FROM %i WHERE object_id = %d', $this->wpdb->term_relationships, $id );
			$this->execute( 'DELETE FROM %i WHERE ID = %d', $this->wpdb->posts, $id );
		}

		wp_delete_term( $this->category, 'product_cat' );
		unregister_taxonomy( 'product_cat' );
		wp_set_current_user( 0 );
		wp_cache_flush();
		parent::tearDown();
	}

	public function test_the_section_shows_origins_and_writes_nothing(): void {
		$category = $this->product( 'category', true );
		$this->product( 'admin', true );
		$this->product( null, false );
		$before = $this->snapshot();

		$html = $this->render();

		self::assertStringContainsString( '<td>Product category<br><span class="description">Via: Origin Alpha</span></td>', $html );
		self::assertSame( 2, substr_count( $html, '<td>Manual</td>' ), 'admin, and a link without origin.' );
		self::assertSame( 1, substr_count( $html, 'value="' . CreatorProductsActions::KEEP_ACTION . '"' ) );
		self::assertStringContainsString( 'name="product_id" value="' . $category . '"', $html );
		self::assertSame( $before, $this->snapshot(), 'No meta, audit or mapping change.' );
	}

	public function test_keep_as_manual_through_the_guard_keeps_the_creator_and_audits_the_admin(): void {
		$product = $this->product( 'category', true );

		$url = $this->actions->keep_as_manual( $this->form( $product ) );

		self::assertStringContainsString( $product . ':kept_manual', $url );
		self::assertSame( array( (string) $this->alpha->id, 'admin' ), $this->link( $product ), 'Creator kept, only the origin changed.' );
		$audit = $this->product_audit();
		self::assertCount( 1, $audit );
		self::assertSame( array( 'product_creator_linked', '1', (string) $this->alpha->id ), array( $audit[0]['action'], $audit[0]['actor_user_id'], $audit[0]['creator_id'] ) );
		self::assertSame(
			array(
				'product_id'          => $product,
				'previous_creator_id' => $this->alpha->id,
				'source'              => 'admin',
				'previous_source'     => 'category',
			),
			$audit[0]['context']
		);

		self::assertStringContainsString( $product . ':unchanged', $this->actions->keep_as_manual( $this->form( $product ) ), 'Idempotent.' );
		self::assertCount( 1, $this->product_audit() );

		self::assertSame( ReconciliationOutcome::SkippedManual, $this->reconciler->reconcile_product( $product, CategoryReconciliationTrigger::CategoryChanged ), 'The reconciliation leaves a manual link alone.' );
		self::assertFalse( update_post_meta( $product, ProductCreatorLink::SOURCE_META_KEY, 'category' ), 'Generic writes stay blocked.' );
		self::assertFalse( update_post_meta( $product, ProductCreatorLink::META_KEY, '99' ) );
		self::assertSame( array( (string) $this->alpha->id, 'admin' ), $this->link( $product ) );
	}

	public function test_a_failed_audit_rolls_the_product_back_and_reports_no_success(): void {
		$product = $this->product( 'category', true );
		$this->wpdb->suppress_errors( true );
		$fail = static fn( string $query ): string => str_starts_with( $query, 'INSERT INTO `mbtest_merchboard_audit_log`' ) ? 'INSERT INTO mbtest_this_table_does_not_exist VALUES (1)' : $query;
		add_filter( 'query', $fail );

		try {
			$url = $this->actions->keep_as_manual( $this->form( $product ) );
		} finally {
			remove_filter( 'query', $fail );
		}

		self::assertStringContainsString( $product . ':error', $url );
		wp_cache_delete( $product, 'post_meta' );
		self::assertSame( array( (string) $this->alpha->id, 'category' ), $this->link( $product ) );
		self::assertSame( array(), $this->product_audit() );
	}

	public function test_without_confirmation_or_edit_product_nothing_changes(): void {
		$product = $this->product( 'category', true );
		$form    = $this->form( $product );
		unset( $form['confirm'] );

		self::assertStringContainsString( ':confirmation_required', $this->actions->keep_as_manual( $form ) );

		remove_filter( 'map_meta_cap', array( self::class, 'product_caps' ), 10 );
		self::assertStringContainsString( ':not_authorized', $this->actions->keep_as_manual( $this->form( $product ) ) );
		add_filter( 'map_meta_cap', array( self::class, 'product_caps' ), 10, 2 );

		self::assertSame( array( (string) $this->alpha->id, 'category' ), $this->link( $product ) );
		self::assertSame( array(), $this->product_audit() );
	}

	public function test_remove_is_refused_for_a_category_link_until_it_is_kept_as_manual(): void {
		$product = $this->product( 'category', true );
		$before  = $this->snapshot();

		self::assertStringContainsString( $product . ':category_managed', $this->actions->unlink( $this->unlink_form( $product ) ) );
		self::assertSame( $before, $this->snapshot(), 'No meta, audit or mapping change.' );
		self::assertFalse( delete_post_meta( $product, ProductCreatorLink::META_KEY ), 'The guard still blocks generic deletes.' );
		self::assertFalse( delete_post_meta( $product, ProductCreatorLink::SOURCE_META_KEY ) );
		self::assertSame( array( (string) $this->alpha->id, 'category' ), $this->link( $product ) );

		self::assertStringContainsString( $product . ':kept_manual', $this->actions->keep_as_manual( $this->form( $product ) ) );
		self::assertStringContainsString( $product . ':unlinked', $this->actions->unlink( $this->unlink_form( $product ) ) );
		self::assertSame( array( null, null ), $this->link( $product ) );
		self::assertSame( array( 'product_creator_linked', 'product_creator_unlinked' ), array_column( $this->product_audit(), 'action' ) );
	}

	public function test_a_manual_link_is_still_removed_as_before(): void {
		$admin  = $this->product( 'admin', true );
		$legacy = $this->product( null, false );

		self::assertStringContainsString( $admin . ':unlinked', $this->actions->unlink( $this->unlink_form( $admin ) ) );
		self::assertStringContainsString( $legacy . ':unlinked', $this->actions->unlink( $this->unlink_form( $legacy ) ) );
		self::assertSame( array( null, null ), $this->link( $admin ) );
		self::assertSame( array( null, null ), $this->link( $legacy ) );
	}

	public function test_the_reconciliation_still_removes_category_links(): void {
		$left   = $this->product( 'category', true );
		$mapped = $this->product( 'category', true );
		$manual = $this->product( 'admin', false );

		wp_set_object_terms( $left, array(), 'product_cat' );
		self::assertSame( ReconciliationOutcome::Unlinked, $this->reconciler->reconcile_product( $left, CategoryReconciliationTrigger::CategoryChanged ), 'The product left its category.' );

		$this->mappings->delete( $this->category, $this->alpha->id );
		self::assertSame( ReconciliationOutcome::Unlinked, $this->reconciler->reconcile_product( $mapped, CategoryReconciliationTrigger::MappingRemoved ), 'The mapping was removed.' );
		self::assertSame( ReconciliationOutcome::SkippedManual, $this->reconciler->reconcile_product( $manual, CategoryReconciliationTrigger::MappingRemoved ) );

		self::assertSame( array( null, null ), $this->link( $left ) );
		self::assertSame( array( null, null ), $this->link( $mapped ) );
		self::assertSame( array( (string) $this->alpha->id, 'admin' ), $this->link( $manual ) );
		self::assertSame( array( 'category_removed', 'mapping_removed' ), array_column( array_column( $this->product_audit(), 'context' ), 'reason' ) );
	}

	public function test_keep_as_manual_has_no_ajax_rest_or_logged_out_route(): void {
		$this->actions->register();

		self::assertSame( 10, has_action( 'admin_post_' . CreatorProductsActions::KEEP_ACTION, array( $this->actions, 'handle_keep_request' ) ) );
		self::assertFalse( has_action( 'admin_post_nopriv_' . CreatorProductsActions::KEEP_ACTION ) );
		self::assertFalse( has_action( 'wp_ajax_' . CreatorProductsActions::KEEP_ACTION ) );
		self::assertSame( array(), array_filter( array_keys( rest_get_server()->get_routes() ), static fn( string $route ): bool => str_starts_with( $route, '/merchboard' ) && ( str_contains( $route, 'keep' ) || str_contains( $route, 'categor' ) || str_contains( $route, 'product' ) ) ) );

		remove_action( 'admin_post_' . CreatorProductsActions::LINK_ACTION, array( $this->actions, 'handle_link_request' ) );
		remove_action( 'admin_post_' . CreatorProductsActions::UNLINK_ACTION, array( $this->actions, 'handle_unlink_request' ) );
		remove_action( 'admin_post_' . CreatorProductsActions::KEEP_ACTION, array( $this->actions, 'handle_keep_request' ) );
	}

	/**
	 * A confirmed "Keep as manual" request for Alpha with a real nonce.
	 *
	 * @param int $product_id Product id.
	 * @return array<string, string>
	 */
	private function form( int $product_id ): array {
		return array(
			'creator_id' => (string) $this->alpha->id,
			'product_id' => (string) $product_id,
			'confirm'    => '1',
			'_wpnonce'   => wp_create_nonce( CreatorProductsActions::keep_nonce_action( $this->alpha->id ) ),
		);
	}

	/**
	 * A Remove request for Alpha with a real nonce.
	 *
	 * @param int $product_id Product id.
	 * @return array<string, string>
	 */
	private function unlink_form( int $product_id ): array {
		return array(
			'creator_id' => (string) $this->alpha->id,
			'product_id' => (string) $product_id,
			'_wpnonce'   => wp_create_nonce( CreatorProductsActions::unlink_nonce_action( $this->alpha->id ) ),
		);
	}

	/**
	 * A product linked to Alpha, stored directly like an earlier write.
	 *
	 * @param string|null $source      Link origin (null: none).
	 * @param bool        $in_category Whether it is in Alpha's category.
	 */
	private function product( ?string $source, bool $in_category ): int {
		$id            = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Origin tee' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->posts[] = $id;

		if ( $in_category ) {
			wp_set_object_terms( $id, array( $this->category ), 'product_cat' );
		}

		$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, $id, ProductCreatorLink::META_KEY, (string) $this->alpha->id );

		if ( null !== $source ) {
			$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, $id, ProductCreatorLink::SOURCE_META_KEY, $source );
		}

		wp_cache_delete( $id, 'post_meta' );

		return $id;
	}

	/**
	 * Renders the section for Alpha.
	 */
	private function render(): string {
		ob_start();
		$this->section->render( $this->alpha );

		return (string) ob_get_clean();
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
	 * Product link audit rows, decoded.
	 *
	 * @return list<array<string, mixed>>
	 * @phpstan-impure
	 */
	private function product_audit(): array {
		$rows = array();

		foreach ( $this->rows( Tables::AUDIT_LOG ) as $row ) {
			if ( str_starts_with( (string) $row['action'], 'product_creator_' ) ) {
				$row['context'] = json_decode( (string) $row['context'], true );
				$rows[]         = $row;
			}
		}

		return $rows;
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
