<?php
/**
 * Tests for schema migration 5.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Database;

use Brain\Monkey\Functions;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migrations\CreateSalesLedgerTables;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( CreateSalesLedgerTables::class )]
final class CreateSalesLedgerTablesTest extends TestCase {

	public function test_sales_schema_defines_the_locked_columns_and_keys(): void {
		$sql = CreateSalesLedgerTables::sales_schema( 'wp_merchboard_sales', 'DEFAULT CHARACTER SET utf8mb4' );

		self::assertStringStartsWith( "CREATE TABLE wp_merchboard_sales (\n", $sql );
		foreach ( array(
			"\nid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n",
			"\nevent_key varchar(64) NOT NULL,\n",
			"\ncycle int(10) unsigned NOT NULL,\n",
			"\nrefund_id bigint(20) unsigned NOT NULL DEFAULT 0,\n",
			"\nitem_name varchar(191) NOT NULL DEFAULT '',\n",
			"\ncorrection_reason varchar(32) DEFAULT NULL,\n",
			"\nquantity int(11) NOT NULL,\n",
			"\nnet_amount decimal(19,4) NOT NULL,\n",
			"\ncurrency char(3) NOT NULL,\n",
			'PRIMARY KEY  (id)',
			"\nUNIQUE KEY event_key (event_key),\n",
			"\nKEY creator_occurred (creator_id,occurred_at),\n",
			"\nKEY order_id (order_id),\n",
			"\nKEY refund_id (refund_id)\n",
		) as $fragment ) {
			self::assertStringContainsString( $fragment, $sql );
		}
		foreach ( array( 'FOREIGN KEY', 'CURRENT_TIMESTAMP', 'float', 'double' ) as $absent ) {
			self::assertStringNotContainsString( $absent, $sql );
		}
		self::assertSame( 1, substr_count( $sql, 'UNIQUE KEY' ), 'event_key is the only unique key; no UNIQUE(order_item_id, refund_id, type).' );
	}

	public function test_cycles_schema_has_the_unique_key_and_no_separate_order_index(): void {
		$sql = CreateSalesLedgerTables::cycles_schema( 'wp_merchboard_sales_cycles', 'DEFAULT CHARACTER SET utf8mb4' );

		self::assertStringContainsString( "\nUNIQUE KEY order_cycle_event (order_id,cycle,event)\n", $sql );
		self::assertStringContainsString( "\nevent varchar(5) NOT NULL,\n", $sql );
		self::assertStringNotContainsString( 'KEY order_id', $sql );
		self::assertStringNotContainsString( 'status', $sql, 'No mutable status column.' );
	}

	public function test_up_creates_both_prefixed_tables(): void {
		$wpdb               = new RecordingWpdb();
		$wpdb->prefix       = 'wp_9_';
		$wpdb->tables_exist = true;
		$created            = array();

		Functions\when( 'dbDelta' )->alias(
			static function ( string $sql ) use ( &$created ): array {
				$created[] = (string) strtok( $sql, '(' );
				return array();
			}
		);

		( new CreateSalesLedgerTables() )->up( $wpdb );

		self::assertSame( array( 'CREATE TABLE wp_9_merchboard_sales ', 'CREATE TABLE wp_9_merchboard_sales_cycles ' ), $created );
	}

	public function test_up_fails_when_a_table_is_missing_afterwards(): void {
		$wpdb = new RecordingWpdb();
		Functions\when( 'dbDelta' )->justReturn( array() );

		$this->expectException( DatabaseException::class );

		( new CreateSalesLedgerTables() )->up( $wpdb );
	}
}
