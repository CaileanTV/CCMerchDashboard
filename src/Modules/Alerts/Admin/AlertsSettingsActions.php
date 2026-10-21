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
 * Switches purchase alerts on or off (ADR-065 pkt. 6). Order of checks:
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
		return add_query_arg( array( self::RESULT_ARG => $result ), SettingsPage::url() ) . '#merchboard-alerts';
	}

	/**
	 * Registers the admin-post handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save_request' ) );
		add_action( 'admin_post_' . self::COMPANY_ACTION, array( $this, 'handle_company_request' ) );
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
