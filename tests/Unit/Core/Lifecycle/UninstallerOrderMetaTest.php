<?php
/**
 * Tests for the opt-in uninstall of the sales ledger failure marker with WooCommerce loaded.
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
use MerchBoard\Tests\Unit\Support\WcOrderStorage;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[CoversClass( Uninstaller::class )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class UninstallerOrderMetaTest extends TestCase {

	private const KEY = '_merchboard_ledger_failure';

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/Fixtures/woocommerce-order.php';
		WcOrderStorage::reset();

		InMemoryOptions::install( array( Settings::OPTION => array( Settings::DELETE_DATA_ON_UNINSTALL => true ) ) );
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'wp_roles' )->justReturn( (object) array( 'role_objects' => array() ) );
		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'delete_metadata' )->justReturn( true );
	}

	public function test_hpos_markers_are_removed_through_crud_in_batches_with_the_order_save_suppressed(): void {
		$deleted = array();
		Functions\when( 'delete_post_meta_by_key' )->alias(
			static function ( string $key ) use ( &$deleted ): bool {
				$deleted[] = $key;
				return true;
			}
		);

		for ( $id = 1; $id <= 250; $id++ ) {
			WcOrderStorage::add_order( $id, 'completed' );
			WcOrderStorage::$meta[ $id ] = array( '_other' => 'kept' );

			if ( 0 === $id % 2 ) {
				WcOrderStorage::$meta[ $id ][ self::KEY ] = array( 'v' => 1 );
			}
		}

		$wpdb   = new RecordingWpdb();
		$logger = new RecordingLogger();

		self::assertTrue( ( new Uninstaller( $wpdb, new SchemaEraser( $wpdb, $logger ), $logger ) )->run() );

		self::assertContains( self::KEY, $deleted, 'Legacy post meta through the meta API.' );
		self::assertCount( 250, WcOrderStorage::$orders, 'Orders stay.' );

		foreach ( WcOrderStorage::$meta as $meta ) {
			self::assertSame( array( '_other' => 'kept' ), $meta, 'Only the marker is removed.' );
		}

		self::assertCount( 125, WcOrderStorage::$saves, 'One meta save per marked order.' );
		self::assertSame( array( true ), array_values( array_unique( WcOrderStorage::$saves ) ), 'Every save with HPOS\' order save suppressed.' );
		self::assertFalse( has_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', '__return_false' ) );
		self::assertSame( array( 100, 100, 100 ), array_column( WcOrderStorage::$queries, 'limit' ), 'Batches of 100 until none is left.' );

		foreach ( WcOrderStorage::$queries as $query ) {
			// Only orders with the key are loaded: legacy storage ignores `meta_query` (Q4 runtime finding).
			self::assertSame( array( self::KEY, 'EXISTS' ), array( $query['meta_key'] ?? null, $query['meta_compare'] ?? null ) );
			self::assertArrayNotHasKey( 'meta_query', $query );
		}
		self::assertSame( array(), $logger->at( 'warning' ) );
	}
}
