<?php
/**
 * Tests for the opt-in uninstall and the table eraser.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Lifecycle;

use Brain\Monkey\Functions;
use MerchBoard\Core\Database\SchemaEraser;
use MerchBoard\Core\Lifecycle\Uninstaller;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Uninstaller::class )]
#[CoversClass( SchemaEraser::class )]
final class UninstallerTest extends TestCase {

	private RecordingWpdb $wpdb;

	private RecordingLogger $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb   = new RecordingWpdb();
		$this->logger = new RecordingLogger();
	}

	public function test_without_opt_in_nothing_is_touched(): void {
		$options = InMemoryOptions::install(
			array(
				'merchboard_db_version'    => 2,
				'merchboard_roles_version' => 1,
			)
		);
		Functions\expect( 'get_users' )->never();
		Functions\expect( 'wp_roles' )->never();
		Functions\expect( 'remove_role' )->never();

		self::assertFalse( $this->uninstaller()->run() );

		self::assertSame( array(), $this->wpdb->prepared, 'No table is dropped.' );
		self::assertSame( 2, $options->options['merchboard_db_version'] );
		self::assertSame( 1, $options->options['merchboard_roles_version'] );
	}

	public function test_forged_opt_in_values_delete_nothing(): void {
		InMemoryOptions::install( array( Settings::OPTION => array( Settings::DELETE_DATA_ON_UNINSTALL => '1' ) ) );
		Functions\expect( 'get_users' )->never();

		self::assertFalse( $this->uninstaller()->run() );
		self::assertSame( array(), $this->wpdb->prepared );
	}

	public function test_eraser_drops_exactly_the_plugin_tables_of_the_current_prefix(): void {
		$this->wpdb->prefix = 'wp_3_';

		self::assertTrue( ( new SchemaEraser( $this->wpdb, $this->logger ) )->erase() );

		self::assertSame(
			array(
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_creators' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_audit_log' ),
				),
			),
			$this->wpdb->prepared
		);
	}

	public function test_eraser_stops_and_logs_when_a_table_cannot_be_dropped(): void {
		$this->wpdb->query_results['PREPARED#0'] = false;

		self::assertFalse( ( new SchemaEraser( $this->wpdb, $this->logger ) )->erase() );

		self::assertCount( 1, $this->wpdb->prepared, 'The remaining tables are left alone.' );
		self::assertSame( array( 'error' ), array_column( $this->logger->entries, 'level' ) );
		self::assertSame( array( 'table' => 'wp_merchboard_creators' ), $this->logger->entries[0]['context'] );
	}

	public function test_failed_table_drop_keeps_the_options_and_the_opt_in_for_a_retry(): void {
		$options = InMemoryOptions::install(
			array(
				Settings::OPTION        => array( Settings::DELETE_DATA_ON_UNINSTALL => true ),
				'merchboard_db_version' => 2,
			)
		);
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'wp_roles' )->justReturn( (object) array( 'role_objects' => array() ) );
		Functions\when( 'remove_role' )->justReturn( null );
		$this->wpdb->query_results['PREPARED#0'] = false;

		self::assertFalse( $this->uninstaller()->run() );

		self::assertArrayHasKey( Settings::OPTION, $options->options );
		self::assertArrayHasKey( 'merchboard_db_version', $options->options );
	}

	private function uninstaller(): Uninstaller {
		return new Uninstaller( $this->wpdb, new SchemaEraser( $this->wpdb, $this->logger ), $this->logger );
	}
}
