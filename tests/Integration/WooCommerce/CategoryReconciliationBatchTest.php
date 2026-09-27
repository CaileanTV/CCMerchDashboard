<?php
/**
 * Full category reconciliation in batches on a real database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CategoryReconciliationBatch;
use MerchBoard\Modules\WooCommerce\CreatorCategoryReconciler;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ReconciliationOutcome;
use MerchBoard\Modules\WooCommerce\ReconciliationCandidateRepository;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;

/**
 * The real candidate query (ReconciliationCandidateRepository) on MariaDB, with
 * the booted plugin's guard, transactions and audit. WooCommerce is not
 * installed: products are `product` posts, `product_cat` is registered here
 * and PostMetaProductStore reads and writes through WordPress' APIs.
 */
final class CategoryReconciliationBatchTest extends IntegrationTestCase {

	private CategoryReconciliationBatch $batch;

	private CreatorCategoryRepository $mappings;

	private int $alpha;

	private int $beta;

	private int $gamma;

	private int $alpha_cat;

	private int $beta_cat;

	private int $gamma_cat;

	private int $child_cat;

	private int $general_cat;

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

		register_taxonomy( 'product_cat', array( 'product', 'product_variation' ), array( 'hierarchical' => true ) );
		$this->alpha_cat   = $this->term( 'Batch Alpha' );
		$this->beta_cat    = $this->term( 'Batch Beta' );
		$this->gamma_cat   = $this->term( 'Batch Gamma' );
		$this->general_cat = $this->term( 'Batch General' );
		$this->child_cat   = $this->term( 'Batch Alpha Child', $this->alpha_cat );

		$creators    = $this->service( CreatorService::class );
		$this->alpha = $creators->create( 'Batch Alpha', CreatorStatus::Active, null )->id;
		$this->beta  = $creators->create( 'Batch Beta', CreatorStatus::Active, null )->id;
		$this->gamma = $creators->create( 'Batch Gamma', CreatorStatus::Inactive, null )->id;

		$this->mappings = new CreatorCategoryRepository( $this->wpdb );
		$this->mappings->insert( $this->alpha_cat, $this->alpha, 1 );
		$this->mappings->insert( $this->beta_cat, $this->beta, 1 );
		$this->mappings->insert( $this->gamma_cat, $this->gamma, 1 );

		$products    = new PostMetaProductStore();
		$repository  = $this->service( CreatorRepository::class );
		$audit       = $this->service( AuditTrail::class );
		$logger      = $this->service( Logger::class );
		$this->batch = new CategoryReconciliationBatch(
			new ReconciliationCandidateRepository( $this->wpdb ),
			new CreatorCategoryReconciler(
				$products,
				$this->mappings,
				$repository,
				new ProductCreatorLink( $products, $repository, $audit, $this->service( Transaction::class ), $this->service( ProtectedProductMeta::class ) ),
				$logger
			),
			$audit,
			$logger
		);
	}

	protected function tearDown(): void {
		foreach ( $this->posts as $id ) {
			$this->execute( 'DELETE FROM %i WHERE post_id = %d', $this->wpdb->postmeta, $id );
			$this->execute( 'DELETE FROM %i WHERE object_id = %d', $this->wpdb->term_relationships, $id );
			$this->execute( 'DELETE FROM %i WHERE ID = %d', $this->wpdb->posts, $id );
		}

		foreach ( array( $this->child_cat, $this->alpha_cat, $this->beta_cat, $this->gamma_cat, $this->general_cat ) as $term_id ) {
			wp_delete_term( $term_id, 'product_cat' );
		}

		unregister_taxonomy( 'product_cat' );
		wp_cache_flush();
		parent::tearDown();
	}

	public function test_a_full_run_reconciles_every_relevant_product_and_records_completion(): void {
		$ids = $this->seed();

		$report = $this->batch->execute( trigger_user_id: 1 );

		self::assertSame(
			array(
				'linked'           => 1,
				'moved'            => 1,
				'unlinked'         => 2,
				'unchanged'        => 1,
				'skipped_manual'   => 2,
				'ambiguous'        => 2,
				'skipped_inactive' => 1,
				'errors'           => 0,
			),
			$report->result->to_array()
		);

		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $ids['linked'] ) );
		self::assertSame( array( (string) $this->beta, 'category' ), $this->link( $ids['moved'] ) );
		self::assertSame( array( null, null ), $this->link( $ids['left'] ), 'Found through its category link.' );
		self::assertSame( array( null, null ), $this->link( $ids['unmapped'] ), 'Its mapping is gone.' );
		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $ids['unchanged'] ) );
		self::assertSame( array( (string) $this->beta, 'admin' ), $this->link( $ids['manual'] ) );
		self::assertSame( array( (string) $this->beta, null ), $this->link( $ids['legacy'] ) );
		self::assertSame( array( null, null ), $this->link( $ids['ambiguous_link'] ) );
		foreach ( array( 'ambiguous', 'inactive', 'trash', 'auto_draft', 'variation', 'child', 'general' ) as $key ) {
			self::assertSame( array( null, null ), $this->link( $ids[ $key ] ), $key );
		}

		$audit = $this->audit();
		self::assertSame( array( $report->run_id->value ), array_values( array_unique( array_column( array_column( $audit, 'context' ), 'run_id' ) ) ) );
		self::assertSame( array( null ), array_values( array_unique( array_column( $audit, 'actor_user_id' ) ) ), 'System actor throughout.' );

		$completed = end( $audit );
		self::assertIsArray( $completed );
		self::assertSame( 'category_sync_completed', $completed['action'] );
		self::assertNull( $completed['creator_id'] );
		self::assertSame(
			array(
				'run_id'           => $report->run_id->value,
				'dry_run'          => false,
				'trigger'          => 'full_sync',
				'trigger_user_id'  => 1,
				'scope'            => 'all',
				'linked'           => 1,
				'moved'            => 1,
				'unlinked'         => 2,
				'unchanged'        => 1,
				'skipped_manual'   => 2,
				'ambiguous'        => 2,
				'skipped_inactive' => 1,
				'errors'           => 0,
				'total'            => 10,
			),
			$completed['context']
		);
	}

	public function test_a_dry_run_changes_nothing_and_matches_the_real_run(): void {
		$this->seed();
		$before = $this->snapshot();

		$preview = $this->batch->preview();

		self::assertTrue( $preview->dry_run );
		self::assertSame( $before, $this->snapshot(), 'No post, meta, term or plugin table change; no audit row.' );
		self::assertSame( array(), $this->audit() );
		self::assertCount( 5, $preview->changes );

		$real = $this->batch->execute();
		self::assertSame( $preview->result->to_array(), $real->result->to_array() );
		self::assertNotSame( $preview->run_id->value, $real->run_id->value );
	}

	public function test_250_products_in_pages_once_each_then_idempotent_with_product_data_kept(): void {
		for ( $i = 0; $i < 250; $i++ ) {
			$this->product( array( $this->alpha_cat ) );
		}
		$posts    = $this->post_rows();
		$mappings = $this->table_rows( Tables::CREATOR_CATEGORIES );
		$creators = $this->table_rows( Tables::CREATORS );

		$first = $this->batch->execute();

		self::assertSame( 250, $first->result->count( ReconciliationOutcome::Linked ) );
		self::assertSame( 3, $first->pages );
		$linked = array_map( static fn( array $row ): int => (int) $row['context']['product_id'], array_filter( $this->audit(), static fn( array $row ): bool => 'product_creator_linked' === $row['action'] ) );
		self::assertSame( $this->posts, array_values( $linked ), 'Every product exactly once, ascending.' );
		self::assertSame( $posts, $this->post_rows(), 'No other product data changed.' );

		$audit  = count( $this->audit() );
		$second = $this->batch->execute();

		self::assertSame( 250, $second->result->count( ReconciliationOutcome::Unchanged ) );
		self::assertCount( $audit + 1, $this->audit(), 'Only the new completed record.' );
		self::assertSame( $mappings, $this->table_rows( Tables::CREATOR_CATEGORIES ) );
		self::assertSame( $creators, $this->table_rows( Tables::CREATORS ) );
	}

	public function test_a_run_that_cannot_read_a_page_records_no_completion(): void {
		for ( $i = 0; $i < 150; $i++ ) {
			$this->product( array( $this->alpha_cat ) );
		}
		$this->wpdb->suppress_errors( true );
		$calls = 0;
		$fail  = static function ( string $query ) use ( &$calls ): string {
			if ( str_starts_with( ltrim( $query ), 'SELECT p.ID FROM' ) && 2 === ++$calls ) {
				return 'SELECT ID FROM mbtest_this_table_does_not_exist';
			}

			return $query;
		};
		add_filter( 'query', $fail );

		try {
			$this->batch->execute();
			self::fail( 'Expected the run to stop.' );
		} catch ( DatabaseException ) {
			self::assertNotContains( 'category_sync_completed', array_column( $this->audit(), 'action' ) );
		} finally {
			remove_filter( 'query', $fail );
		}

		self::assertCount( 100, $this->audit(), 'The first page kept its per-product results.' );
		self::assertSame( 50, $this->batch->execute()->result->count( ReconciliationOutcome::Linked ), 'A new run picks up the rest.' );
	}

	public function test_a_failed_audit_insert_rolls_back_only_that_product(): void {
		$first  = $this->product( array( $this->alpha_cat ) );
		$second = $this->product( array( $this->alpha_cat ) );
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
			$report = $this->batch->execute();
		} finally {
			remove_filter( 'query', $fail );
		}

		self::assertSame( 1, $report->result->count( ReconciliationOutcome::Error ) );
		wp_cache_delete( $first, 'post_meta' );
		self::assertSame( array( null, null ), $this->link( $first ), 'Rolled back with its audit record.' );
		self::assertSame( array( (string) $this->alpha, 'category' ), $this->link( $second ) );
		self::assertSame( array( 'product_creator_linked', 'category_sync_completed' ), array_column( $this->audit(), 'action' ) );
	}

	/**
	 * One product per case.
	 *
	 * @return array<string, int>
	 */
	private function seed(): array {
		$unmapped_cat = $this->general_cat;

		return array(
			'linked'         => $this->product( array( $this->alpha_cat ) ),
			'moved'          => $this->product( array( $this->beta_cat ), $this->alpha, 'category' ),
			'left'           => $this->product( array( $this->general_cat ), $this->alpha, 'category' ),
			'unmapped'       => $this->product( array( $unmapped_cat ), $this->beta, 'category' ),
			'unchanged'      => $this->product( array( $this->alpha_cat ), $this->alpha, 'category' ),
			'manual'         => $this->product( array( $this->alpha_cat ), $this->beta, 'admin' ),
			'legacy'         => $this->product( array( $this->alpha_cat ), $this->beta ),
			'ambiguous'      => $this->product( array( $this->alpha_cat, $this->beta_cat ) ),
			'ambiguous_link' => $this->product( array( $this->alpha_cat, $this->beta_cat ), $this->alpha, 'category' ),
			'inactive'       => $this->product( array( $this->gamma_cat ) ),
			'trash'          => $this->product( array( $this->alpha_cat ), null, null, 'trash' ),
			'auto_draft'     => $this->product( array( $this->alpha_cat ), null, null, 'auto-draft' ),
			'variation'      => $this->product( array( $this->alpha_cat ), null, null, 'publish', 'product_variation' ),
			'child'          => $this->product( array( $this->child_cat ) ),
			'general'        => $this->product( array( $this->general_cat ) ),
		);
	}

	/**
	 * Creates a `product_cat` term.
	 *
	 * @param string $name      Name.
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
	 * Creates a product (or variation) with categories and optionally a link
	 * stored directly, like an earlier ProductCreatorLink write.
	 *
	 * @phpstan-param list<int> $categories
	 *
	 * @param int[]       $categories Direct categories.
	 * @param int|null    $creator_id Linked creator.
	 * @param string|null $source     Link origin.
	 * @param string      $status     Post status.
	 * @param string      $type       Post type.
	 */
	private function product( array $categories, ?int $creator_id = null, ?string $source = null, string $status = 'publish', string $type = 'product' ): int {
		$id            = (int) wp_insert_post( array( 'post_type' => $type, 'post_status' => $status, 'post_title' => 'Batch tee' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
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
	 * Reconciliation audit rows (product links and completed runs; not the
	 * setup's creator records) with decoded context, in order.
	 *
	 * @return list<array<string, mixed>>
	 * @phpstan-impure
	 */
	private function audit(): array {
		$rows = array();

		foreach ( $this->rows( Tables::AUDIT_LOG ) as $row ) {
			if ( str_starts_with( (string) $row['action'], 'product_creator_' ) || 'category_sync_completed' === $row['action'] ) {
				$row['context'] = json_decode( (string) $row['context'], true );
				$rows[]         = $row;
			}
		}

		return $rows;
	}

	/**
	 * Every row of a plugin table (any primary key).
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
	 * The test products' post rows.
	 *
	 * @return list<array<string, mixed>>
	 * @phpstan-impure
	 */
	private function post_rows(): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i WHERE post_type IN (%s, %s) ORDER BY ID', $this->wpdb->posts, 'product', 'product_variation' ), ARRAY_A );

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
