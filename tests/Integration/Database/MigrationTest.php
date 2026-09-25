<?php
/**
 * Migrations, version option and migration lock on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Database;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migration;
use MerchBoard\Core\Database\Migrations\CreateCreatorsTable;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Core\Database\Schema;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Lifecycle\Installer;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\RoleManager;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use wpdb;

final class MigrationTest extends IntegrationTestCase {

	public function test_fresh_install_creates_schema_version_3(): void {
		self::assertSame( 0, $this->migrator()->installed_version() );

		$this->install();

		self::assertSame( 3, $this->migrator()->installed_version() );
		self::assertSame( '3', (string) $this->raw_option( Migrator::VERSION_OPTION ) );
		self::assertSame( 0, $this->count_rows( Tables::CREATORS ) );
		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
		self::assertSame( 0, $this->count_rows( Tables::CREATOR_META ) );
		self::assertNull( $this->raw_option( Migrator::LOCK_OPTION ), 'Lock released.' );
		self::assertFalse( get_transient( Installer::FAILURE_TRANSIENT ) );
	}

	public function test_upgrade_from_1_adds_the_later_tables_and_keeps_creator_data(): void {
		( new Migrator( $this->wpdb, array( 1 => new CreateCreatorsTable() ) ) )->migrate();
		$creator = ( new CreatorRepository( $this->wpdb ) )->create( 'Før opgradering 🎸', CreatorStatus::Active );
		$before  = $this->rows( Tables::CREATORS );

		self::assertSame( 1, $this->migrator()->installed_version() );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table( Tables::AUDIT_LOG ) ) ) );

		$this->install();

		self::assertSame( 3, $this->migrator()->installed_version() );
		self::assertSame( $before, $this->rows( Tables::CREATORS ) );
		self::assertSame( 'Før opgradering 🎸', ( new CreatorRepository( $this->wpdb ) )->find( $creator->id )?->display_name );
		self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
	}

	public function test_current_schema_runs_no_queries(): void {
		$this->install();
		$migrator = $this->migrator();
		$migrator->installed_version(); // Warm the option cache like a normal request.
		$before = $this->wpdb->num_queries;

		self::assertTrue( $migrator->migrate() );
		self::assertSame( $before, $this->wpdb->num_queries );
	}

	public function test_rerunning_all_migrations_is_idempotent_and_keeps_data(): void {
		$this->install();
		( new CreatorRepository( $this->wpdb ) )->create( 'Stays', CreatorStatus::Inactive );
		$before = $this->rows( Tables::CREATORS );

		// As if the version option was lost after a successful schema change.
		delete_option( Migrator::VERSION_OPTION );
		$this->install();
		$this->install();

		self::assertSame( 3, $this->migrator()->installed_version() );
		self::assertSame( $before, $this->rows( Tables::CREATORS ) );
	}

	public function test_failed_migration_is_not_recorded_and_releases_the_lock(): void {
		$migrator = new Migrator(
			$this->wpdb,
			array(
				1 => new CreateCreatorsTable(),
				2 => new class() implements Migration {
					/**
					 * Fails with a real database error.
					 *
					 * @param wpdb $wpdb Database.
					 */
					public function up( wpdb $wpdb ): void {
						$wpdb->suppress_errors( true );
						$wpdb->query( 'ALTER TABLE merchboard_table_that_does_not_exist ADD COLUMN x INT' );
						$wpdb->suppress_errors( false );
						throw new DatabaseException( 'Could not alter table.' );
					}
				},
			)
		);

		try {
			$migrator->migrate();
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 2, $e->context()['schema_version'] );
			self::assertSame( 1, $e->context()['installed_version'] );
			self::assertStringContainsString( "doesn't exist", (string) $e->context()['db_error'], 'Real server error for the log.' );
		}

		self::assertSame( 1, $migrator->installed_version(), 'Only the successful migration is recorded.' );
		self::assertNull( $this->raw_option( Migrator::LOCK_OPTION ), 'Lock released after the failure.' );
	}

	public function test_installer_pauses_and_logs_a_failed_migration(): void {
		$logger    = $this->service( Logger::class );
		$installer = new Installer(
			new Migrator(
				$this->wpdb,
				array(
					1 => new class() implements Migration {
						/**
						 * Fails.
						 *
						 * @param wpdb $wpdb Database.
						 */
						public function up( wpdb $wpdb ): void {
							throw new DatabaseException( 'Could not create table.' );
						}
					},
				)
			),
			$this->service( RoleManager::class ),
			$logger
		);
		file_put_contents( MERCHBOARD_TEST_LOG, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test log.

		$installer->install();

		self::assertNotFalse( get_transient( Installer::FAILURE_TRANSIENT ) );
		self::assertSame( 0, ( new Migrator( $this->wpdb, Schema::migrations() ) )->installed_version() );
		self::assertStringContainsString( 'ERROR: Database migration failed.', (string) file_get_contents( MERCHBOARD_TEST_LOG ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test log.
	}

	public function test_lock_held_by_another_request_blocks_migration(): void {
		$foreign = time() . ':other-request';
		$this->insert_lock( $foreign );

		self::assertFalse( $this->migrator()->migrate() );

		self::assertSame( 0, $this->migrator()->installed_version() );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table( Tables::CREATORS ) ) ) );
		self::assertSame( $foreign, $this->raw_option( Migrator::LOCK_OPTION ), 'Foreign lock untouched.' );
	}

	public function test_abandoned_lock_is_taken_over_and_released(): void {
		$this->insert_lock( ( time() - 3600 ) . ':crashed-request' );

		self::assertTrue( $this->migrator()->migrate() );

		self::assertSame( 3, $this->migrator()->installed_version() );
		self::assertNull( $this->raw_option( Migrator::LOCK_OPTION ) );
	}

	public function test_lock_insert_is_atomic_on_this_server(): void {
		$sql = 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)';

		self::assertSame( 1, $this->execute( $sql, $this->wpdb->options, Migrator::LOCK_OPTION, '1:a', 'off' ) );
		self::assertSame( 0, $this->execute( $sql, $this->wpdb->options, Migrator::LOCK_OPTION, '2:b', 'off' ) );
		self::assertSame( '1:a', $this->raw_option( Migrator::LOCK_OPTION ), 'The second insert neither wins nor overwrites.' );
	}

	public function test_add_option_is_not_a_lock_when_the_cache_is_stale(): void {
		// Why the migrator does not use add_option(): a request whose cache still
		// says "no such option" overwrites a lock that another request inserted
		// and is told it succeeded (INSERT … ON DUPLICATE KEY UPDATE).
		wp_cache_set( 'notoptions', array( Migrator::LOCK_OPTION => true ), 'options' );
		$this->insert_lock( time() . ':other-request' );

		self::assertTrue( add_option( Migrator::LOCK_OPTION, time() . ':me', '', false ) );
		self::assertSame( time() . ':me', $this->raw_option( Migrator::LOCK_OPTION ) );
	}

	public function test_concurrent_requests_run_each_migration_exactly_once(): void {
		$this->wpdb->insert(
			$this->wpdb->options,
			array(
				'option_name'  => 'merchboard_test_runs',
				'option_value' => '0',
				'autoload'     => 'off',
			)
		);
		$start     = microtime( true ) + 4;
		$processes = array();
		$outputs   = array();

		for ( $i = 0; $i < 6; $i++ ) {
			$process = proc_open(
				array( PHP_BINARY, __DIR__ . '/../Fixtures/migrate-contender.php', (string) $start ),
				array(
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes
			);
			self::assertIsResource( $process, 'Contender process could not be started.' );
			$processes[] = $process;
			$outputs[]   = $pipes;
		}

		$results = array();

		foreach ( $processes as $i => $process ) {
			$results[] = trim( (string) stream_get_contents( $outputs[ $i ][1] ) ) . trim( (string) stream_get_contents( $outputs[ $i ][2] ) );
			proc_close( $process );
		}

		foreach ( $results as $result ) {
			self::assertContains( $result, array( 'migrated', 'locked' ), 'Contender output: ' . $result );
		}

		self::assertSame( '1', $this->raw_option( 'merchboard_test_runs' ), 'The migration ran exactly once: ' . implode( ', ', $results ) );
		self::assertContains( 'locked', $results, 'At least one request found the lock held.' );
		self::assertSame( '1', (string) $this->raw_option( Migrator::VERSION_OPTION ) );
		self::assertNull( $this->raw_option( Migrator::LOCK_OPTION ) );

		$this->wpdb->delete( $this->wpdb->options, array( 'option_name' => 'merchboard_test_runs' ) );
	}

	private function migrator(): Migrator {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( Migrator::VERSION_OPTION, 'options' );

		return new Migrator( $this->wpdb, Schema::migrations() );
	}

	/**
	 * Option value straight from the database (no cache).
	 *
	 * @param string $name Option name.
	 */
	private function raw_option( string $name ): ?string {
		$value = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $this->wpdb->options, $name ) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Inserts a lock row as another request would.
	 *
	 * @param string $value Lock value.
	 */
	private function insert_lock( string $value ): void {
		$this->wpdb->insert(
			$this->wpdb->options,
			array(
				'option_name'  => Migrator::LOCK_OPTION,
				'option_value' => $value,
				'autoload'     => 'off',
			)
		);
	}
}
