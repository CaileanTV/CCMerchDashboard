<?php
/**
 * Tests for the table registry.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Database;

use InvalidArgumentException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( Tables::class )]
final class TablesTest extends TestCase {

	public function test_creators_table_uses_the_site_prefix(): void {
		$wpdb = new RecordingWpdb();

		self::assertSame( 'wp_merchboard_creators', Tables::name( $wpdb, Tables::CREATORS ) );

		$wpdb->prefix = 'wp_7_';
		self::assertSame( 'wp_7_merchboard_creators', Tables::name( $wpdb, Tables::CREATORS ) );
	}

	/**
	 * Only plugin-owned table constants are accepted, so input can never select a table.
	 *
	 * @return array<string, array{string}>
	 */
	public static function foreign_tables(): array {
		return array(
			'core users table'   => array( 'users' ),
			'prefixed core'      => array( 'wp_users' ),
			'sql injection'      => array( 'merchboard_creators; DROP TABLE wp_users' ),
			'case variant'       => array( 'MERCHBOARD_CREATORS' ),
			'unregistered table' => array( 'merchboard_sales' ),
			'empty'              => array( '' ),
		);
	}

	#[DataProvider( 'foreign_tables' )]
	public function test_rejects_tables_not_owned_by_the_plugin( string $table ): void {
		$this->expectException( InvalidArgumentException::class );

		Tables::name( new RecordingWpdb(), $table );
	}

	public function test_all_lists_every_plugin_table_with_prefix_convention(): void {
		self::assertSame( array( 'merchboard_creators' ), Tables::all() );

		foreach ( Tables::all() as $table ) {
			self::assertStringStartsWith( 'merchboard_', $table );
		}
	}
}
