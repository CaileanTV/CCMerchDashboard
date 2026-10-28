<?php
/**
 * "Product designer" section on the settings screen.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\ProductDesigner\DesignerException;

defined( 'ABSPATH' ) || exit;

/**
 * The kill switches of the product designer (ADR-080), Printful (ADR-081)
 * and Gelato (ADR-082) on "Merch Dashboard → Settings", rendered via
 * `merchboard_settings_sections` for users with `merchboard_manage_designer`.
 * All are off by default. Read-only rendering with its own form, nonce and
 * notices; every change goes through DesignerSettingsActions. Everything is
 * escaped at output.
 */
final class DesignerSettingsSection {

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
			'saved'                           => array( __( 'The product designer settings were saved.', 'merchboard' ), 'success' ),
			'no_changes'                      => array( __( 'No changes were made to the product designer settings.', 'merchboard' ), 'info' ),
			DesignerException::NOT_AUTHORIZED => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			'error'                           => array( __( 'A technical error occurred. The product designer settings were not changed.', 'merchboard' ), 'error' ),
			default                           => null,
		};
	}

	/**
	 * Renders the section.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			return;
		}

		// Read-only outcome code; nothing is changed by this request.
		$code   = isset( $_GET[ DesignerSettingsActions::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ DesignerSettingsActions::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::result_message( $code );
		$fields = array(
			Settings::DESIGNER_ENABLED => array(
				__( 'Product designer', 'merchboard' ),
				__( 'Creators can design their own products', 'merchboard' ),
				__( 'Off by default. New products and changes always wait for an administrator\'s approval. Products that were not made in the designer are never changed by it.', 'merchboard' ),
				Settings::designer_enabled(),
			),
			Settings::PRINTFUL_ENABLED => array(
				__( 'Printful', 'merchboard' ),
				__( 'Use Printful for base products and mockups', 'merchboard' ),
				__( 'Off by default. Only the catalog and the mockup generator are used; orders are not sent to Printful.', 'merchboard' ),
				Settings::printful_enabled(),
			),
			Settings::GELATO_ENABLED   => array(
				__( 'Gelato', 'merchboard' ),
				__( 'Use Gelato for base products and mockups', 'merchboard' ),
				__( 'Off by default. Only the catalog and mockups from your Gelato templates are used; orders are not sent to Gelato.', 'merchboard' ),
				Settings::gelato_enabled(),
			),
		);

		echo '<h2 id="merchboard-designer">' . esc_html__( 'Product designer', 'merchboard' ) . '</h2>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( DesignerSettingsActions::SAVE_ACTION ) . '">';
		wp_nonce_field( DesignerSettingsActions::SAVE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $fields as $key => [ $title, $label, $help, $on ] ) {
			echo '<tr><th scope="row">' . esc_html( $title ) . '</th><td>';
			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . checked( $on, true, false ) . '> ' . esc_html( $label ) . '</label>';
			echo '<p class="description">' . esc_html( $help ) . '</p>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		submit_button( __( 'Save product designer settings', 'merchboard' ), 'secondary' );
		echo '</form>';
	}
}
