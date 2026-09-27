<?php
/**
 * Category-based ownership reconciliation on a real database.
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
use MerchBoard\Modules\WooCommerce\CategoryReconciliationTrigger;
use MerchBoard\Modules\WooCommerce\CreatorCategoryReconciler;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ReconciliationOutcome;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;

/**
 * WooCommerce is not installed here: products are plain `product` posts, the
 * test registers `product_cat` and reads/writes through PostMetaProductStore
 * on the generic metadata API. The core guard, transactions, audit and
 * MariaDB are the booted plugin's real ones, so every write must pass the
 * guard via ProductCreatorLink's grant.
 */
final class CreatorCategoryReconciliationTest extends IntegrationTestCase {

	private PostMetaProductStore $products;

	private CreatorCategoryReconciler $reconciler;

	private int $alpha;

	private int $beta;

	private int $alpha_cat;

	private int $beta_cat;

	private int $child_cat;

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

		register_taxonomy( 'product_cat', 'product', array( 'hierarchical' => true ) );
		$this->alpha_cat = $this->term( 'Reconcile Alpha' );
		$this->beta_cat  = $this->term( 'Reconcile Beta' );
		$this->child_cat = $this->term( 'Reconcile Alpha Child', $this->alpha_cat );

		$creators    = $this->service( CreatorService::class );
		$this->alpha = $creators->create( 'Reconcile Alpha', CreatorStatus::Active, null )->id;
		$this->beta  = $creators->create( 'Reconcile Beta', CreatorStatus::Active, null )->id;

		$mappings = new CreatorCategoryRepository( $this->wpdb );
		$mappings->insert( $this->alpha_cat, $this->alpha, 1 );
		$mappings->insert( $this->beta_cat, $this->beta, 1 );

		$this->products   = new PostMetaProductStore();
		$repository       = $this->service( CreatorRepository::class );
		$this->reconciler = new CreatorCategoryReconciler(
			$this->products,
			$mappings,
			$repository,
			new ProductCreatorLink(
				$this->products,
				$repository,
				$this->service( AuditTrail::class ),
				$this->service( Transaction::class ),
				$this->service( ProtectedProductMeta::class )
			),
			$this->service( Logger::class )
		);
	}

	protected function tearDown(): void {
		foreach ( $this->posts as $id ) {
			$this->execute( 'DELETE FROM %i WHERE post_id = %d', $this->wpdb->postmeta, $id );
			$this->execute( 'DELETE FROM %i WHERE object_id = %d', $this->wpdb->term_relationships, $id );
			$this->execute( 'DELETE FROM %i WHERE ID = %d', $this->wpdb->posts, $id );
		}

		foreach ( array( $this->child_cat, $this->alpha_cat, $this->beta_cat ) as $term_id ) {
			wp_delete_term( $term_id, 'product_cat' );
		}

		unregister_taxonomy( 'product_cat' );
		wp_cache_flush();
		parent::tearDown();
	}

	public function test_a_category_run_links_moves_and_skips_through_the_real_guard(): void {
		$plain     = $this->product( array( $this->alpha_cat ) );
		$moved     = $this->product( array( $this->alpha_cat ), $this->beta, 'category' );
		$manual    = $this->product( array( $this->alpha_cat ), $this->beta, 'admin' );
		$ambiguous = $this->product( array( $this->alpha_cat, $this->beta_cat ), $this->alpha, 'category' );
		$child     = $this->product( array( $this->child_cat ) );
		$draft     = $this->product( array( $this->alpha_cat ), null, null, 'draft' );
		$trash     = $this->product( array( $this->alpha_cat ), null, null, 'trash' );
		$variation = $this->variation( $plain, array( $this->alpha_cat ) );

		$result = $this->reconciler->reconcile_category( $this->alpha_cat, CategoryReconciliationTrigger::MappingCreated, 1 );

		self::assertSame(
			array(
				'linked'           => 2,
				'moved'            => 1,
				'unlinked'         => 0,
				'unchanged'        => 0,
				'skipped_manual'   => 1,
				'ambiguous'        => 1,
				'skipped_inactive' => 0,
				'errors'           => 0,
			),
			$result->to_array()
		);

		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $plain ) );
		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $draft ) );
		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $moved ) );
		self::assertSame( array( (string) $this->beta, 'admin' ), $this->link( $manual ), 'Manual link untouched.' );
		self::assertSame( array( null, null ), $this->link( $ambiguous ), 'Ambiguous: category link removed.' );
		self::assertSame( array( null, null ), $this->link( $child ), 'Child categories do not count.' );
		self::assertSame( array( null, null ), $this->link( $trash ) );
		self::assertSame( array( null, null ), $this->link( $variation ) );

		$audit = $this->product_audit();
		self::assertSame( array( null ), array_values( array_unique( array_column( $audit, 'actor_user_id' ) ) ), 'System actor.' );
		self::assertSame(
			array( 'linked:mapping_created', 'unlinked:category_moved', 'linked:category_moved', 'unlinked:ambiguous', 'linked:mapping_created' ),
			array_map( static fn( array $row ): string => substr( (string) $row['action'], strlen( 'product_creator_' ) ) . ':' . $row['context']['reason'], $audit )
		);
		self::assertSame( 1, $audit[0]['context']['trigger_user_id'] );
		self::assertSame( 'category', $audit[0]['context']['source'] );

		$writes = $this->products->writes;
		$again  = $this->reconciler->reconcile_category( $this->alpha_cat, CategoryReconciliationTrigger::MappingCreated, 1 );
		self::assertSame( 0, $again->count( ReconciliationOutcome::Linked ) + $again->count( ReconciliationOutcome::Moved ) + $again->count( ReconciliationOutcome::Unlinked ), 'Idempotent.' );
		self::assertSame( $writes, $this->products->writes );
		self::assertCount( count( $audit ), $this->product_audit() );
	}

	public function test_a_product_that_left_its_category_loses_the_category_link(): void {
		$product = $this->product( array( $this->alpha_cat ) );
		$this->reconciler->reconcile_product( $product, CategoryReconciliationTrigger::CategoryChanged );
		wp_set_object_terms( $product, array(), 'product_cat' );

		self::assertSame( ReconciliationOutcome::Unlinked, $this->reconciler->reconcile_product( $product, CategoryReconciliationTrigger::CategoryChanged ) );
		self::assertSame( array( null, null ), $this->link( $product ) );
		self::assertSame( 'category_removed', $this->product_audit()[1]['context']['reason'] );
	}

	public function test_an_inactive_creator_gets_no_link_and_generic_writes_stay_blocked(): void {
		$this->service( CreatorService::class )->set_status( $this->alpha, CreatorStatus::Inactive );
		$product = $this->product( array( $this->alpha_cat ) );

		self::assertSame( ReconciliationOutcome::SkippedInactive, $this->reconciler->reconcile_product( $product, CategoryReconciliationTrigger::CategoryChanged ) );
		self::assertSame( array( null, null ), $this->link( $product ) );

		self::assertFalse( add_post_meta( $product, ProductCreatorLink::META_KEY, (string) $this->beta ), 'Outside a reconciliation the guard still denies.' );
		self::assertFalse( add_post_meta( $product, ProductCreatorLink::SOURCE_META_KEY, 'category' ) );
	}

	public function test_a_failed_audit_insert_rolls_the_product_back_and_counts_an_error(): void {
		$product = $this->product( array( $this->alpha_cat ) );
		$this->wpdb->suppress_errors( true );
		$fail = static fn( string $query ): string => str_starts_with( $query, 'INSERT INTO `mbtest_merchboard_audit_log`' ) ? 'INSERT INTO mbtest_this_table_does_not_exist VALUES (1)' : $query;
		add_filter( 'query', $fail );

		try {
			$result = $this->reconciler->reconcile_category( $this->alpha_cat, CategoryReconciliationTrigger::CategoryChanged );
		} finally {
			remove_filter( 'query', $fail );
		}

		self::assertSame( 1, $result->count( ReconciliationOutcome::Error ) );
		wp_cache_delete( $product, 'post_meta' );
		self::assertSame( array( null, null ), $this->link( $product ), 'The link was rolled back with the audit record.' );
		self::assertSame( array(), $this->product_audit() );
	}

	/**
	 * Creates a `product_cat` term.
	 *
	 * @param string $name   Name.
	 * @param int    $parent_id Parent term id.
	 */
	private function term( string $name, int $parent_id = 0 ): int {
		$args = array();

		if ( $parent_id > 0 ) {
			$args['parent'] = $parent_id;
		}

		$term = wp_insert_term( $name, 'product_cat', $args );
		self::assertIsArray( $term );

		return (int) $term['term_id'];
	}

	/**
	 * Creates a product with categories and optionally a stored link, written
	 * directly like an earlier ProductCreatorLink write.
	 *
	 * @phpstan-param list<int> $categories
	 *
	 * @param int[]       $categories Direct categories.
	 * @param int|null    $creator_id Linked creator.
	 * @param string|null $source     Link origin.
	 * @param string      $status     Post status.
	 */
	private function product( array $categories, ?int $creator_id = null, ?string $source = null, string $status = 'publish' ): int {
		$id            = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => $status, 'post_title' => 'Reconciled tee' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->posts[] = $id;
		wp_set_object_terms( $id, $categories, 'product_cat' );

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
	 * Creates a variation with categories.
	 *
	 * @phpstan-param list<int> $categories
	 *
	 * @param int   $parent_id     Parent product.
	 * @param int[] $categories Categories.
	 */
	private function variation( int $parent_id, array $categories ): int {
		$id            = (int) wp_insert_post( array( 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => $parent_id, 'post_title' => 'Reconciled tee - S' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->posts[] = $id;
		wp_set_object_terms( $id, $categories, 'product_cat' );

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
	 * Product link audit rows with decoded context, in order.
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
}
