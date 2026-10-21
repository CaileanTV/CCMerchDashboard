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
	 * Saves the company name and contact e-mail, which fill `[COMPANY]` and
	 * `[EMAIL]` in the YouTube chatbot's privacy policy and terms (ADR-071).
	 * Each changed value is audited (`settings_updated`).
	 *
	 * @param string $name  Company name ('' to clear).
	 * @param string $email Contact e-mail ('' to clear).
	 * @return bool Whether anything changed.
	 * @throws AlertSettingsException When not allowed or a value is invalid.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function set_company_details( string $name, string $email ): bool {
		$this->authorize();
		$name  = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
		$email = trim( $email );

		self::require_valid( Settings::valid_company_name( $name ), AlertSettingsException::INVALID_COMPANY_NAME );
		self::require_valid( Settings::valid_company_email( $email ), AlertSettingsException::INVALID_COMPANY_EMAIL );

		$settings = Settings::all();
		$changes  = array_filter(
			array(
				Settings::COMPANY_NAME  => $name,
				Settings::COMPANY_EMAIL => $email,
			),
			static fn( string $value, string $key ): bool => $settings[ $key ] !== $value,
			ARRAY_FILTER_USE_BOTH
		);

		if ( array() === $changes ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $settings, $changes ): void {
				foreach ( $changes as $key => $value ) {
					$this->audit->record(
						AuditAction::SettingsUpdated,
						null,
						array(
							'setting'        => $key,
							'previous_value' => $settings[ $key ],
							'value'          => $value,
						)
					);
				}

				if ( ! Settings::save( array_merge( $settings, $changes ) ) ) {
					throw new DatabaseException( 'Could not save settings.' );
				}
			}
		);

		return true;
	}

	/**
	 * Requires a valid value.
	 *
	 * @param bool   $valid  Whether it is valid.
	 * @param string $reason Reason when not.
	 * @throws AlertSettingsException When it is not.
	 */
	private static function require_valid( bool $valid, string $reason ): void {
		if ( ! $valid ) {
			throw new AlertSettingsException( $reason );
		}
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
