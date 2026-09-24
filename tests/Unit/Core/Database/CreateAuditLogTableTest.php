<?php
/**
 * Tests for schema migration 2.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Database;

use Brain\Monkey\Functions;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migrations\CreateAuditLogTable;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( CreateAuditLogTable::class )]
final class CreateAuditLogTableTest extends TestCase {

	public function test_schema_defines_the_audit_columns_and_keys(): void {
		$sql = CreateAuditLogTable::schema( 'wp_merchboard_audit_log', 'DEFAULT CHARACTER SET utf8mb4' );

		self::assertStringStartsWith( "CREATE TABLE wp_merchboard_audit_log (\n", $sql );
		foreach ( array(
			"\nid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n",
			"\ncreated_at datetime NOT NULL,\n",
			"\naction varchar(50) NOT NULL,\n",
			"\nactor_user_id bigint(20) unsigned DEFAULT NULL,\n",
			"\ncreator_id bigint(20) unsigned DEFAULT NULL,\n",
			"\ncontext longtext DEFAULT NULL,\n",
			'PRIMARY KEY  (id)',
			"\nKEY created_at (created_at),\n",
			"\nKEY action (action),\n",
			"\nKEY creator_id (creator_id)\n",
		) as $fragment ) {
			self::assertStringContainsString( $fragment, $sql );
		}
		foreach ( array( 'FOREIGN KEY', 'CURRENT_TIMESTAMP', 'INDEX', 'ip', 'user_agent' ) as $absent ) {
			self::assertStringNotContainsString( $absent, $sql );
		}
	}

	public function test_up_creates_the_prefixed_table_idempotently(): void {
		$wpdb               = new RecordingWpdb();
		$wpdb->prefix       = 'wp_9_';
		$wpdb->tables_exist = true;

		Functions\expect( 'dbDelta' )->twice()->with( \Mockery::pattern( '/^CREATE TABLE wp_9_merchboard_audit_log \(/' ) );

		$migration = new CreateAuditLogTable();
		$migration->up( $wpdb );
		$migration->up( $wpdb );

		self::assertSame( 'SHOW TABLES LIKE %s', $wpdb->prepared[0]['query'] );
	}

	public function test_up_fails_when_the_table_is_missing_afterwards(): void {
		$wpdb = new RecordingWpdb();
		Functions\when( 'dbDelta' )->justReturn( array() );

		$this->expectException( DatabaseException::class );

		( new CreateAuditLogTable() )->up( $wpdb );
	}
}
