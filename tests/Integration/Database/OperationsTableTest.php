<?php
/**
 * Schema version 13: the operation registry (E3 T4), on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Database;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migrations\CreateOperationsTable;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\Schema;
use MerchBoard\Core\Database\SchemaEraser;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Lifecycle\RestoredHistory;
use MerchBoard\Core\Lifecycle\Uninstaller;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Settings\SettingsService;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;

/**
 * Migration 13 creates `merchboard_operations` (InnoDB, PK operation_id);
 * running it again changes no row; a failing CREATE TABLE keeps the schema
 * version at 12; the opt-in uninstall keeps the table and its rows and the
 * reinstall finds them unchanged (B-2). Synthetic data only.
 */
final class OperationsTableTest extends IntegrationTestCase {

	private const ROW = array(
		'operation_id'  => '0f8fad5b-d9cb-469f-a165-70867728950e',
		'kind'          => 'g1_decision',
		'subject_id'    => '42',
		'payload_hash'  => 'a3f5c0d2e1b4968700112233445566778899aabbccddeeff0011223344556677',
		'actor_user_id' => '1',
		'created_at'    => '2026-12-02 10:00:00',
	);

	protected function tearDown(): void {
		delete_option( RestoredHistory::OPTION );
		delete_option( Settings::OPTION );
		parent::tearDown();
	}

	public function test_a_fresh_install_creates_the_registry(): void {
		$this->install();

		self::assertSame( '33', (string) get_option( Migrator::VERSION_OPTION ) );
		self::assertSame( 'InnoDB', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table( Tables::OPERATIONS ) ) ) );
		self::assertSame(
			array( 'operation_id' ),
			$this->wpdb->get_col( $this->wpdb->prepare( "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = 'PRIMARY'", $this->table( Tables::OPERATIONS ) ) )
		);
		self::assertSame(
			array( 'operation_id', 'kind', 'subject_id', 'payload_hash', 'actor_user_id', 'created_at' ),
			$this->wpdb->get_col( $this->wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION', $this->table( Tables::OPERATIONS ) ) )
		);
		self::assertSame( 0, $this->count_rows( Tables::OPERATIONS ) );
	}

	public function test_running_the_migration_again_changes_no_row(): void {
		$this->install();
		self::assertSame( 1, $this->wpdb->insert( $this->table( Tables::OPERATIONS ), self::ROW ) );

		( new CreateOperationsTable() )->up( $this->wpdb );
		( new CreateOperationsTable() )->up( $this->wpdb );

		self::assertSame( array( self::ROW ), $this->registry() );
	}

	public function test_a_failing_table_keeps_the_schema_version_at_12(): void {
		$migrations = Schema::migrations();
		unset( $migrations[13], $migrations[14], $migrations[15], $migrations[16], $migrations[17], $migrations[18], $migrations[19], $migrations[20], $migrations[21], $migrations[22], $migrations[23], $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29], $migrations[30], $migrations[31], $migrations[32], $migrations[33] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		self::assertSame( '12', (string) get_option( Migrator::VERSION_OPTION ) );

		$table  = $this->table( Tables::OPERATIONS );
		$filter = static function ( string $sql ) use ( $table ): string {
			// Two primary keys (1068): the table is not created.
			return str_starts_with( $sql, "CREATE TABLE {$table} (" ) ? "CREATE TABLE {$table} (operation_id char(36) NOT NULL, PRIMARY KEY (operation_id), PRIMARY KEY (operation_id))" : $sql;
		};
		add_filter( 'query', $filter );
		$this->wpdb->suppress_errors( true );

		try {
			( new Migrator( $this->wpdb, Schema::migrations() ) )->migrate();
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not create the operation registry.', $e->getPrevious()?->getMessage() );
		} finally {
			remove_filter( 'query', $filter );
		}

		wp_cache_delete( Migrator::VERSION_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		self::assertSame( '12', (string) get_option( Migrator::VERSION_OPTION ), 'The version is stored only after up() returned.' );
	}

	public function test_the_opt_in_uninstall_keeps_the_registry_and_the_reinstall_finds_it_unchanged(): void {
		$this->install();
		self::assertSame( 1, $this->wpdb->insert( $this->table( Tables::OPERATIONS ), self::ROW ) );

		self::assertTrue( $this->service( SettingsService::class )->set_delete_data_on_uninstall( true, 'DELETE' ) );
		self::assertTrue( ( new Uninstaller( $this->wpdb, new SchemaEraser( $this->wpdb, new RecordingLogger() ), new RecordingLogger() ) )->run() );

		self::assertNull( get_option( Migrator::VERSION_OPTION, null ) );
		self::assertSame( array( self::ROW ), $this->registry(), 'Kept by the uninstall (B-2).' );

		$this->install();

		self::assertSame( '33', (string) get_option( Migrator::VERSION_OPTION ) );
		self::assertSame( array( self::ROW ), $this->registry(), 'Migration 13 changed no kept row.' );
	}

	/**
	 * The registry's rows as MySQL returns them.
	 *
	 * @return list<array<string, string|null>>
	 */
	private function registry(): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i ORDER BY operation_id', $this->table( Tables::OPERATIONS ) ), ARRAY_A );

		return array_map(
			static fn( array $row ): array => array_map( static fn( mixed $value ): ?string => null === $value ? null : (string) $value, $row ),
			is_array( $rows ) ? $rows : array()
		);
	}
}
