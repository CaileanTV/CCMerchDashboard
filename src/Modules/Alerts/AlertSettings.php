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

		return $this->save_changes(
			array(
				Settings::COMPANY_NAME  => $name,
				Settings::COMPANY_EMAIL => $email,
			)
		);
	}

	/**
	 * Saves the checkout username field (ADR-065 pkt. 9, step 25.9.1):
	 * whether it is shown, its label and its help text ('' for the
	 * defaults). Each changed value is audited (`settings_updated`).
	 *
	 * @param bool   $enabled Whether the field is shown in the checkout.
	 * @param string $label   Label ('' for the default).
	 * @param string $help    Help text ('' for the default).
	 * @return bool Whether anything changed.
	 * @throws AlertSettingsException When not allowed or a text is invalid.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function set_checkout_username( bool $enabled, string $label, string $help ): bool {
		$this->authorize();
		$label = trim( (string) preg_replace( '/\s+/u', ' ', $label ) );
		$help  = trim( (string) preg_replace( '/\s+/u', ' ', $help ) );

		self::require_valid( Settings::valid_line( $label, Settings::CHECKOUT_USERNAME_LABEL_MAX_LENGTH ), AlertSettingsException::INVALID_CHECKOUT_LABEL );
		self::require_valid( Settings::valid_line( $help, Settings::CHECKOUT_USERNAME_HELP_MAX_LENGTH ), AlertSettingsException::INVALID_CHECKOUT_HELP );

		return $this->save_changes(
			array(
				Settings::CHECKOUT_USERNAME_ENABLED => $enabled,
				Settings::CHECKOUT_USERNAME_LABEL   => $label,
				Settings::CHECKOUT_USERNAME_HELP    => $help,
			)
		);
	}

	/**
	 * Saves the limits of uploaded alert sounds (step 25.9.2): the largest
	 * file in bytes and the longest sound in seconds, within
	 * Settings::SOUND_MAX_BYTES_RANGE and SOUND_MAX_SECONDS_RANGE. They apply
	 * to new uploads (creators' sounds and the library); stored sounds stay.
	 * Each changed value is audited (`settings_updated`).
	 *
	 * @param int $max_bytes   Largest file in bytes.
	 * @param int $max_seconds Longest sound in seconds.
	 * @return bool Whether anything changed.
	 * @throws AlertSettingsException When not allowed or a limit is out of range.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function set_sound_limits( int $max_bytes, int $max_seconds ): bool {
		$this->authorize();

		self::require_valid( Settings::valid_sound_max_bytes( $max_bytes ), AlertSettingsException::INVALID_SOUND_SIZE );
		self::require_valid( Settings::valid_sound_max_seconds( $max_seconds ), AlertSettingsException::INVALID_SOUND_LENGTH );

		return $this->save_changes(
			array(
				Settings::SOUND_MAX_BYTES   => $max_bytes,
				Settings::SOUND_MAX_SECONDS => $max_seconds,
			)
		);
	}

	/**
	 * Saves the changed values: one audit record (`settings_updated`) per
	 * changed value and the option, in one transaction.
	 *
	 * @param array<string, string|bool|int> $values Values by settings key.
	 * @return bool Whether anything changed.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	private function save_changes( array $values ): bool {
		$settings = Settings::all();
		$changes  = array_filter(
			$values,
			static fn( string|bool|int $value, string $key ): bool => ( $settings[ $key ] ?? null ) !== $value,
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
							'previous_value' => $settings[ $key ] ?? null,
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
