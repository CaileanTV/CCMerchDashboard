<?php
/**
 * Tests for schema migration 1.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Database;

use Brain\Monkey\Functions;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migrations\CreateCreatorsTable;
use MerchBoard\Core\Database\Schema;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( CreateCreatorsTable::class )]
#[CoversClass( Schema::class )]
final class CreateCreatorsTableTest extends TestCase {

	private const CHARSET = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';

	public function test_schema_version_1_creates_the_creators_table(): void {
		$migrations = Schema::migrations();

		self::assertSame( array( 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20 ), array_keys( $migrations ) );
		self::assertInstanceOf( CreateCreatorsTable::class, $migrations[1] );
		self::assertInstanceOf( \MerchBoard\Core\Database\Migrations\CreateAuditLogTable::class, $migrations[2] );
	}

	public function test_schema_defines_the_expected_columns_and_keys(): void {
		$sql = CreateCreatorsTable::schema( 'wp_merchboard_creators', self::CHARSET );

		self::assertStringStartsWith( "CREATE TABLE wp_merchboard_creators (\n", $sql );
		self::assertStringContainsString( "\nid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n", $sql );
		self::assertStringContainsString( "\nuser_id bigint(20) unsigned DEFAULT NULL,\n", $sql );
		self::assertStringContainsString( "\ndisplay_name varchar(191) NOT NULL,\n", $sql );
		self::assertStringContainsString( "\nstatus varchar(20) NOT NULL,\n", $sql );
		self::assertStringContainsString( "\ncreated_at datetime NOT NULL,\n", $sql );
		self::assertStringContainsString( "\nupdated_at datetime NOT NULL,\n", $sql );
		self::assertStringContainsString( "\nUNIQUE KEY user_id (user_id),\n", $sql );
		self::assertStringContainsString( "\nKEY status (status)\n", $sql );
		self::assertStringEndsWith( ') ' . self::CHARSET . ';', $sql );
	}

	public function test_schema_follows_dbdelta_formatting_rules(): void {
		$sql = CreateCreatorsTable::schema( 'wp_merchboard_creators', self::CHARSET );

		// dbDelta() requires exactly two spaces after PRIMARY KEY and KEY instead of INDEX.
		self::assertStringContainsString( 'PRIMARY KEY  (id)', $sql );
		self::assertStringNotContainsString( 'INDEX', $sql );
		// No foreign keys and no database-side timestamp defaults (ADR-019).
		self::assertStringNotContainsString( 'FOREIGN KEY', $sql );
		self::assertStringNotContainsString( 'CURRENT_TIMESTAMP', $sql );
	}

	public function test_up_runs_dbdelta_with_prefixed_table_and_verifies_it_exists(): void {
		$wpdb         = new RecordingWpdb();
		$wpdb->prefix = 'wp_3_';
		$wpdb->var    = 'wp_3_merchboard_creators';

		Functions\expect( 'dbDelta' )
			->once()
			->with( CreateCreatorsTable::schema( 'wp_3_merchboard_creators', self::CHARSET ) );

		( new CreateCreatorsTable() )->up( $wpdb );

		self::assertSame( 'SHOW TABLES LIKE %s', $wpdb->prepared[0]['query'] );
		self::assertSame( array( 'wp\_3\_merchboard\_creators' ), $wpdb->prepared[0]['args'] );
		self::assertSame( array( 'PREPARED#0' ), $wpdb->executed );
	}

	public function test_up_is_idempotent(): void {
		$wpdb      = new RecordingWpdb();
		$wpdb->var = 'wp_merchboard_creators';

		Functions\expect( 'dbDelta' )->twice();

		$migration = new CreateCreatorsTable();
		$migration->up( $wpdb );
		$migration->up( $wpdb );

		$this->addToAssertionCount( 1 );
	}

	public function test_up_fails_when_the_table_is_missing_afterwards(): void {
		$wpdb      = new RecordingWpdb();
		$wpdb->var = null;

		Functions\when( 'dbDelta' )->justReturn( array() );

		$this->expectException( DatabaseException::class );

		( new CreateCreatorsTable() )->up( $wpdb );
	}
}
