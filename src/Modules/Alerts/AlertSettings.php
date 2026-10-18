<?php
/**
 * Changes the alert settings.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The only write path of `alerts_enabled` (ADR-065 pkt. 6). Requires
 * `merchboard_manage_alerts`, independent of the UI. An unchanged value
 * writes nothing; a change writes a `settings_updated` audit record and the
 * option in one transaction, as SettingsService does.
 */
final class AlertSettings {

	/**
	 * Constructor.
	 *
	 * @param AuditTrail  $audit       Audit trail.
	 * @param Transaction $transaction Database transaction.
	 */
	public function __construct( private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * Switches alerts on or off.
	 *
	 * @param bool $enabled Whether alerts are sent.
	 * @return bool Whether the setting changed.
	 * @throws AlertSettingsException When the current user may not manage alerts.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function set_enabled( bool $enabled ): bool {
		$this->authorize();

		$previous = Settings::alerts_enabled();

		if ( $previous === $enabled ) {
			return false;
		}

		$settings = Settings::all();

		$settings[ Settings::ALERTS_ENABLED ] = $enabled;

		$this->transaction->run(
			function () use ( $settings, $previous, $enabled ): void {
				$this->audit->record(
					AuditAction::SettingsUpdated,
					null,
					array(
						'setting'        => Settings::ALERTS_ENABLED,
						'previous_value' => $previous,
						'value'          => $enabled,
					)
				);

				if ( ! Settings::save( $settings ) ) {
					throw new DatabaseException( 'Could not save settings.' );
				}
			}
		);

		return true;
	}

	/**
	 * Requires the alerts capability (independent of the UI).
	 *
	 * @throws AlertSettingsException When the current user lacks it.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			throw new AlertSettingsException( AlertSettingsException::NOT_AUTHORIZED );
		}
	}
}
