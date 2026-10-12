<?php
/**
 * Warns on the Plugins screen before a deletion would be refused.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Lifecycle;

use MerchBoard\Core\Settings\Settings;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * While the plugin is still active (a deactivated plugin runs no code): on
 * the Plugins screen, for users who may delete plugins, with permanent
 * deletion switched on and payouts pending or not countable, says that
 * deleting the plugin now deletes nothing and is refused (ADR-059). Costs
 * nothing on other screens.
 */
final class UninstallNotice {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Prints the notice (admin_notices).
	 */
	public function render(): void {
		global $pagenow;

		if ( 'plugins.php' !== $pagenow || ! current_user_can( 'delete_plugins' ) || ! Settings::delete_data_on_uninstall() ) {
			return;
		}

		$pending = Uninstaller::pending_payouts( $this->wpdb );

		if ( 0 === $pending ) {
			return;
		}

		$reason = null === $pending
			? __( 'Merch Dashboard could not check whether payouts are pending.', 'merchboard' )
			/* translators: %d: number of pending payouts. */
			: sprintf( _n( '%d Merch Dashboard payout is pending.', '%d Merch Dashboard payouts are pending.', $pending, 'merchboard' ), $pending );

		echo '<div class="notice notice-warning"><p>' . esc_html( $reason ) . ' ' . esc_html__( 'Permanent deletion is switched on, but deleting Merch Dashboard now deletes nothing and is refused: handle the pending payouts under Merch Dashboard → Payouts first.', 'merchboard' ) . '</p></div>';
	}
}
