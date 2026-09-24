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
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Settings\SettingsException;
use MerchBoard\Modules\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * "Merch Dashboard → Settings": for now only the opt-in for permanent data
 * deletion on uninstall (ADR-025). Read-only rendering; changes go through
 * SettingsActions. Everything is escaped at output.
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
			CreatorsAdminPage::SLUG,
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
}
