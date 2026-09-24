<?php
/**
 * Tests for the migrator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Database;

use ArrayObject;
use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migrator;
use MerchBoard\Tests\Unit\Support\CallbackMigration;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\RecordingMigration;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Migrator::class )]
final class MigratorTest extends TestCase {

	/**
	 * Run log shared by the migration doubles.
	 *
	 * @var ArrayObject<int, string>
	 */
	private ArrayObject $log;

	private RecordingWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->log  = new ArrayObject();
		$this->wpdb = new RecordingWpdb();
	}

	public function test_latest_version_is_the_highest_migration_key(): void {
		InMemoryOptions::install();

		self::assertSame( 3, $this->migrator( array( 3, 1, 2 ) )->latest_version() );
		self::assertSame( 0, $this->migrator( array() )->latest_version() );
	}

	public function test_fresh_install_reports_version_0_and_needs_migration(): void {
		InMemoryOptions::install();
		$migrator = $this->migrator( array( 1 ) );

		self::assertSame( 0, $migrator->installed_version() );
		self::assertTrue( $migrator->needs_migration() );
	}

	public function test_invalid_stored_version_is_treated_as_not_installed(): void {
		InMemoryOptions::install( array( Migrator::VERSION_OPTION => 'garbage' ) );

		self::assertSame( 0, $this->migrator( array( 1 ) )->installed_version() );
	}

	public function test_migrates_from_0_to_1_and_saves_the_version(): void {
		$options = InMemoryOptions::install();

		self::assertTrue( $this->migrator( array( 1 ) )->migrate() );

		self::assertSame( array( 'm1' ), $this->log->getArrayCopy() );
		self::assertSame( 1, $options->options[ Migrator::VERSION_OPTION ] );
		self::assertSame( array(), $this->wpdb->option_rows, 'Lock must be released.' );
	}

	public function test_up_to_date_schema_runs_nothing(): void {
		$options  = InMemoryOptions::install( array( Migrator::VERSION_OPTION => '1' ) );
		$migrator = $this->migrator( array( 1 ) );

		self::assertFalse( $migrator->needs_migration() );
		self::assertTrue( $migrator->migrate() );
		self::assertSame( array(), $this->log->getArrayCopy() );
		self::assertSame( array(), $options->updates );
	}

	public function test_running_twice_migrates_only_once(): void {
		InMemoryOptions::install();
		$migrator = $this->migrator( array( 1 ) );

		$migrator->migrate();
		$migrator->migrate();

		self::assertSame( array( 'm1' ), $this->log->getArrayCopy() );
	}

	public function test_runs_only_pending_migrations_in_ascending_order(): void {
		$options = InMemoryOptions::install( array( Migrator::VERSION_OPTION => 1 ) );

		$this->migrator( array( 3, 1, 2 ) )->migrate();

		self::assertSame( array( 'm2', 'm3' ), $this->log->getArrayCopy() );
		self::assertSame( 3, $options->options[ Migrator::VERSION_OPTION ] );
	}

	public function test_failure_keeps_last_successful_version_and_releases_lock(): void {
		$options          = InMemoryOptions::install();
		$wpdb             = $this->wpdb;
		$wpdb->last_error = "Can't create table 'wp_x' (errno: 13 \"Permission denied\")";
		$migrator         = new Migrator(
			$wpdb,
			array(
				1 => new RecordingMigration( 'm1', $this->log ),
				2 => new RecordingMigration( 'm2', $this->log, true ),
				3 => new RecordingMigration( 'm3', $this->log ),
			)
		);

		try {
			$migrator->migrate();
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Database migration failed.', $e->getMessage() );
			self::assertSame(
				array(
					'schema_version'    => 2,
					'installed_version' => 1,
					'migration'         => RecordingMigration::class,
					'reason'            => 'Migration failed.',
					'db_error'          => "Can't create table 'wp_x' (errno: 13 \"Permission denied\")",
				),
				$e->context()
			);
			self::assertInstanceOf( DatabaseException::class, $e->getPrevious() );
		}

		self::assertSame( array( 'm1', 'm2' ), $this->log->getArrayCopy() );
		self::assertSame( 1, $options->options[ Migrator::VERSION_OPTION ] );
		self::assertSame( array(), $this->wpdb->option_rows, 'Lock must be released after a failure.' );
	}

	public function test_database_error_in_context_is_bounded(): void {
		InMemoryOptions::install();
		$wpdb             = new RecordingWpdb();
		$wpdb->last_error = str_repeat( 'x', 1000 );
		$migrator         = new Migrator( $wpdb, array( 1 => new RecordingMigration( 'm1', $this->log, true ) ) );

		try {
			$migrator->migrate();
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertIsString( $e->context()['db_error'] );
			self::assertSame( 200, strlen( $e->context()['db_error'] ) );
		}
	}

	public function test_html_in_database_error_is_reduced_to_plain_text(): void {
		InMemoryOptions::install();
		$wpdb             = new RecordingWpdb();
		$wpdb->last_error = "<div style=\"clear:both\">&nbsp;</div>\n<p>MySQL query:</p><p>CREATE TABLE x (</p><p>Backtrace:</p><pre>#0 /var/www/wp-includes/class-wpdb.php(123)</pre>";
		$migrator         = new Migrator( $wpdb, array( 1 => new RecordingMigration( 'm1', $this->log, true ) ) );

		try {
			$migrator->migrate();
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertIsString( $e->context()['db_error'] );
			self::assertStringNotContainsString( '<', $e->context()['db_error'] );
			self::assertStringNotContainsString( "\n", $e->context()['db_error'] );
			self::assertSame( 'MySQL query: CREATE TABLE x (', $e->context()['db_error'] );
		}
	}

	public function test_does_not_migrate_while_another_request_holds_the_lock(): void {
		$options = InMemoryOptions::install();
		$foreign = time() . ':other-request';

		$this->wpdb->option_rows[ Migrator::LOCK_OPTION ] = $foreign;

		self::assertFalse( $this->migrator( array( 1 ) )->migrate() );

		self::assertSame( array(), $this->log->getArrayCopy() );
		self::assertArrayNotHasKey( Migrator::VERSION_OPTION, $options->options );
		self::assertSame( $foreign, $this->wpdb->option_rows[ Migrator::LOCK_OPTION ], 'Foreign lock must be kept.' );
	}

	public function test_lock_is_created_atomically_and_not_with_add_option(): void {
		InMemoryOptions::install();

		$this->migrator( array( 1 ) )->migrate();

		$templates = array_column( $this->wpdb->prepared, 'query' );
		self::assertContains( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $templates );
		self::assertSame( 'wp_options', $this->wpdb->prepared[0]['args'][0] );
		self::assertSame( 'off', $this->wpdb->prepared[0]['args'][3], 'The lock is never autoloaded.' );
		self::assertMatchesRegularExpression( '/^\d+:[0-9a-f]{16}$/', (string) $this->wpdb->prepared[0]['args'][2], 'Lock value carries time and an owner token.' );
	}

	public function test_abandoned_lock_is_replaced(): void {
		$options = InMemoryOptions::install();

		$this->wpdb->option_rows[ Migrator::LOCK_OPTION ] = ( time() - 3600 ) . ':crashed-request';

		self::assertTrue( $this->migrator( array( 1 ) )->migrate() );

		self::assertSame( array( 'm1' ), $this->log->getArrayCopy() );
		self::assertSame( 1, $options->options[ Migrator::VERSION_OPTION ] );
		self::assertSame( array(), $this->wpdb->option_rows, 'The taken-over lock is released afterwards.' );
	}

	public function test_abandoned_lock_from_earlier_builds_and_unreadable_locks_are_replaced(): void {
		foreach ( array( (string) ( time() - 3600 ), 'garbage', '' ) as $stale ) {
			InMemoryOptions::install();
			$this->log->exchangeArray( array() );
			$this->wpdb->option_rows = array( Migrator::LOCK_OPTION => $stale );

			self::assertTrue( $this->migrator( array( 1 ) )->migrate(), 'Stale value: ' . $stale );
			self::assertSame( array( 'm1' ), $this->log->getArrayCopy() );
		}
	}

	public function test_fresh_lock_from_earlier_builds_is_respected(): void {
		InMemoryOptions::install();
		$this->wpdb->option_rows[ Migrator::LOCK_OPTION ] = (string) time();

		self::assertFalse( $this->migrator( array( 1 ) )->migrate() );
		self::assertSame( array(), $this->log->getArrayCopy() );
	}

	public function test_takeover_is_a_compare_and_swap_on_the_stale_value(): void {
		InMemoryOptions::install();
		$stale = ( time() - 3600 ) . ':crashed-request';

		$this->wpdb->option_rows[ Migrator::LOCK_OPTION ] = $stale;

		$this->migrator( array( 1 ) )->migrate();

		$update = array_values( array_filter( $this->wpdb->prepared, static fn( array $p ): bool => str_starts_with( $p['query'], 'UPDATE' ) ) );
		self::assertCount( 1, $update );
		self::assertSame( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $update[0]['query'] );
		self::assertSame( $stale, $update[0]['args'][3], 'Only a request that still sees the stale value can win.' );
	}

	public function test_losing_the_takeover_race_does_not_migrate(): void {
		InMemoryOptions::install();
		// This request reads the stale value (the SELECT is the second prepared
		// statement), but another request swapped it before our UPDATE.
		$this->wpdb->vars['PREPARED#1']                   = ( time() - 3600 ) . ':crashed-request';
		$this->wpdb->option_rows[ Migrator::LOCK_OPTION ] = time() . ':winner';

		self::assertFalse( $this->migrator( array( 1 ) )->migrate() );
		self::assertSame( array(), $this->log->getArrayCopy() );
		self::assertSame( time() . ':winner', $this->wpdb->option_rows[ Migrator::LOCK_OPTION ] );
	}

	public function test_release_never_deletes_a_lock_that_another_request_took_over(): void {
		InMemoryOptions::install();
		$wpdb     = $this->wpdb;
		$migrator = new Migrator(
			$wpdb,
			array(
				1 => new CallbackMigration(
					static function () use ( $wpdb ): void {
						// This run outlived the timeout and another request took the lock.
						$wpdb->option_rows[ Migrator::LOCK_OPTION ] = time() . ':other-request';
					}
				),
			)
		);

		self::assertTrue( $migrator->migrate() );
		self::assertSame( time() . ':other-request', $wpdb->option_rows[ Migrator::LOCK_OPTION ] );
	}

	public function test_rejects_non_positive_versions(): void {
		$this->expectException( InvalidArgumentException::class );

		new Migrator( new RecordingWpdb(), array( 0 => new RecordingMigration( 'm0', $this->log ) ) );
	}

	/**
	 * Creates a migrator with recording migrations for the given versions.
	 *
	 * @param int[] $versions Versions.
	 */
	private function migrator( array $versions ): Migrator {
		$migrations = array();

		foreach ( $versions as $version ) {
			$migrations[ $version ] = new RecordingMigration( 'm' . $version, $this->log );
		}

		return new Migrator( $this->wpdb, $migrations );
	}
}
