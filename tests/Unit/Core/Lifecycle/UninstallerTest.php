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
use MerchBoard\Core\Security\ProtectedProductMeta;
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
		Functions\expect( 'delete_post_meta_by_key' )->never();
		Functions\expect( 'delete_metadata' )->never();

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
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_creatormeta' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_creator_categories' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_sales' ),
				),
				array(
					'query' => 'DROP TABLE IF EXISTS %i',
					'args'  => array( 'wp_3_merchboard_sales_cycles' ),
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
		Functions\when( 'delete_post_meta_by_key' )->justReturn( true );
		Functions\when( 'delete_metadata' )->justReturn( true );
		$this->wpdb->query_results['PREPARED#0'] = false;

		self::assertFalse( $this->uninstaller()->run() );

		self::assertArrayHasKey( Settings::OPTION, $options->options );
		self::assertArrayHasKey( 'merchboard_db_version', $options->options );
	}

	public function test_with_opt_in_the_protected_product_meta_is_deleted_by_key_before_tables_and_settings(): void {
		$options = InMemoryOptions::install( array( Settings::OPTION => array( Settings::DELETE_DATA_ON_UNINSTALL => true ) ) );
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'wp_roles' )->justReturn( (object) array( 'role_objects' => array() ) );
		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'delete_metadata' )->justReturn( true );
		$deleted = array();
		Functions\when( 'delete_post_meta_by_key' )->alias(
			function ( string $key ) use ( &$deleted, $options ): bool {
				$deleted[] = array(
					'key'          => $key,
					'opt_in_still' => Settings::delete_data_on_uninstall(),
					'tables_left'  => array() === $this->wpdb->prepared,
					'settings'     => array_key_exists( Settings::OPTION, $options->options ),
				);
				return false;
			}
		);

		self::assertTrue( $this->uninstaller()->run(), 'Nothing to delete is not a failure.' );

		self::assertSame(
			array(
				array(
					'key'          => ProtectedProductMeta::LINK_KEY,
					'opt_in_still' => true,
					'tables_left'  => true,
					'settings'     => true,
				),
				array(
					'key'          => ProtectedProductMeta::SOURCE_KEY,
					'opt_in_still' => true,
					'tables_left'  => true,
					'settings'     => true,
				),
				array(
					'key'          => '_merchboard_ledger_failure',
					'opt_in_still' => true,
					'tables_left'  => true,
					'settings'     => true,
				),
			),
			$deleted,
			'Both product keys and the order failure marker (legacy post meta), through the meta API, while the opt-in is on and before the tables are dropped.'
		);
		self::assertArrayNotHasKey( Settings::OPTION, $options->options, 'The opt-in is removed last.' );
	}

	public function test_with_opt_in_the_theme_user_meta_is_deleted_for_every_user_by_exact_key(): void {
		$options = InMemoryOptions::install( array( Settings::OPTION => array( Settings::DELETE_DATA_ON_UNINSTALL => true ) ) );
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'wp_roles' )->justReturn( (object) array( 'role_objects' => array() ) );
		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'delete_post_meta_by_key' )->justReturn( true );
		$calls = array();
		Functions\when( 'delete_metadata' )->alias(
			static function ( string $type, int $object_id, string $key, mixed $value, bool $all ) use ( &$calls, $options ): bool {
				$calls[] = array( $type, $object_id, $key, $value, $all, array_key_exists( Settings::OPTION, $options->options ) );
				return false;
			}
		);

		self::assertTrue( $this->uninstaller()->run(), 'Nothing to delete is not a failure.' );

		self::assertSame( array( 'merchboard_dashboard_theme' ), Uninstaller::USER_META_KEYS );
		self::assertSame(
			array( array( 'user', 0, 'merchboard_dashboard_theme', '', true, true ) ),
			$calls,
			'Exact key, every user, through the meta API, while the opt-in still exists.'
		);
	}

	private function uninstaller(): Uninstaller {
		return new Uninstaller( $this->wpdb, new SchemaEraser( $this->wpdb, $this->logger ), $this->logger );
	}
}
