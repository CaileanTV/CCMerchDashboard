<?php
/**
 * Settings write synchronization regressions.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Settings;

use Brain\Monkey\Functions;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\ProductDesigner\DesignerSettings;
use MerchBoard\Tests\Unit\Support\FakeDatabase;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\TestCase;

/**
 * Exercises the permission boundary with stale WordPress option caches.
 */
final class SettingsWriteSnapshotTest extends TestCase {

	public function test_designer_save_preserves_alert_change_despite_stale_cache(): void {
		$options = InMemoryOptions::install( array( Settings::OPTION => array( Settings::ALERTS_ENABLED => true ) ) );
		// This request cached alerts=off before the alert administrator committed.
		Functions\when( 'get_option' )->justReturn( array( Settings::ALERTS_ENABLED => false ) );
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => 'merchboard_manage_designer' === $cap );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-11-07 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$db      = new FakeDatabase();
		$service = new DesignerSettings( new AuditTrail( new AuditRepository( $db ) ), new Transaction( $db, new TransactionSupport( $db ) ) );

		self::assertSame( array( Settings::DESIGNER_ENABLED ), $service->save( array( Settings::DESIGNER_ENABLED => true ) ) );
		self::assertTrue( $options->options[ Settings::OPTION ][ Settings::ALERTS_ENABLED ] );
		self::assertTrue( $options->options[ Settings::OPTION ][ Settings::DESIGNER_ENABLED ] );
	}

	public function test_concurrent_change_rejects_stale_save_and_rolls_back_audit(): void {
		$options = InMemoryOptions::install( array( Settings::OPTION => Settings::defaults() ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-11-07 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$db                              = new FakeDatabase();
		$service                         = new DesignerSettings( new AuditTrail( new AuditRepository( $db ) ), new Transaction( $db, new TransactionSupport( $db ) ) );
		$write                           = $GLOBALS['wpdb']->query_callback;
		$GLOBALS['wpdb']->query_callback = static function ( array $prepared ) use ( $write, $options ): int|false {
			$options->options[ Settings::OPTION ][ Settings::ALERTS_ENABLED ] = true;
			return $write( $prepared );
		};
		try {
			$service->save( array( Settings::DESIGNER_ENABLED => true ) );
			self::fail( 'Expected stale save to fail.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not save settings.', $e->getMessage() );
		}
		self::assertTrue( $options->options[ Settings::OPTION ][ Settings::ALERTS_ENABLED ] );
		self::assertFalse( $options->options[ Settings::OPTION ][ Settings::DESIGNER_ENABLED ] );
		self::assertSame( 'ROLLBACK', end( $db->transaction_log ) );
		self::assertSame( array(), $db->data['wp_merchboard_audit_log'] ?? array() );
	}

	public function test_concurrent_creation_is_not_replaced(): void {
		$options = InMemoryOptions::install();
		Settings::with_write_snapshot(
			static function () use ( $options ): void {
				$options->options[ Settings::OPTION ] = array( Settings::ALERTS_ENABLED => true );
				self::assertFalse( Settings::save( Settings::defaults() ) );
			}
		);
		self::assertTrue( $options->options[ Settings::OPTION ][ Settings::ALERTS_ENABLED ] );
	}

	public function test_save_without_snapshot_is_rejected(): void {
		$this->expectException( DatabaseException::class );
		Settings::save( Settings::defaults() );
	}
}
