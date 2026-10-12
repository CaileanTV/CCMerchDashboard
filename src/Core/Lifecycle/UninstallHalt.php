<?php
/**
 * Ends a plugin deletion that the uninstall blocked.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Lifecycle;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress offers no way to refuse the deletion of one plugin: delete_plugins()
 * ignores what uninstall.php returns and deletes the files afterwards. The only
 * way to keep them is to end the request inside uninstall.php (ADR-059), with
 * the mechanism of the request that runs it:
 *
 * - WP-CLI: WP_CLI::error() (exit code 1). Plugins named after this one in the
 *   same command are not handled.
 * - The Plugins screen (one `delete-plugin` AJAX request per plugin, also for
 *   the bulk action): wp_send_json_error() with the fields of WordPress' own
 *   wp_ajax_delete_plugin(), so wp.updates.deletePluginError() shows the
 *   message in the plugin's row. Other plugins have their own requests.
 * - Any other request (plugins.php without JavaScript deletes all selected
 *   plugins in one request): wp_die() with a back link. Plugins after this
 *   one in that request are not handled.
 *
 * Whether a message reaches the administrator depends on that interface; the
 * plugin staying in the list does not. The text is English: the plugin's
 * translations are not loaded during uninstall.
 */
final class UninstallHalt {

	/**
	 * Error code in the AJAX response.
	 */
	public const ERROR_CODE = 'merchboard_pending_payouts';

	/**
	 * Ends the request; never returns.
	 *
	 * @param UninstallBlocked $blocked Why.
	 * @param string           $plugin  The plugin's basename (WP_UNINSTALL_PLUGIN).
	 */
	public static function respond( UninstallBlocked $blocked, string $plugin ): void {
		$message = self::message( $blocked );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI', false ) ) {
			\WP_CLI::error( $message );
		}

		if ( wp_doing_ajax() ) {
			wp_send_json_error(
				array(
					'delete'       => 'plugin',
					'slug'         => dirname( $plugin ),
					'plugin'       => $plugin,
					'pluginName'   => 'Merch Dashboard',
					'errorCode'    => self::ERROR_CODE,
					'errorMessage' => esc_html( $message ),
				)
			);
		}

		wp_die(
			esc_html( $message ),
			esc_html__( 'Merch Dashboard was not deleted', 'merchboard' ),
			array(
				'response'  => 409,
				'back_link' => true,
			)
		);
	}

	/**
	 * The message for the administrator.
	 *
	 * @param UninstallBlocked $blocked Why.
	 */
	public static function message( UninstallBlocked $blocked ): string {
		$reason = null === $blocked->pending
			? __( 'Merch Dashboard was not deleted: it could not check whether payouts are pending.', 'merchboard' )
			/* translators: %d: number of pending payouts. */
			: sprintf( _n( 'Merch Dashboard was not deleted: %d payout is still pending.', 'Merch Dashboard was not deleted: %d payouts are still pending.', $blocked->pending, 'merchboard' ), $blocked->pending );

		return $reason . ' ' . __( 'Nothing was deleted: its data is still in the database, and permanent deletion is still switched on. Activate Merch Dashboard again, handle the pending payouts under Merch Dashboard → Payouts, then delete the plugin again.', 'merchboard' );
	}
}
