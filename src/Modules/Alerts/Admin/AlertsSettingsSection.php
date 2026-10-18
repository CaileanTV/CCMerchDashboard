<?php
/**
 * "Alerts" section on the settings screen.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\AlertSettingsException;
use MerchBoard\Modules\Alerts\AlertsAvailability;

defined( 'ABSPATH' ) || exit;

/**
 * The kill switch of purchase alerts (ADR-065 pkt. 6), rendered on "Merch
 * Dashboard → Settings" via `merchboard_settings_sections` for users with
 * `merchboard_manage_alerts`. Shows whether alerts are sent now and warns
 * while MerchAlert is active, because then no alerts are sent. Read-only
 * rendering with its own form, nonce and notices; every change goes through
 * AlertsSettingsActions. Everything is escaped at output.
 */
final class AlertsSettingsSection {

	/**
	 * Constructor.
	 *
	 * @param AlertsAvailability $availability Kill switch and MerchAlert guard.
	 */
	public function __construct( private AlertsAvailability $availability ) {
	}

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
			'enabled'                                => array( __( 'Purchase alerts are switched on.', 'merchboard' ), 'success' ),
			'disabled'                               => array( __( 'Purchase alerts are switched off. No new alerts are recorded.', 'merchboard' ), 'success' ),
			'no_changes'                             => array( __( 'No changes were made to the alert settings.', 'merchboard' ), 'info' ),
			AlertSettingsException::NOT_AUTHORIZED   => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			'error'                                  => array( __( 'A technical error occurred. The alert settings were not changed.', 'merchboard' ), 'error' ),
			default                                  => null,
		};
	}

	/**
	 * Renders the section.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			return;
		}

		// Read-only outcome code; nothing is changed by this request.
		$code    = isset( $_GET[ AlertsSettingsActions::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ AlertsSettingsActions::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice  = self::result_message( $code );
		$enabled = $this->availability->enabled();
		$blocked = $this->availability->merchalert_active();

		echo '<h2 id="merchboard-alerts">' . esc_html__( 'Alerts', 'merchboard' ) . '</h2>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		if ( $blocked ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'MerchAlert is active on this site, so Merch Dashboard sends no alerts. Deactivate MerchAlert before you switch alerts on. Do not delete it: deleting MerchAlert deletes its data, which can still be imported.', 'merchboard' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'When an order is paid (processing or completed), the creator each product is attributed to gets an alert. Mapping a product category to a creator attributes its products, so the category turns the alerts on. Only active creators get alerts.', 'merchboard' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Status:', 'merchboard' ) . '</strong> ' . esc_html( self::status( $enabled, $blocked ) ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertsSettingsActions::SAVE_ACTION ) . '">';
		wp_nonce_field( AlertsSettingsActions::SAVE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Purchase alerts', 'merchboard' ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( AlertsSettingsActions::FIELD_ENABLED ) . '" value="1"' . checked( $enabled, true, false ) . '> ';
		echo esc_html__( 'Record purchase alerts for creators', 'merchboard' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default. Orders paid while alerts are off get no alerts later.', 'merchboard' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save alert settings', 'merchboard' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * The current status as a sentence.
	 *
	 * @param bool $enabled Whether alerts are switched on.
	 * @param bool $blocked Whether MerchAlert is active.
	 */
	private static function status( bool $enabled, bool $blocked ): string {
		if ( ! $enabled ) {
			return __( 'Off. No alerts are recorded.', 'merchboard' );
		}

		return $blocked
			? __( 'Switched on, but paused while MerchAlert is active.', 'merchboard' )
			: __( 'On. Alerts are recorded when orders are paid.', 'merchboard' );
	}
}
