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
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulToken;

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
	/**
	 * Constructor.
	 *
	 * @param PrintfulToken|null $token The Printful token (step 30.12).
	 */
	public function __construct( private ?PrintfulToken $token = null ) {
	}

	/**
	 * Hooks the section into the Settings page.
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
			DesignerException::PRINTFUL_TOKEN => array( __( 'The Printful token was not saved: use the token as Printful shows it (letters, digits, - and _).', 'merchboard' ), 'error' ),
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

		$configured = null !== $this->token && $this->token->configured();

		echo '<tr><th scope="row"><label for="merchboard-printful-token">' . esc_html__( 'Printful API token', 'merchboard' ) . '</label></th><td>';
		echo '<input type="password" id="merchboard-printful-token" name="printful_token" value="" class="regular-text" autocomplete="off"> ';
		echo '<span>' . esc_html( $configured ? __( 'A token is stored.', 'merchboard' ) : __( 'No token yet.', 'merchboard' ) ) . '</span>';

		if ( $configured ) {
			echo '<br><label><input type="checkbox" name="printful_token_remove" value="1"> ' . esc_html__( 'Remove the stored token', 'merchboard' ) . '</label>';
		}

		echo '<p class="description">' . esc_html__( 'A private token from the Printful Developer Portal with as few scopes as possible (the catalog and the mockup generator need no store access). It is stored encrypted and never shown again; leave the field empty to keep the stored one.', 'merchboard' ) . '</p>';
		echo '<details style="margin-top:8px"><summary>' . esc_html__( 'How to set up Printful', 'merchboard' ) . '</summary><ol>';

		foreach (
			array(
				__( 'Create an account at printful.com and a store of the type "Manual order platform / API" that is not connected to a web shop, so nothing is published or sold through Printful.', 'merchboard' ),
				__( 'In Printful\'s Developer Portal (developers.printful.com), create a private token with access to that one store and as few scopes as possible. Do not choose orders, sync products or webhooks: Merch Dashboard never creates orders or products at Printful.', 'merchboard' ),
				__( 'Paste the token above, switch Printful on and save.', 'merchboard' ),
				__( 'Import catalog products under Merch Dashboard → Base products ("Import from Printful"), check them, set the base price and activate them.', 'merchboard' ),
				__( 'Printful fetches print files from a short-lived link on this site, so mockups from Printful only work on a site Printful can reach (not on a local test site).', 'merchboard' ),
			) as $step
		) {
			echo '<li>' . esc_html( $step ) . '</li>';
		}

		echo '</ol></details>';
		echo '</td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save product designer settings', 'merchboard' ), 'secondary' );
		echo '</form>';
	}
}
