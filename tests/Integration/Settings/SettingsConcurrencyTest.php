<?php
/**
 * Settings compare-and-swap against two independent database connections.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Settings;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\ProductDesigner\DesignerSettings;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use wpdb;

/**
 * A designer save must not restore an alert administrator's old setting.
 */
final class SettingsConcurrencyTest extends IntegrationTestCase {

	public function test_concurrent_alert_commit_rejects_designer_snapshot(): void {
		$this->install();
		update_option( Settings::OPTION, Settings::defaults(), true );
		$other  = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$latest = array_merge( Settings::defaults(), array( Settings::ALERTS_ENABLED => true ) );
		$filter = function ( mixed $value ) use ( $other, $latest ): mixed {
			self::assertSame( 1, $other->update( $this->wpdb->options, array( 'option_value' => maybe_serialize( $latest ) ), array( 'option_name' => Settings::OPTION ) ) );
			return $value;
		};
		add_filter( 'pre_update_option_' . Settings::OPTION, $filter );
		try {
			$service = new DesignerSettings( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
			try {
				$service->save( array( Settings::DESIGNER_ENABLED => true ) );
				self::fail( 'Expected stale settings to be rejected.' );
			} catch ( DatabaseException $e ) {
				self::assertSame( 'Could not save settings.', $e->getMessage() );
			}
			self::assertTrue( Settings::alerts_enabled() );
			self::assertFalse( Settings::designer_enabled() );
			self::assertSame( 0, $this->count_rows( Tables::AUDIT_LOG ) );
		} finally {
			remove_filter( 'pre_update_option_' . Settings::OPTION, $filter );
			$other->close();
			delete_option( Settings::OPTION );
		}
	}
}
