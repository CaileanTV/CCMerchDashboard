<?php
/**
 * Tests for schema migration 6 (creator pricing).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Database;

use Brain\Monkey\Functions;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migrations\CreateCreatorPricingTables;
use MerchBoard\Core\Database\Schema;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( CreateCreatorPricingTables::class )]
final class CreateCreatorPricingTablesTest extends TestCase {

	private const CHARSET = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';

	private const ALTER = 'ALTER TABLE %i ADD COLUMN default_markup_bps int(10) unsigned NOT NULL DEFAULT 0';

	public function test_schema_version_6_is_the_pricing_migration(): void {
		self::assertInstanceOf( CreateCreatorPricingTables::class, Schema::migrations()[6] );
	}

	public function test_overrides_schema_has_one_fixed_profit_per_parent_and_no_percentage(): void {
		$sql = CreateCreatorPricingTables::overrides_schema( 'wp_merchboard_pricing_overrides', self::CHARSET );

		self::assertStringStartsWith( "CREATE TABLE wp_merchboard_pricing_overrides (\n", $sql );
		self::assertStringContainsString( "\nproduct_id bigint(20) unsigned NOT NULL,\n", $sql );
		self::assertStringContainsString( "\ncreator_id bigint(20) unsigned NOT NULL,\n", $sql );
		self::assertStringContainsString( "\nfixed_profit decimal(19,4) NOT NULL,\n", $sql );
		self::assertStringContainsString( "\ncurrency char(3) NOT NULL,\n", $sql );
		self::assertStringContainsString( "\nPRIMARY KEY  (product_id),\n", $sql );
		self::assertStringContainsString( "\nKEY creator_id (creator_id)\n", $sql );
		self::assertStringNotContainsString( 'markup', $sql, 'A product has a fixed profit or the default markup, never both.' );
	}

	public function test_item_pricing_schema_keeps_base_and_applied_values_but_no_expected_price(): void {
		$sql = CreateCreatorPricingTables::item_pricing_schema( 'wp_merchboard_item_pricing', self::CHARSET );

		self::assertStringContainsString( "\nitem_id bigint(20) unsigned NOT NULL,\n", $sql );
		self::assertStringContainsString( "\nbase_price decimal(19,4) NOT NULL,\n", $sql );
		self::assertStringContainsString( "\nbase_currency char(3) NOT NULL,\n", $sql );
		self::assertStringContainsString( "\napplied_at datetime DEFAULT NULL,\n", $sql );
		self::assertStringContainsString( "\napplied_creator_id bigint(20) unsigned DEFAULT NULL,\n", $sql );
		self::assertStringContainsString( "\napplied_rule varchar(7) DEFAULT NULL,\n", $sql );
		self::assertStringContainsString( "\napplied_markup_bps int(10) unsigned DEFAULT NULL,\n", $sql );
		self::assertStringContainsString( "\napplied_price decimal(19,4) DEFAULT NULL,\n", $sql );
		self::assertStringContainsString( "\napplied_currency char(3) DEFAULT NULL,\n", $sql );
		self::assertStringContainsString( "\nPRIMARY KEY  (item_id),\n", $sql );
		self::assertStringContainsString( "\nKEY product_id (product_id)\n", $sql );
		self::assertStringNotContainsString( 'expected', $sql );
	}

	public function test_sales_line_pricing_schema_is_a_self_contained_snapshot(): void {
		$sql = CreateCreatorPricingTables::sales_line_pricing_schema( 'wp_merchboard_sales_line_pricing', self::CHARSET );

		self::assertStringContainsString( "\norder_item_id bigint(20) unsigned NOT NULL,\n", $sql );
		self::assertStringContainsString( "\nvariation_id bigint(20) unsigned NOT NULL DEFAULT 0,\n", $sql );
		self::assertStringContainsString( "\nstatus varchar(8) NOT NULL,\n", $sql );
		self::assertStringContainsString( "\nreason varchar(20) DEFAULT NULL,\n", $sql );
		self::assertStringContainsString( "\nbase_unit decimal(19,4) DEFAULT NULL,\n", $sql );
		self::assertStringContainsString( "\nprofit_unit decimal(19,4) DEFAULT NULL,\n", $sql );
		self::assertStringContainsString( "\nprice_unit decimal(19,4) DEFAULT NULL,\n", $sql );
		self::assertStringContainsString( "\ncaptured_at datetime NOT NULL,\n", $sql );
		self::assertStringContainsString( "\nPRIMARY KEY  (order_item_id),\n", $sql );
	}

	public function test_schemas_follow_the_dbdelta_rules_without_foreign_keys(): void {
		foreach ( array(
			CreateCreatorPricingTables::overrides_schema( 't', self::CHARSET ),
			CreateCreatorPricingTables::item_pricing_schema( 't', self::CHARSET ),
			CreateCreatorPricingTables::sales_line_pricing_schema( 't', self::CHARSET ),
		) as $sql ) {
			self::assertStringNotContainsString( 'INDEX', $sql );
			self::assertStringNotContainsString( 'FOREIGN KEY', $sql );
			self::assertStringNotContainsString( 'REFERENCES', $sql );
			self::assertStringNotContainsString( 'CURRENT_TIMESTAMP', $sql );
			self::assertStringNotContainsString( 'float', strtolower( $sql ) );
			self::assertStringNotContainsString( 'double', strtolower( $sql ) );
			self::assertStringEndsWith( ') ' . self::CHARSET . ';', $sql );
		}
	}

	public function test_up_adds_the_markup_column_and_creates_the_three_tables(): void {
		$wpdb                = new RecordingWpdb();
		$wpdb->prefix        = 'wp_3_';
		$wpdb->tables_exist  = true;
		$wpdb->columns_exist = false;

		Functions\expect( 'dbDelta' )->once()->ordered()->with( CreateCreatorPricingTables::overrides_schema( 'wp_3_merchboard_pricing_overrides', self::CHARSET ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( CreateCreatorPricingTables::item_pricing_schema( 'wp_3_merchboard_item_pricing', self::CHARSET ) );
		Functions\expect( 'dbDelta' )->once()->ordered()->with( CreateCreatorPricingTables::sales_line_pricing_schema( 'wp_3_merchboard_sales_line_pricing', self::CHARSET ) );

		( new CreateCreatorPricingTables() )->up( $wpdb );

		$alters = array_values( array_filter( $wpdb->prepared, static fn ( array $p ): bool => self::ALTER === $p['query'] ) );

		self::assertCount( 1, $alters );
		self::assertSame( array( 'wp_3_merchboard_creators' ), $alters[0]['args'] );
		self::assertArrayHasKey( 'wp_3_merchboard_creators.default_markup_bps', $wpdb->added_columns );
	}

	public function test_up_is_idempotent_and_never_adds_the_column_twice(): void {
		$wpdb               = new RecordingWpdb();
		$wpdb->tables_exist = true;

		Functions\expect( 'dbDelta' )->times( 3 );

		( new CreateCreatorPricingTables() )->up( $wpdb );

		self::assertSame( array(), array_filter( $wpdb->prepared, static fn ( array $p ): bool => self::ALTER === $p['query'] ) );
	}

	public function test_up_fails_when_the_column_cannot_be_added(): void {
		$wpdb                = new RecordingWpdb();
		$wpdb->tables_exist  = true;
		$wpdb->columns_exist = false;
		// PREPARED#0 is the column check, PREPARED#1 the ALTER.
		$wpdb->query_results = array( 'PREPARED#1' => false );

		Functions\expect( 'dbDelta' )->never();

		$this->expectException( DatabaseException::class );

		( new CreateCreatorPricingTables() )->up( $wpdb );
	}

	public function test_up_fails_when_a_table_is_missing_afterwards(): void {
		$wpdb                = new RecordingWpdb();
		$wpdb->columns_exist = true;

		Functions\when( 'dbDelta' )->justReturn( array() );

		$this->expectException( DatabaseException::class );

		( new CreateCreatorPricingTables() )->up( $wpdb );
	}
}
