<?php
/**
 * Schema version 12: the G1 tables and the activation row (E3 T2), on a real MySQL/MariaDB.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Database;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migrations\CreateGiftCardAssessmentTables;
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
 * Migration 12 creates the four G1 tables (InnoDB, with their keys) and one
 * inactive activation row; running it again changes nothing, also not an
 * activation row set by hand; a missing row is created again; a failing
 * table keeps the schema version at 11. The opt-in uninstall keeps the
 * tables and their rows, and the reinstall finds them unchanged (part of
 * E3.10 tests 19 and 27). Nothing reads or writes these tables yet.
 * Synthetic data only.
 */
final class GiftCardAssessmentSchemaTest extends IntegrationTestCase {

	private const G1_TABLES = array(
		Tables::ORDER_GIFT_CARD_ASSESSMENTS,
		Tables::ORDER_GIFT_CARD_PAYOUTS,
		Tables::ORDER_GIFT_CARD_ATTEMPTS,
		Tables::G1_STATE,
	);

	protected function tearDown(): void {
		delete_option( RestoredHistory::OPTION );
		delete_option( Settings::OPTION );
		parent::tearDown();
	}

	public function test_a_fresh_install_creates_the_tables_and_one_inactive_activation_row(): void {
		$this->install();

		self::assertSame( '29', (string) get_option( Migrator::VERSION_OPTION ) );

		foreach ( self::G1_TABLES as $table ) {
			self::assertSame( 'InnoDB', $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->table( $table ) ) ), $table );
		}

		self::assertSame( array( 'order_id' ), $this->primary_key( Tables::ORDER_GIFT_CARD_ASSESSMENTS ) );
		self::assertSame( array( 'order_id', 'payout_id' ), $this->primary_key( Tables::ORDER_GIFT_CARD_PAYOUTS ) );
		self::assertSame( array( 'order_id' ), $this->primary_key( Tables::ORDER_GIFT_CARD_ATTEMPTS ) );
		self::assertSame( array( 'id' ), $this->primary_key( Tables::G1_STATE ) );
		self::assertSame(
			array( 'order_id', 'payout_id', 'creator_id', 'gift_card_provider', 'gift_card_external_id', 'recorded_at' ),
			$this->columns( Tables::ORDER_GIFT_CARD_PAYOUTS ),
			'References only: no code column.'
		);

		self::assertSame( array( $this->inactive_row() ), $this->rows_of( Tables::G1_STATE ) );

		foreach ( array( Tables::ORDER_GIFT_CARD_ASSESSMENTS, Tables::ORDER_GIFT_CARD_PAYOUTS, Tables::ORDER_GIFT_CARD_ATTEMPTS ) as $table ) {
			self::assertSame( 0, $this->count_rows( $table ), $table );
		}
	}

	public function test_running_the_migration_again_changes_nothing_also_not_a_set_activation_row(): void {
		$this->install();
		$this->execute( 'UPDATE %i SET cutoff_utc = %s, activated_at = %s, activated_by = %d WHERE id = 1', $this->table( Tables::G1_STATE ), '2026-12-01 00:00:00', '2026-11-30 12:00:00', 1 );
		$this->execute( 'INSERT INTO %i (order_id, status, updated_at) VALUES (%d, %s, %s)', $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), 900, 'pending', '2026-12-02 10:00:00' );
		$before = $this->all_rows();

		( new CreateGiftCardAssessmentTables() )->up( $this->wpdb );
		( new CreateGiftCardAssessmentTables() )->up( $this->wpdb );

		self::assertSame( $before, $this->all_rows() );
		self::assertSame( 1, $this->count_rows( Tables::G1_STATE ) );
	}

	public function test_a_missing_activation_row_is_created_again_inactive(): void {
		$this->install();
		$this->execute( 'DELETE FROM %i', $this->table( Tables::G1_STATE ) );

		( new CreateGiftCardAssessmentTables() )->up( $this->wpdb );

		self::assertSame( array( $this->inactive_row() ), $this->rows_of( Tables::G1_STATE ) );
	}

	public function test_a_failing_table_keeps_the_schema_version_at_11(): void {
		$migrations = Schema::migrations();
		unset( $migrations[12], $migrations[13], $migrations[14], $migrations[15], $migrations[16], $migrations[17], $migrations[18], $migrations[19], $migrations[20], $migrations[21], $migrations[22], $migrations[23], $migrations[24], $migrations[25], $migrations[26], $migrations[27], $migrations[28], $migrations[29] );
		( new Migrator( $this->wpdb, $migrations ) )->migrate();
		self::assertSame( '11', (string) get_option( Migrator::VERSION_OPTION ) );

		// The activation table's CREATE TABLE fails in the database itself
		// (two primary keys, error 1068), so the table does not exist afterwards.
		$state  = $this->table( Tables::G1_STATE );
		$failed = false;
		$filter = static function ( string $sql ) use ( $state, &$failed ): string {
			if ( str_starts_with( $sql, "CREATE TABLE {$state} (" ) ) {
				$failed = true;

				return "CREATE TABLE {$state} (id tinyint(3) unsigned NOT NULL, PRIMARY KEY (id), PRIMARY KEY (id))";
			}

			return $sql;
		};
		add_filter( 'query', $filter );
		$this->wpdb->suppress_errors( true );

		try {
			( new Migrator( $this->wpdb, Schema::migrations() ) )->migrate();
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Database migration failed.', $e->getMessage() );
			self::assertSame( 'Could not create the gift card assessment tables.', $e->getPrevious()?->getMessage() );
		} finally {
			remove_filter( 'query', $filter );
		}

		self::assertTrue( $failed, 'The CREATE TABLE statement was replaced.' );
		self::assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $state ) ) ), 'The activation table does not exist.' );

		wp_cache_delete( Migrator::VERSION_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		self::assertSame( '11', (string) get_option( Migrator::VERSION_OPTION ), 'The version is stored only after up() returned.' );
	}

	public function test_the_opt_in_uninstall_keeps_the_g1_tables_and_the_reinstall_finds_them_unchanged(): void {
		$this->install();
		$this->execute( 'UPDATE %i SET cutoff_utc = %s, activated_at = %s, activated_by = %d WHERE id = 1', $this->table( Tables::G1_STATE ), '2026-12-01 00:00:00', '2026-11-30 12:00:00', 1 );
		$this->execute( 'INSERT INTO %i (order_id, status, updated_at) VALUES (%d, %s, %s)', $this->table( Tables::ORDER_GIFT_CARD_ASSESSMENTS ), 900, 'paid', '2026-12-02 10:00:00' );
		$this->execute( 'INSERT INTO %i (order_id, payout_id, creator_id, gift_card_provider, gift_card_external_id, recorded_at) VALUES (%d, %d, %d, %s, %s, %s)', $this->table( Tables::ORDER_GIFT_CARD_PAYOUTS ), 900, 5, 3, 'yith-wcgc', '4711', '2026-12-02 10:00:00' );
		$this->execute( 'INSERT INTO %i (order_id, attempts, last_attempt_at, last_error_code) VALUES (%d, %d, %s, %s)', $this->table( Tables::ORDER_GIFT_CARD_ATTEMPTS ), 901, 2, '2026-12-02 11:00:00', 'plugin_unavailable' );
		$before = $this->all_rows();

		self::assertTrue( $this->service( SettingsService::class )->set_delete_data_on_uninstall( true, 'DELETE' ) );
		self::assertTrue( ( new Uninstaller( $this->wpdb, new SchemaEraser( $this->wpdb, new RecordingLogger() ), new RecordingLogger() ) )->run() );

		self::assertNull( get_option( Migrator::VERSION_OPTION, null ), 'The schema version is deleted.' );
		self::assertSame( $before, $this->all_rows(), 'Kept by the uninstall.' );

		$this->install();

		self::assertSame( '29', (string) get_option( Migrator::VERSION_OPTION ), 'Every migration ran again.' );
		self::assertSame( $before, $this->all_rows(), 'Migration 12 changed no kept row, not even the activation.' );
	}

	/**
	 * The activation row as created by the migration.
	 *
	 * @return array<string, string|null>
	 */
	private function inactive_row(): array {
		return array(
			'id'           => '1',
			'cutoff_utc'   => null,
			'activated_at' => null,
			'activated_by' => null,
		);
	}

	/**
	 * Rows of every G1 table.
	 *
	 * @return array<string, list<array<string, string|null>>>
	 */
	private function all_rows(): array {
		$rows = array();

		foreach ( self::G1_TABLES as $table ) {
			$rows[ $table ] = $this->rows_of( $table );
		}

		return $rows;
	}

	/**
	 * Rows of a table as MySQL returns them, in primary key order.
	 *
	 * @param string $table Tables constant.
	 * @return list<array<string, string|null>>
	 */
	private function rows_of( string $table ): array {
		$sql = match ( $table ) {
			Tables::ORDER_GIFT_CARD_PAYOUTS => 'SELECT * FROM %i ORDER BY order_id, payout_id',
			Tables::G1_STATE                => 'SELECT * FROM %i ORDER BY id',
			default                         => 'SELECT * FROM %i ORDER BY order_id',
		};
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $this->table( $table ) ), ARRAY_A );

		return array_map(
			static fn( array $row ): array => array_map( static fn( mixed $value ): ?string => null === $value ? null : (string) $value, $row ),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Primary key columns of a table, in key order.
	 *
	 * @param string $table Tables constant.
	 * @return list<string>
	 */
	private function primary_key( string $table ): array {
		return array_map( 'strval', $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s ORDER BY ORDINAL_POSITION', $this->table( $table ), 'PRIMARY' ) ) );
	}

	/**
	 * Columns of a table, in table order.
	 *
	 * @param string $table Tables constant.
	 * @return list<string>
	 */
	private function columns( string $table ): array {
		return array_map( 'strval', $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION', $this->table( $table ) ) ) );
	}
}
