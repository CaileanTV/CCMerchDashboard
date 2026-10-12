<?php
/**
 * Tests for schema migration 8 (creator share model).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Database;

use Brain\Monkey\Functions;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migrations\AddCreatorShareModel;
use MerchBoard\Core\Database\Schema;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( AddCreatorShareModel::class )]
final class AddCreatorShareModelTest extends TestCase {

	private const CHARSET = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';

	public function test_schema_version_8_is_the_share_migration(): void {
		self::assertSame( AddCreatorShareModel::class, Schema::migrations()[8]::class );
		self::assertInstanceOf( AddCreatorShareModel::class, Schema::migrations()[8] );
	}

	public function test_product_markups_schema_has_one_percentage_per_parent_and_no_amount(): void {
		$sql = AddCreatorShareModel::product_markups_schema( 'wp_merchboard_pricing_overrides', self::CHARSET );

		self::assertStringStartsWith( "CREATE TABLE wp_merchboard_pricing_overrides (\n", $sql );
		self::assertStringContainsString( "\nproduct_id bigint(20) unsigned NOT NULL,\n", $sql );
		self::assertStringContainsString( "\ncreator_id bigint(20) unsigned NOT NULL,\n", $sql );
		self::assertStringContainsString( "\nmarkup_bps int(10) unsigned NOT NULL,\n", $sql );
		self::assertStringContainsString( "\nPRIMARY KEY  (product_id),\n", $sql );
		self::assertStringContainsString( "\nKEY creator_id (creator_id)\n", $sql );
		self::assertStringNotContainsString( 'decimal', $sql, 'A share, never an amount.' );
		self::assertStringNotContainsString( 'FOREIGN KEY', $sql );
		self::assertStringEndsWith( ') ' . self::CHARSET . ';', $sql );
	}

	public function test_up_adds_the_share_columns_recreates_the_overrides_and_drops_item_pricing(): void {
		$wpdb                = new RecordingWpdb();
		$wpdb->prefix        = 'wp_3_';
		$wpdb->tables_exist  = true;
		$wpdb->columns_exist = false;

		Functions\expect( 'dbDelta' )->once()->with( AddCreatorShareModel::product_markups_schema( 'wp_3_merchboard_pricing_overrides', self::CHARSET ) )->andReturnUsing(
			static function () use ( $wpdb ): array {
				$wpdb->added_columns['wp_3_merchboard_pricing_overrides.markup_bps'] = true;

				return array();
			}
		);

		( new AddCreatorShareModel() )->up( $wpdb );

		self::assertArrayHasKey( 'wp_3_merchboard_sales_line_pricing.share_bps', $wpdb->added_columns );
		self::assertArrayHasKey( 'wp_3_merchboard_sales_line_pricing.share_source', $wpdb->added_columns );
		self::assertArrayHasKey( 'wp_3_merchboard_sales_line_pricing.price_decimals', $wpdb->added_columns );
		self::assertSame( array( 'wp_3_merchboard_pricing_overrides' => true, 'wp_3_merchboard_item_pricing' => true ), $wpdb->dropped_tables ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array(), self::alters_of( $wpdb, 'merchboard_sales' ), 'The ledger is not touched.' );
		self::assertSame( array(), self::alters_of( $wpdb, 'merchboard_creators' ) );
	}

	public function test_the_new_columns_are_nullable_so_existing_snapshots_stay_unknown(): void {
		$wpdb                = new RecordingWpdb();
		$wpdb->tables_exist  = true;
		$wpdb->columns_exist = false;

		Functions\when( 'dbDelta' )->alias(
			static function () use ( $wpdb ): array {
				$wpdb->added_columns['wp_merchboard_pricing_overrides.markup_bps'] = true;

				return array();
			}
		);

		( new AddCreatorShareModel() )->up( $wpdb );

		$alters = array_column( array_filter( $wpdb->prepared, static fn ( array $p ): bool => str_starts_with( $p['query'], 'ALTER TABLE' ) ), 'query' );

		self::assertSame(
			array(
				'ALTER TABLE %i ADD COLUMN share_bps int(10) unsigned DEFAULT NULL',
				'ALTER TABLE %i ADD COLUMN share_source varchar(8) DEFAULT NULL',
				'ALTER TABLE %i ADD COLUMN price_decimals tinyint(3) unsigned DEFAULT NULL',
			),
			$alters
		);
	}

	public function test_up_is_idempotent(): void {
		$wpdb               = new RecordingWpdb();
		$wpdb->tables_exist = true;

		Functions\expect( 'dbDelta' )->never();

		( new AddCreatorShareModel() )->up( $wpdb );
		( new AddCreatorShareModel() )->up( $wpdb );

		self::assertSame( array(), $wpdb->added_columns );
		self::assertArrayNotHasKey( 'wp_merchboard_pricing_overrides', $wpdb->dropped_tables, 'An overrides table with markup_bps is kept.' );
	}

	public function test_up_fails_when_a_column_cannot_be_added(): void {
		$wpdb                = new RecordingWpdb();
		$wpdb->tables_exist  = true;
		$wpdb->columns_exist = false;
		// PREPARED#0 is the first column check, PREPARED#1 its ALTER.
		$wpdb->query_results = array( 'PREPARED#1' => false );

		Functions\expect( 'dbDelta' )->never();

		$this->expectException( DatabaseException::class );

		( new AddCreatorShareModel() )->up( $wpdb );
	}

	public function test_a_failed_drop_of_the_fixed_profit_table_never_turns_its_rows_into_0_percent_markups(): void {
		$wpdb                = new RecordingWpdb();
		$wpdb->tables_exist  = true;
		$wpdb->columns_exist = false;
		// PREPARED#0–8: the three share columns (check, ALTER) and their verification; #9 the
		// markup_bps check of the overrides table; #10 its DROP, which fails here.
		$wpdb->query_results = array( 'PREPARED#10' => false );

		Functions\expect( 'dbDelta' )->never();

		try {
			( new AddCreatorShareModel() )->up( $wpdb );
			self::fail( 'Expected a DatabaseException.' );
		} catch ( DatabaseException ) {
			self::assertSame( 'DROP TABLE IF EXISTS %i', $wpdb->prepared[10]['query'] ?? null );
			self::assertSame( array( 'wp_merchboard_pricing_overrides' ), $wpdb->prepared[10]['args'] );
			self::assertArrayNotHasKey( 'wp_merchboard_pricing_overrides.markup_bps', $wpdb->added_columns, 'The old table is never altered.' );
		}
	}

	public function test_up_fails_when_the_product_markup_table_is_not_created(): void {
		$wpdb                = new RecordingWpdb();
		$wpdb->tables_exist  = true;
		$wpdb->columns_exist = false;

		Functions\when( 'dbDelta' )->justReturn( array() );

		$this->expectException( DatabaseException::class );

		( new AddCreatorShareModel() )->up( $wpdb );
	}

	/**
	 * ALTER statements on a table.
	 *
	 * @param RecordingWpdb $wpdb  Database stand-in.
	 * @param string        $table Unprefixed table.
	 * @return list<array{query: string, args: array<mixed>}>
	 */
	private static function alters_of( RecordingWpdb $wpdb, string $table ): array {
		return array_values(
			array_filter(
				$wpdb->prepared,
				static fn ( array $p ): bool => str_starts_with( $p['query'], 'ALTER TABLE' ) && ( $p['args'][0] ?? null ) === 'wp_3_' . $table
			)
		);
	}
}
