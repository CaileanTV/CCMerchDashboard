<?php
/**
 * The "Alerts" admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\AdminMenu;
use MerchBoard\Modules\Alerts\Fonts\AlertFont;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Fonts\AlertFontsException;

defined( 'ABSPATH' ) || exit;

/**
 * "Merch Dashboard → Alerts" (ADR-065 pkt. 7), for `merchboard_manage_alerts`.
 * Phase 25 step 5 adds the tab "Fonts" (ADR-067): the Google Fonts API key,
 * the catalogue and the approved fonts. The other tabs (order log, global
 * overlay, chatbot, import) follow in later steps. Read-only rendering;
 * every change goes through AlertsAdminActions. Everything is escaped.
 */
final class AlertsAdminPage {

	public const SLUG = 'merchboard-alerts';

	/**
	 * `admin_menu` priority: after "Recalculations" (26), before "Audit log" (30).
	 */
	public const PRIORITY = 27;

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
	 * Adds the submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Merch Dashboard Alerts', 'merchboard' ),
			__( 'Alerts', 'merchboard' ),
			Capabilities::MANAGE_ALERTS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * URL of a tab with an outcome code.
	 *
	 * @param string $tab    Tab.
	 * @param string $result Outcome code ('' for none).
	 */
	public static function url( string $tab = 'fonts', string $result = '' ): string {
		$args = array(
			'page' => self::SLUG,
			'tab'  => $tab,
		);

		if ( '' !== $result ) {
			$args[ AlertsAdminActions::RESULT_ARG ] = $result;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'key_saved'                              => array( __( 'The Google Fonts API key was saved.', 'merchboard' ), 'success' ),
			'key_removed'                            => array( __( 'The Google Fonts API key was removed.', 'merchboard' ), 'success' ),
			'synced'                                 => array( __( 'The Google Fonts catalogue was fetched.', 'merchboard' ), 'success' ),
			'approved'                               => array( __( 'The font was approved. Creators can choose it now.', 'merchboard' ), 'success' ),
			'removed'                                => array( __( 'The font was removed. Overlays that used it show the default font.', 'merchboard' ), 'success' ),
			'no_changes'                             => array( __( 'Nothing was changed.', 'merchboard' ), 'info' ),
			AlertFontsException::NOT_AUTHORIZED      => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			AlertFontsException::INVALID_KEY         => array( __( 'Google did not accept the API key. Check the key and try again.', 'merchboard' ), 'error' ),
			AlertFontsException::NO_KEY              => array( __( 'Save a Google Fonts API key first.', 'merchboard' ), 'error' ),
			AlertFontsException::UNAVAILABLE         => array( __( 'Google Fonts could not be reached. Try again later.', 'merchboard' ), 'error' ),
			AlertFontsException::UNKNOWN_FONT        => array( __( 'The font is not in the catalogue, or the limit of approved fonts is reached.', 'merchboard' ), 'error' ),
			'error'                                  => array( __( 'A technical error occurred. Nothing was changed.', 'merchboard' ), 'error' ),
			default                                  => null,
		};
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage alerts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only parameters; nothing is changed by this request.
		$code   = isset( $_GET[ AlertsAdminActions::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ AlertsAdminActions::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A search of the view.
		$notice = self::result_message( $code );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Alerts', 'merchboard' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper"><a class="nav-tab nav-tab-active" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Fonts', 'merchboard' ) . '</a></nav>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<p>' . esc_html__( 'Creators can use the approved fonts in their alert overlay. The fonts are loaded from Google\'s servers in the creator dashboard and in OBS, which sends the viewer\'s IP address to Google (ADR-067).', 'merchboard' ) . '</p>';

		$this->render_key();
		$this->render_approved();
		$this->render_catalog( $search );
		echo '</div>';
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

		if ( array() === $approved ) {
			echo '<p>' . esc_html__( 'No fonts are approved. Creators can only use the default font.', 'merchboard' ) . '</p>';
			return;
		}

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
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="fonts">';
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
