<?php
/**
 * State-changing request of the "Alerts" settings section (admin-post.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\AlertSettings;
use MerchBoard\Modules\Alerts\AlertSettingsException;
use MerchBoard\Modules\Settings\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Switches purchase alerts on or off (ADR-065 pkt. 6) and saves the
 * company details, the checkout username field and the sound limits. Order
 * of checks:
 * capability `merchboard_manage_alerts` → nonce → input (one checkbox) →
 * AlertSettings (checks the capability again, writes audit and option in
 * one transaction). Post/Redirect/Get to the settings screen with its own
 * whitelisted outcome code.
 */
final class AlertsSettingsActions {

	/**
	 * The admin-post action and nonce action.
	 */
	public const SAVE_ACTION = 'merchboard_save_alerts_settings';

	/**
	 * Query argument: outcome code after a redirect.
	 */
	public const RESULT_ARG = 'merchboard_alerts_result';

	/**
	 * The checkbox.
	 */
	public const FIELD_ENABLED = 'alerts_enabled';

	/**
	 * The admin-post action and nonce action of the company details (step 8.5).
	 */
	public const COMPANY_ACTION = 'merchboard_save_company_details';

	/**
	 * Query argument: outcome code of the company details after a redirect.
	 */
	public const COMPANY_RESULT_ARG = 'merchboard_company_result';

	/**
	 * The company name field.
	 */
	public const FIELD_COMPANY_NAME = 'company_name';

	/**
	 * The contact e-mail field.
	 */
	public const FIELD_COMPANY_EMAIL = 'company_email';

	/**
	 * The admin-post action and nonce action of the checkout username field
	 * (step 25.9.1).
	 */
	public const CHECKOUT_ACTION = 'merchboard_save_checkout_username';

	/**
	 * Query argument: outcome code of the checkout username field.
	 */
	public const CHECKOUT_RESULT_ARG = 'merchboard_checkout_result';

	/**
	 * The checkbox that shows the field.
	 */
	public const FIELD_CHECKOUT_ENABLED = 'checkout_username_enabled';

	/**
	 * The label field.
	 */
	public const FIELD_CHECKOUT_LABEL = 'checkout_username_label';

	/**
	 * The help text field.
	 */
	public const FIELD_CHECKOUT_HELP = 'checkout_username_help';

	/**
	 * The admin-post action and nonce action of the sound limits (step 25.9.2).
	 */
	public const SOUNDS_ACTION = 'merchboard_save_sound_limits';

	/**
	 * Query argument: outcome code of the sound limits.
	 */
	public const SOUNDS_RESULT_ARG = 'merchboard_sounds_result';

	/**
	 * The largest file: a whole number …
	 */
	public const FIELD_SOUND_SIZE = 'sound_max_size';

	/**
	 * … in this unit ('KB' or 'MB').
	 */
	public const FIELD_SOUND_SIZE_UNIT = 'sound_max_size_unit';

	/**
	 * The longest sound: a whole number …
	 */
	public const FIELD_SOUND_LENGTH = 'sound_max_length';

	/**
	 * … in this unit ('seconds' or 'minutes').
	 */
	public const FIELD_SOUND_LENGTH_UNIT = 'sound_max_length_unit';

	/**
	 * Bytes per size unit.
	 */
	public const SIZE_UNITS = array(
		'KB' => 1024,
		'MB' => 1048576,
	);

	/**
	 * Seconds per length unit.
	 */
	public const LENGTH_UNITS = array(
		'seconds' => 1,
		'minutes' => 60,
	);

	/**
	 * Constructor.
	 *
	 * @param AlertSettings $settings Saving.
	 * @param Logger        $logger   Technical log.
	 */
	public function __construct( private AlertSettings $settings, private Logger $logger ) {
	}

	/**
	 * URL of the settings screen with an outcome code, at the section.
	 *
	 * @param string $result Outcome code.
	 */
	public static function url( string $result ): string {
		return add_query_arg( array( self::RESULT_ARG => $result ), SettingsPage::url( '', AlertsSettingsSection::TAB ) ) . '#merchboard-alerts';
	}

	/**
	 * Registers the admin-post handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save_request' ) );
		add_action( 'admin_post_' . self::COMPANY_ACTION, array( $this, 'handle_company_request' ) );
		add_action( 'admin_post_' . self::CHECKOUT_ACTION, array( $this, 'handle_checkout_request' ) );
		add_action( 'admin_post_' . self::SOUNDS_ACTION, array( $this, 'handle_sounds_request' ) );
	}

	/**
	 * URL of the settings screen with an outcome code, at the sound limits.
	 *
	 * @param string $result Outcome code.
	 */
	public static function sounds_url( string $result ): string {
		return add_query_arg( array( self::SOUNDS_RESULT_ARG => $result ), SettingsPage::url( '', AlertsSettingsSection::TAB ) ) . '#merchboard-sound-limits';
	}

	/**
	 * WordPress entry point of the sound limits.
	 */
	public function handle_sounds_request(): void {
		// The nonce is verified in save_sounds() before any input is used.
		wp_safe_redirect( $this->save_sounds( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and the fields validated in save_sounds() and AlertSettings.
		exit;
	}

	/**
	 * Processes the sound limits form: a whole number and a unit each,
	 * turned into bytes and seconds (anything else is out of range).
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function save_sounds( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage alerts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$field = static fn( string $key ): string => is_scalar( $input[ $key ] ?? null ) ? trim( sanitize_text_field( (string) $input[ $key ] ) ) : '';

		if ( 1 !== wp_verify_nonce( $field( '_wpnonce' ), self::SOUNDS_ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			$changed = $this->settings->set_sound_limits(
				self::amount( $field( self::FIELD_SOUND_SIZE ), self::SIZE_UNITS[ $field( self::FIELD_SOUND_SIZE_UNIT ) ] ?? 0 ),
				self::amount( $field( self::FIELD_SOUND_LENGTH ), self::LENGTH_UNITS[ $field( self::FIELD_SOUND_LENGTH_UNIT ) ] ?? 0 )
			);
		} catch ( AlertSettingsException $e ) {
			return self::sounds_url( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Saving the sound limits failed.', array_merge( $e->context(), array( 'reason' => $e->getMessage() ) ) );

			return self::sounds_url( 'error' );
		}

		return self::sounds_url( $changed ? 'saved' : 'no_changes' );
	}

	/**
	 * A whole number times its unit, or 0 when it is not a whole number
	 * (at most 7 digits, so the product cannot overflow).
	 *
	 * @param string $number Whole number.
	 * @param int    $unit   Unit factor.
	 */
	public static function amount( string $number, int $unit ): int {
		return 1 === preg_match( '/^\d{1,7}$/', $number ) ? (int) $number * $unit : 0;
	}

	/**
	 * URL of the settings screen with an outcome code, at the checkout field.
	 *
	 * @param string $result Outcome code.
	 */
	public static function checkout_url( string $result ): string {
		return add_query_arg( array( self::CHECKOUT_RESULT_ARG => $result ), SettingsPage::url( '', AlertsSettingsSection::TAB ) ) . '#merchboard-checkout-username';
	}

	/**
	 * WordPress entry point of the checkout username field.
	 */
	public function handle_checkout_request(): void {
		// The nonce is verified in save_checkout() before any input is used.
		wp_safe_redirect( $this->save_checkout( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and the fields validated in save_checkout() and AlertSettings.
		exit;
	}

	/**
	 * Processes the checkout username form.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function save_checkout( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage alerts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$field = static fn( string $key ): string => is_scalar( $input[ $key ] ?? null ) ? sanitize_text_field( (string) $input[ $key ] ) : '';

		if ( 1 !== wp_verify_nonce( $field( '_wpnonce' ), self::CHECKOUT_ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			$changed = $this->settings->set_checkout_username( '1' === $field( self::FIELD_CHECKOUT_ENABLED ), $field( self::FIELD_CHECKOUT_LABEL ), $field( self::FIELD_CHECKOUT_HELP ) );
		} catch ( AlertSettingsException $e ) {
			return self::checkout_url( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Saving the checkout username field failed.', array_merge( $e->context(), array( 'reason' => $e->getMessage() ) ) );

			return self::checkout_url( 'error' );
		}

		return self::checkout_url( $changed ? 'saved' : 'no_changes' );
	}

	/**
	 * URL of the settings screen with an outcome code, at the company details.
	 *
	 * @param string $result Outcome code.
	 */
	public static function company_url( string $result ): string {
		return add_query_arg( array( self::COMPANY_RESULT_ARG => $result ), SettingsPage::url() ) . '#merchboard-company';
	}

	/**
	 * WordPress entry point of the company details.
	 */
	public function handle_company_request(): void {
		// The nonce is verified in save_company() before any input is used.
		wp_safe_redirect( $this->save_company( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and the fields validated in save_company() and AlertSettings.
		exit;
	}

	/**
	 * Processes the company details form.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function save_company( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage alerts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$field = static fn( string $key ): string => is_scalar( $input[ $key ] ?? null ) ? sanitize_text_field( (string) $input[ $key ] ) : '';

		if ( 1 !== wp_verify_nonce( $field( '_wpnonce' ), self::COMPANY_ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			$changed = $this->settings->set_company_details( $field( self::FIELD_COMPANY_NAME ), $field( self::FIELD_COMPANY_EMAIL ) );
		} catch ( AlertSettingsException $e ) {
			return self::company_url( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Saving the company details failed.', array_merge( $e->context(), array( 'reason' => $e->getMessage() ) ) );

			return self::company_url( 'error' );
		}

		return self::company_url( $changed ? 'saved' : 'no_changes' );
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_save_request(): void {
		// The nonce is verified in save() before any input is used.
		wp_safe_redirect( $this->save( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and the field read as a flag in save().
		exit;
	}

	/**
	 * Processes the form.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function save( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage alerts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$nonce = is_scalar( $input['_wpnonce'] ?? null ) ? (string) $input['_wpnonce'] : '';

		if ( 1 !== wp_verify_nonce( $nonce, self::SAVE_ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$enabled = '1' === ( is_scalar( $input[ self::FIELD_ENABLED ] ?? null ) ? (string) $input[ self::FIELD_ENABLED ] : '' );

		try {
			$changed = $this->settings->set_enabled( $enabled );
		} catch ( AlertSettingsException $e ) {
			return self::url( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error(
				'Saving the alert settings failed.',
				array_merge(
					$e->context(),
					array( 'reason' => $e->getMessage() )
				)
			);

			return self::url( 'error' );
		}

		if ( ! $changed ) {
			return self::url( 'no_changes' );
		}

		return self::url( $enabled ? 'enabled' : 'disabled' );
	}
}
