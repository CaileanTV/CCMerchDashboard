<?php
/**
 * Settings use cases.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Settings;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Changes plugin settings (ADR-025).
 *
 * - Authorization: `merchboard_manage_settings`, independent of the UI.
 * - Enabling permanent deletion additionally requires the typed confirmation
 *   word, checked server-side; disabling never needs it (always safe).
 * - Every change writes a `settings_updated` audit record. The record is
 *   written first and the option last in one transaction: if the option
 *   cannot be saved, the audit record is rolled back (fail closed without a
 *   transactional engine, ADR-024).
 */
final class SettingsService {

	/**
	 * Word an administrator must type to enable permanent deletion.
	 */
	public const DELETION_CONFIRMATION = 'DELETE';

	/**
	 * Constructor.
	 *
	 * @param AuditTrail  $audit       Audit trail.
	 * @param Transaction $transaction Database transaction.
	 */
	public function __construct( private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * Enables or disables "delete all data on uninstall".
	 *
	 * @param bool   $enabled      New value.
	 * @param string $confirmation Typed confirmation (required to enable).
	 * @return bool Whether the setting changed.
	 * @throws SettingsException When not authorized or the confirmation is missing.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function set_delete_data_on_uninstall( bool $enabled, string $confirmation = '' ): bool {
		$this->authorize();

		$settings = Settings::all();

		if ( $settings[ Settings::DELETE_DATA_ON_UNINSTALL ] === $enabled ) {
			return false;
		}

		if ( $enabled ) {
			$this->assert_confirmed( $confirmation );
		}

		$settings[ Settings::DELETE_DATA_ON_UNINSTALL ] = $enabled;

		$this->transaction->run(
			function () use ( $settings, $enabled ): void {
				$this->audit->record(
					AuditAction::SettingsUpdated,
					null,
					array(
						'setting' => Settings::DELETE_DATA_ON_UNINSTALL,
						'enabled' => $enabled,
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
	 * Requires the settings capability.
	 *
	 * @throws SettingsException When the current user lacks it.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			throw new SettingsException( SettingsException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Requires the typed confirmation word.
	 *
	 * @param string $confirmation Typed confirmation.
	 * @throws SettingsException When it does not match.
	 */
	private function assert_confirmed( string $confirmation ): void {
		if ( self::DELETION_CONFIRMATION !== trim( $confirmation ) ) {
			throw new SettingsException( SettingsException::CONFIRMATION_REQUIRED );
		}
	}
}
