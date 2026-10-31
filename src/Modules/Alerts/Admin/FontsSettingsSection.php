<?php
/**
 * The fonts on the settings screen.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\Fonts\AlertFont;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Settings\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * "Settings → Fonts" (ADR-067; general since 2026-10-31): the Google Fonts API
 * key, the catalogue and the approved fonts, shared by the alert overlays and
 * the product designer. For `merchboard_manage_alerts`. Read-only rendering;
 * every change goes through AlertsAdminActions. Everything is escaped.
 */
final class FontsSettingsSection {

	/**
	 * The settings tab of the section.
	 */
	public const TAB = 'fonts';

	/**
	 * Catalogue rows shown per search.
	 */
	public const SEARCH_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param AlertFonts $fonts Font library.
	 */
	public function __construct( private AlertFonts $fonts ) {
	}

	/**
	 * Registers the section on the settings screen.
	 */
	public function register(): void {
		SettingsPage::add_tab( self::TAB, static fn(): string => __( 'Fonts', 'merchboard' ) );
		add_action( 'merchboard_settings_sections', array( $this, 'render' ) );
	}

	/**
	 * The section's URL with an outcome code.
	 *
	 * @param string $result Outcome code ('' for none).
	 */
	public static function url( string $result = '' ): string {
		$url = SettingsPage::url( '', self::TAB );

		return '' === $result ? $url : add_query_arg( array( AlertsAdminActions::RESULT_ARG => $result ), $url );
	}

	/**
	 * Renders the section.
	 *
	 * @param mixed $tab The open settings tab (only its own renders).
	 */
	public function render( mixed $tab = self::TAB ): void {
		if ( self::TAB !== $tab || ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			return;
		}

		// Read-only parameters; nothing is changed by this request.
		$code   = isset( $_GET[ AlertsAdminActions::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ AlertsAdminActions::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = AlertsAdminPage::result_message( $code );

		echo '<h2>' . esc_html__( 'Fonts', 'merchboard' ) . '</h2>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<p>' . esc_html__( 'Creators can use the approved fonts in their alert overlays and in the product designer. A set of standard fonts is ready without a key; approve more from the catalogue. The fonts are loaded from Google\'s servers in the creator dashboard and in the browser source, which shows the visitor\'s IP address to Google.', 'merchboard' ) . '</p>';

		$this->render_key();
		$this->render_approved();
		$this->render_catalog( $search );
	}

	/**
	 * The API key form.
	 */
	private function render_key(): void {
		echo '<h2>' . esc_html__( 'Google Fonts API key', 'merchboard' ) . '</h2>';
		echo '<p>' . esc_html( $this->fonts->has_key() ? __( 'A key is saved. It is never shown again.', 'merchboard' ) : __( 'No key is saved. The key is only needed to fetch the catalogue.', 'merchboard' ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertsAdminActions::KEY_ACTION ) . '">';
		wp_nonce_field( AlertsAdminActions::KEY_ACTION );
		echo '<label for="merchboard-alert-fonts-key" class="screen-reader-text">' . esc_html__( 'API key', 'merchboard' ) . '</label>';
		echo '<input type="password" id="merchboard-alert-fonts-key" name="key" class="regular-text" autocomplete="off" spellcheck="false"> ';
		submit_button( __( 'Save key', 'merchboard' ), 'secondary', 'submit', false );

		if ( $this->fonts->has_key() ) {
			echo ' <button type="submit" name="remove" value="1" class="button-link button-link-delete">' . esc_html__( 'Remove key', 'merchboard' ) . '</button>';
		}

		echo '</form>';
	}

	/**
	 * The approved fonts.
	 */
	private function render_approved(): void {
		$approved = $this->fonts->approved();

		echo '<h2>' . esc_html__( 'Approved fonts', 'merchboard' ) . '</h2>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Font', 'merchboard' ) . '</th><th>' . esc_html__( 'Category', 'merchboard' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'merchboard' ) . '</span></th></tr></thead><tbody>';

		foreach ( $approved as $font ) {
			echo '<tr><td>' . esc_html( $font->family ) . '</td><td>' . esc_html( $font->category ) . '</td><td>';
			$this->font_button( AlertsAdminActions::REMOVE_ACTION, $font, __( 'Remove', 'merchboard' ) );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The catalogue with search.
	 *
	 * @param string $search Search text.
	 */
	private function render_catalog( string $search ): void {
		echo '<h2>' . esc_html__( 'Google Fonts catalogue', 'merchboard' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertsAdminActions::SYNC_ACTION ) . '">';
		wp_nonce_field( AlertsAdminActions::SYNC_ACTION );
		submit_button( $this->fonts->has_catalog() ? __( 'Fetch the catalogue again', 'merchboard' ) : __( 'Fetch the catalogue', 'merchboard' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( ! $this->fonts->has_catalog() ) {
			return;
		}

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="search-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( SettingsPage::SLUG ) . '"><input type="hidden" name="' . esc_attr( SettingsPage::TAB_ARG ) . '" value="' . esc_attr( self::TAB ) . '">';
		echo '<p><label for="merchboard-alert-fonts-search">' . esc_html__( 'Search fonts', 'merchboard' ) . '</label> ';
		echo '<input type="search" id="merchboard-alert-fonts-search" name="q" value="' . esc_attr( $search ) . '"> ';
		submit_button( __( 'Search', 'merchboard' ), 'secondary', '', false );
		echo '</p></form>';

		$approved = $this->fonts->approved();
		$fonts    = $this->fonts->catalog( $search, self::SEARCH_LIMIT );

		if ( array() === $fonts ) {
			echo '<p>' . esc_html__( 'No fonts match the search.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %d: number of fonts. */
				__( 'At most %d fonts, the most popular first.', 'merchboard' ),
				self::SEARCH_LIMIT
			)
		) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Font', 'merchboard' ) . '</th><th>' . esc_html__( 'Category', 'merchboard' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'merchboard' ) . '</span></th></tr></thead><tbody>';

		foreach ( $fonts as $font ) {
			echo '<tr><td>' . esc_html( $font->family ) . '</td><td>' . esc_html( $font->category ) . '</td><td>';

			if ( isset( $approved[ $font->family ] ) ) {
				echo esc_html__( 'Approved', 'merchboard' );
			} else {
				$this->font_button( AlertsAdminActions::APPROVE_ACTION, $font, __( 'Approve', 'merchboard' ) );
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * A one-button form for a font.
	 *
	 * @param string    $action Admin-post action.
	 * @param AlertFont $font   Font.
	 * @param string    $label  Button label.
	 */
	private function font_button( string $action, AlertFont $font, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="family" value="' . esc_attr( $font->family ) . '">';
		wp_nonce_field( AlertsAdminActions::nonce_action( $action, $font->family ) );
		submit_button( $label, 'small', 'submit', false );
		echo '</form>';
	}
}
