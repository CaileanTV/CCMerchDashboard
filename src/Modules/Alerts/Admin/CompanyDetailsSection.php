<?php
/**
 * "Company details" section on the settings screen.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\AlertSettingsException;
use MerchBoard\Modules\Alerts\Legal\CompanyDetails;
use MerchBoard\Modules\Settings\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * The details that fill the placeholders of the YouTube chatbot's privacy
 * policy and terms (phase 25, step 8.5, ADR-071), rendered on "Merch
 * Dashboard → Settings" via `merchboard_settings_sections` for users with
 * `merchboard_manage_alerts`. The company name and contact e-mail are edited
 * here; the CVR number (Payouts section) and the address (WooCommerce's store
 * address) are shown where they come from. Every change goes through
 * AlertsSettingsActions. Everything is escaped at output.
 */
final class CompanyDetailsSection {

	/**
	 * Registers the section on the settings screen.
	 */
	public function register(): void {
		add_action( 'merchboard_settings_sections', array( $this, 'render' ) );
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'saved'                                       => array( __( 'The company details were saved.', 'merchboard' ), 'success' ),
			'no_changes'                                  => array( __( 'No changes were made to the company details.', 'merchboard' ), 'info' ),
			AlertSettingsException::INVALID_COMPANY_NAME  => array( __( 'The company name must be one line of at most 200 characters. Nothing was saved.', 'merchboard' ), 'error' ),
			AlertSettingsException::INVALID_COMPANY_EMAIL => array( __( 'The contact e-mail is not a valid e-mail address. Nothing was saved.', 'merchboard' ), 'error' ),
			AlertSettingsException::NOT_AUTHORIZED        => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			'error'                                       => array( __( 'A technical error occurred. The company details were not changed.', 'merchboard' ), 'error' ),
			default                                       => null,
		};
	}

	/**
	 * Renders the section.
	 *
	 * @param mixed $tab The open settings tab (only its own renders).
	 */
	public function render( mixed $tab = SettingsPage::GENERAL ): void {
		if ( SettingsPage::GENERAL !== $tab ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			return;
		}

		// Read-only outcome code; nothing is changed by this request.
		$code    = isset( $_GET[ AlertsSettingsActions::COMPANY_RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ AlertsSettingsActions::COMPANY_RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice  = self::result_message( $code );
		$cvr     = Settings::company_cvr();
		$address = CompanyDetails::address();

		echo '<h2 id="merchboard-company">' . esc_html__( 'Company details', 'merchboard' ) . '</h2>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<p>' . esc_html__( 'These details fill the placeholders of the YouTube chatbot\'s privacy policy and terms ([COMPANY], [CVR], [ADDRESS], [EMAIL]) when the pages are shown.', 'merchboard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertsSettingsActions::COMPANY_ACTION ) . '">';
		wp_nonce_field( AlertsSettingsActions::COMPANY_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="merchboard-company-name">%1$s</label></th><td><input type="text" id="merchboard-company-name" name="%2$s" value="%3$s" maxlength="%4$d" class="regular-text" autocomplete="organization"><p class="description">%5$s</p></td></tr>',
			esc_html__( 'Company name', 'merchboard' ),
			esc_attr( AlertsSettingsActions::FIELD_COMPANY_NAME ),
			esc_attr( Settings::company_name() ),
			(int) Settings::COMPANY_NAME_MAX_LENGTH,
			esc_html__( 'The legal name of the business, e.g. "Merch ApS". [COMPANY]', 'merchboard' )
		);
		printf(
			'<tr><th scope="row"><label for="merchboard-company-email">%1$s</label></th><td><input type="email" id="merchboard-company-email" name="%2$s" value="%3$s" class="regular-text" autocomplete="email"><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Contact e-mail', 'merchboard' ),
			esc_attr( AlertsSettingsActions::FIELD_COMPANY_EMAIL ),
			esc_attr( Settings::company_email() ),
			esc_html__( 'Where people write about privacy and the terms. [EMAIL]', 'merchboard' )
		);
		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s<p class="description">%3$s</p></td></tr>',
			esc_html__( 'CVR number', 'merchboard' ),
			'' === $cvr ? '<em>' . esc_html__( 'Not registered', 'merchboard' ) . '</em>' : esc_html( $cvr ),
			esc_html__( 'Set in the section "CVR number on settlement documents" on this page. [CVR]', 'merchboard' )
		);
		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s<p class="description">%3$s <a href="%4$s">%5$s</a></p></td></tr>',
			esc_html__( 'Address', 'merchboard' ),
			'' === $address ? '<em>' . esc_html__( 'Not registered', 'merchboard' ) . '</em>' : esc_html( $address ),
			esc_html__( 'WooCommerce\'s store address, as on the settlement documents. [ADDRESS]', 'merchboard' ),
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ),
			esc_html__( 'Edit in WooCommerce', 'merchboard' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Save company details', 'merchboard' ), 'secondary' );
		echo '</form>';
	}
}
