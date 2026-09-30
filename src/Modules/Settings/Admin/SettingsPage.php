<?php
/**
 * Settings admin screen.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Settings\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Admin\AdminMenu;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\Settings\SettingsException;
use MerchBoard\Modules\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * "Merch Dashboard → Settings": the creator dashboard slug (ADR-026) and the
 * opt-in for permanent data deletion on uninstall (ADR-025). Read-only rendering; changes go through
 * SettingsActions. Everything is escaped at output.
 *
 * Other modules add their own sections between the dashboard slug and "Data
 * on uninstall" via the action `merchboard_settings_sections` (ADR-038, S1
 * and S11). It fires only after the capability check; each section checks
 * the capability again, renders its own form, nonce and notices and escapes
 * its own output.
 */
final class SettingsPage {

	/**
	 * Admin page slug.
	 */
	public const SLUG = 'merchboard-settings';

	/**
	 * Checkbox field.
	 */
	public const FIELD_DELETE = 'delete_data_on_uninstall';

	/**
	 * Confirmation text field.
	 */
	public const FIELD_CONFIRM = 'confirm_deletion';

	/**
	 * Dashboard slug field.
	 */
	public const FIELD_SLUG = 'dashboard_slug';

	/**
	 * URL of the settings screen, optionally with a notice code.
	 *
	 * @param string $notice Notice code.
	 */
	public static function url( string $notice = '' ): string {
		$args = array( 'page' => self::SLUG );

		if ( '' !== $notice ) {
			$args[ CreatorsAdminPage::NOTICE_ARG ] = $notice;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Adds "Settings" to the Merch Dashboard menu.
	 */
	public function add_menu(): void {
		add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Merch Dashboard Settings', 'merchboard' ),
			__( 'Settings', 'merchboard' ),
			Capabilities::MANAGE_SETTINGS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Message and type for a notice code; null for unknown codes.
	 *
	 * @param string $code Notice code.
	 * @return array{0: string, 1: string}|null
	 */
	public static function notice_message( string $code ): ?array {
		return match ( $code ) {
			'saved'                                  => array( __( 'Settings saved.', 'merchboard' ), 'success' ),
			'no_changes'                             => array( __( 'No changes were made.', 'merchboard' ), 'info' ),
			SettingsException::NOT_AUTHORIZED        => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			SettingsException::CONFIRMATION_REQUIRED => array( __( 'Permanent deletion was not enabled: type DELETE in the confirmation field to enable it.', 'merchboard' ), 'error' ),
			SettingsException::INVALID_SLUG          => array( __( 'The dashboard slug was not changed: use 3-50 lowercase letters, digits and single hyphens, and not a reserved WordPress or WooCommerce path.', 'merchboard' ), 'error' ),
			SettingsException::SLUG_IN_USE           => array( __( 'The dashboard slug was not changed: a page, post, product or archive already uses it.', 'merchboard' ), 'error' ),
			'error'                                  => array( __( 'A technical error occurred. The settings were not changed.', 'merchboard' ), 'error' ),
			default                                  => null,
		};
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage Merch Dashboard settings.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only notice code; nothing is changed by this request.
		$code    = isset( $_GET[ CreatorsAdminPage::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ CreatorsAdminPage::NOTICE_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice  = self::notice_message( $code );
		$enabled = Settings::delete_data_on_uninstall();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Merch Dashboard Settings', 'merchboard' ) . '</h1>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		$this->render_dashboard_slug();

		/**
		 * Fires between the dashboard slug and "Data on uninstall" so other
		 * modules can add their own settings sections (own form, nonce, notices
		 * and escaping; ADR-038 S1).
		 */
		do_action( 'merchboard_settings_sections' );

		echo '<h2>' . esc_html__( 'Data on uninstall', 'merchboard' ) . '</h2>';
		echo '<p>' . esc_html__( 'Deactivating the plugin never deletes data. Deleting the plugin also keeps all data unless permanent deletion is enabled here.', 'merchboard' ) . '</p>';

		if ( $enabled ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Permanent deletion is ENABLED.', 'merchboard' ) . '</strong> ';
			echo esc_html__( 'When the plugin is deleted from the Plugins screen, all Merch Dashboard data is permanently removed.', 'merchboard' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Permanent deletion is off. Deleting the plugin keeps all Merch Dashboard data.', 'merchboard' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'If enabled, deleting the plugin permanently removes: all creators, the audit log, the Merch Dashboard settings and schema state, the "Merch Dashboard Creator" role and all merchboard_ capabilities. This cannot be undone.', 'merchboard' ) . '</p>';
		echo '<p>' . esc_html__( 'Never removed: WordPress users, other roles and capabilities, posts, pages, media and WooCommerce products, orders and customers.', 'merchboard' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( SettingsActions::SAVE_ACTION ) . '">';
		wp_nonce_field( SettingsActions::SAVE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Permanent deletion', 'merchboard' ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( self::FIELD_DELETE ) . '" value="1"' . checked( $enabled, true, false ) . '> ';
		echo esc_html__( 'Delete all Merch Dashboard data when the plugin is deleted', 'merchboard' ) . '</label>';
		echo '</td></tr>';

		if ( ! $enabled ) {
			echo '<tr><th scope="row"><label for="merchboard-confirm-deletion">' . esc_html__( 'Confirmation', 'merchboard' ) . '</label></th><td>';
			echo '<input type="text" class="regular-text" id="merchboard-confirm-deletion" name="' . esc_attr( self::FIELD_CONFIRM ) . '" value="" autocomplete="off">';
			/* translators: %s: the word to type, "DELETE". */
			echo '<p class="description">' . esc_html( sprintf( __( 'To enable permanent deletion, also type %s here.', 'merchboard' ), SettingsService::DELETION_CONFIRMATION ) ) . '</p>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'merchboard' ) );
		echo '</form></div>';
	}

	/**
	 * The dashboard slug form (its own form and nonce, so saving it never
	 * touches the deletion opt-in).
	 */
	private function render_dashboard_slug(): void {
		$slug = Settings::dashboard_slug();
		$url  = ( new DashboardRoute() )->url();

		echo '<h2>' . esc_html__( 'Creator dashboard', 'merchboard' ) . '</h2>';
		echo '<p>' . esc_html__( 'Creators log in and use their dashboard at this address:', 'merchboard' ) . ' <a href="' . esc_url( $url ) . '"><code>' . esc_html( $url ) . '</code></a></p>';

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Plain permalinks are active, so the slug is not used; the dashboard address above works regardless.', 'merchboard' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( SettingsActions::SAVE_SLUG_ACTION ) . '">';
		wp_nonce_field( SettingsActions::SAVE_SLUG_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="merchboard-dashboard-slug">' . esc_html__( 'Dashboard URL slug', 'merchboard' ) . '</label></th><td>';
		echo '<code>' . esc_html( trailingslashit( home_url() ) ) . '</code><input type="text" class="regular-text" id="merchboard-dashboard-slug" name="' . esc_attr( self::FIELD_SLUG ) . '" value="' . esc_attr( $slug ) . '" maxlength="50" pattern="[a-z0-9]+(-[a-z0-9]+)*" required><code>/</code>';
		echo '<p class="description">' . esc_html__( 'Lowercase letters, digits and hyphens. Changing it moves the dashboard: the old address stops working and bookmarks must be updated. Pages, posts, products and archives that already use a slug cannot be chosen.', 'merchboard' ) . '</p>';
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save dashboard URL', 'merchboard' ), 'secondary' );
		echo '</form>';
	}
}
