<?php
/**
 * Migration 4 and the creator ↔ product category mapping on a real database.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\Migrations\CreateCreatorCategoriesTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorsTable;
use MerchBoard\Core\Database\Migrations\CreateAuditLogTable;
use MerchBoard\Core\Database\Migrations\CreateCreatorMetaTable;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\CreatorCategoryException;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;
use MerchBoard\Modules\WooCommerce\MappingRemovalChoice;
use MerchBoard\Tests\Integration\IntegrationTestCase;

/**
 * WooCommerce is not installed here, so the test registers the `product_cat`
 * and `product_tag` taxonomies itself and builds the service from the booted
 * plugin's core services.
 */
final class CreatorCategoryMappingTest extends IntegrationTestCase {

	private CreatorCategoryService $mappings;

	private int $alpha;

	private int $beta;

	private int $alpha_cat;

	private int $general_cat;

	private int $tag;

	private int $product = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		register_taxonomy( 'product_cat', 'product' );
		register_taxonomy( 'product_tag', 'product' );
		$this->alpha_cat   = $this->term( 'Mapping Alpha', 'product_cat' );
		$this->general_cat = $this->term( 'Mapping General', 'product_cat' );
		$this->tag         = $this->term( 'Mapping Tag', 'product_tag' );

		$creators    = $this->service( CreatorService::class );
		$this->alpha = $creators->create( 'Mapping Alpha', CreatorStatus::Active, null )->id;
		$this->beta  = $creators->create( 'Mapping Beta', CreatorStatus::Active, null )->id;

		$this->mappings = new CreatorCategoryService(
			new CreatorCategoryRepository( $this->wpdb ),
			$this->service( CreatorRepository::class ),
			$this->service( AuditTrail::class ),
			$this->service( Transaction::class )
		);
	}

	protected function tearDown(): void {
		foreach ( array(
			$this->alpha_cat   => 'product_cat',
			$this->general_cat => 'product_cat',
			$this->tag         => 'product_tag',
		) as $term_id => $taxonomy ) {
			wp_delete_term( $term_id, $taxonomy );
		}

		if ( $this->product > 0 ) {
			$this->execute( 'DELETE FROM %i WHERE post_id = %d', $this->wpdb->postmeta, $this->product );
			$this->execute( 'DELETE FROM %i WHERE ID = %d', $this->wpdb->posts, $this->product );
		}

		unregister_taxonomy( 'product_cat' );
		unregister_taxonomy( 'product_tag' );
		parent::tearDown();
	}

	public function test_migration_4_creates_the_table_like_the_other_plugin_tables(): void {
		$table   = $this->table( Tables::CREATOR_CATEGORIES );
		$columns = $this->wpdb->get_results( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );

		self::assertSame(
			array(
				array( 'term_id', 'bigint unsigned', 'NO', 'PRI' ),
				array( 'creator_id', 'bigint unsigned', 'NO', 'MUL' ),
				array( 'created_at', 'datetime', 'NO', '' ),
				array( 'created_by', 'bigint unsigned', 'YES', '' ),
			),
			// MySQL 8.0.19+ reports no integer display width, MariaDB does (see SchemaTest).
			array_map( static fn( array $c ): array => array( $c['Field'], (string) preg_replace( '/^bigint\(\d+\)/', 'bigint', $c['Type'] ), $c['Null'], $c['Key'] ), is_array( $columns ) ? $columns : array() )
		);

		$status = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)', $table, $this->table( Tables::CREATORS ) ), OBJECT_K );
		self::assertSame( 'InnoDB', $status[ $table ]->ENGINE ?? null );
		self::assertSame( $status[ $this->table( Tables::CREATORS ) ]->TABLE_COLLATION ?? 'x', $status[ $table ]->TABLE_COLLATION ?? 'y', 'Same collation as the other plugin tables.' );
		self::assertContains( Tables::CREATOR_CATEGORIES, Tables::all(), 'Covered by the engine check and opt-in uninstall.' );
	}

	public function test_an_existing_version_3_database_is_upgraded_with_its_data_kept(): void {
		$this->drop_plugin_schema();
		( new Migrator(
			$this->wpdb,
			array(
				1 => new CreateCreatorsTable(),
				2 => new CreateAuditLogTable(),
				3 => new CreateCreatorMetaTable(),
			)
		) )->migrate();
		$creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Before 4', CreatorStatus::Active );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table( Tables::CREATOR_CATEGORIES ) ) ) );

		$this->install();

		self::assertSame( '18', (string) get_option( Migrator::VERSION_OPTION ), 'Upgraded to the latest version.' );
		self::assertSame( $this->table( Tables::CREATOR_CATEGORIES ), $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table( Tables::CREATOR_CATEGORIES ) ) ) );
		self::assertSame( 'Before 4', ( new CreatorRepository( $this->wpdb ) )->find( $creator->id )?->display_name );
		( new CreateCreatorCategoriesTable() )->up( $this->wpdb );
		self::assertSame( 0, $this->count_rows( Tables::CREATOR_CATEGORIES ), 'Re-running the migration is idempotent.' );
	}

	public function test_mapping_lifecycle_with_audit_on_a_real_database(): void {
		self::assertTrue( $this->mappings->link( $this->alpha, $this->alpha_cat ) );
		self::assertFalse( $this->mappings->link( $this->alpha, $this->alpha_cat ), 'Idempotent.' );

		$row = $this->mapping_rows()[0];
		self::assertSame( array( (string) $this->alpha_cat, (string) $this->alpha, '1' ), array( $row['term_id'], $row['creator_id'], $row['created_by'] ) );

		self::assertTrue( $this->mappings->unlink( $this->alpha, $this->alpha_cat, MappingRemovalChoice::RemoveLinks ) );
		self::assertFalse( $this->mappings->unlink( $this->alpha, $this->alpha_cat, MappingRemovalChoice::RemoveLinks ), 'Idempotent.' );
		self::assertSame( array(), $this->mapping_rows() );

		self::assertSame( array( 'creator_category_linked', 'creator_category_unlinked' ), $this->category_audit_actions() );
	}

	public function test_real_terms_are_validated(): void {
		$this->expect_reason( CreatorCategoryException::NOT_PRODUCT_CATEGORY, fn() => $this->mappings->link( $this->alpha, $this->tag ) );
		$this->expect_reason( CreatorCategoryException::TERM_NOT_FOUND, fn() => $this->mappings->link( $this->alpha, $this->general_cat + 100000 ) );

		update_option( 'default_product_cat', $this->general_cat );
		$this->expect_reason( CreatorCategoryException::DEFAULT_CATEGORY, fn() => $this->mappings->link( $this->alpha, $this->general_cat ) );
		delete_option( 'default_product_cat' );

		self::assertSame( array(), $this->mapping_rows() );
		self::assertSame( array(), $this->category_audit_actions() );
	}

	public function test_the_primary_key_allows_one_creator_per_category(): void {
		$repository = new CreatorCategoryRepository( $this->wpdb );
		$repository->insert( $this->alpha_cat, $this->alpha, null );
		$this->wpdb->suppress_errors( true );

		try {
			$repository->insert( $this->alpha_cat, $this->beta, null );
			self::fail( 'The database must refuse a second creator for a category.' );
		} catch ( DatabaseException ) {
			self::assertSame( array( (string) $this->alpha ), array_column( $this->mapping_rows(), 'creator_id' ) );
		}

		$this->expect_reason( CreatorCategoryException::CATEGORY_MAPPED_ELSEWHERE, fn() => $this->mappings->link( $this->beta, $this->alpha_cat ) );
	}

	public function test_a_failed_audit_insert_rolls_the_mapping_back(): void {
		$this->wpdb->suppress_errors( true );
		$fail = static fn( string $query ): string => str_starts_with( $query, 'INSERT INTO `mbtest_merchboard_audit_log`' ) ? 'INSERT INTO mbtest_this_table_does_not_exist VALUES (1)' : $query;
		add_filter( 'query', $fail );

		try {
			$this->mappings->link( $this->alpha, $this->alpha_cat );
			self::fail( 'Expected a database failure.' );
		} catch ( DatabaseException ) {
			self::assertSame( array(), $this->mapping_rows(), 'The mapping was rolled back with the audit record.' );
		} finally {
			remove_filter( 'query', $fail );
		}

		self::assertSame( array(), $this->category_audit_actions() );

		$this->mappings->link( $this->alpha, $this->alpha_cat );
		add_filter( 'query', $fail );

		try {
			$this->mappings->unlink( $this->alpha, $this->alpha_cat, MappingRemovalChoice::RemoveLinks );
			self::fail( 'Expected a database failure.' );
		} catch ( DatabaseException ) {
			self::assertCount( 1, $this->mapping_rows(), 'The removal was rolled back with the audit record.' );
		} finally {
			remove_filter( 'query', $fail );
		}

		self::assertSame( array( 'creator_category_linked' ), $this->category_audit_actions() );
	}

	public function test_removing_a_mapping_changes_no_product_ownership(): void {
		$this->product = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Mapped tee' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		wp_set_object_terms( $this->product, array( $this->alpha_cat ), 'product_cat' );
		$this->execute( 'INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)', $this->wpdb->postmeta, $this->product, ProtectedProductMeta::LINK_KEY, (string) $this->alpha );
		$meta = $this->product_meta();

		$this->mappings->link( $this->alpha, $this->alpha_cat );
		self::assertSame( $meta, $this->product_meta(), 'Mapping adds no link.' );

		$this->mappings->unlink( $this->alpha, $this->alpha_cat, MappingRemovalChoice::RemoveLinks );
		self::assertSame( $meta, $this->product_meta(), 'Unmapping removes no link.' );

		$this->mappings->link( $this->beta, $this->alpha_cat );
		self::assertSame( $meta, $this->product_meta(), 'Mapping to another creator moves no link.' );
	}

	public function test_deactivation_keeps_the_mapping_and_reactivation_changes_nothing(): void {
		$creators = $this->service( CreatorService::class );
		$this->mappings->link( $this->alpha, $this->alpha_cat );
		$expected = array(
			array(
				'term_id'    => (string) $this->alpha_cat,
				'creator_id' => (string) $this->alpha,
				'created_by' => '1',
			),
		);

		$creators->set_status( $this->alpha, CreatorStatus::Inactive );
		self::assertSame( $expected, $this->mapping_rows(), 'Kept while inactive.' );

		$creators->set_status( $this->alpha, CreatorStatus::Active );
		self::assertSame( $expected, $this->mapping_rows(), 'Unchanged after reactivation.' );
		self::assertSame( array( 'creator_category_linked' ), $this->category_audit_actions() );
	}

	/**
	 * Creates a term.
	 *
	 * @param string $name     Name.
	 * @param string $taxonomy Taxonomy.
	 */
	private function term( string $name, string $taxonomy ): int {
		$term = wp_insert_term( $name, $taxonomy );
		self::assertIsArray( $term );

		return (int) $term['term_id'];
	}

	/**
	 * Mapping rows straight from the database.
	 *
	 * @return list<array<string, mixed>>
	 * @phpstan-impure
	 */
	private function mapping_rows(): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT term_id, creator_id, created_by FROM %i ORDER BY term_id', $this->table( Tables::CREATOR_CATEGORIES ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Category mapping audit actions, in order.
	 *
	 * @return list<string>
	 * @phpstan-impure
	 */
	private function category_audit_actions(): array {
		return array_values( array_filter( array_map( 'strval', array_column( $this->rows( Tables::AUDIT_LOG ), 'action' ) ), static fn( string $a ): bool => str_starts_with( $a, 'creator_category' ) ) );
	}

	/**
	 * The test product's meta rows.
	 *
	 * @return list<array<string, mixed>>
	 * @phpstan-impure
	 */
	private function product_meta(): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT meta_id, meta_key, meta_value FROM %i WHERE post_id = %d ORDER BY meta_id', $this->wpdb->postmeta, $this->product ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
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
