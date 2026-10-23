<?php
/**
 * "Global overlay" tab of the Alerts admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The global overlay (ADR-065 pkt. 7, step 25.9.4): the tab only prints the
 * root element and loads build/alerts-global-admin.js, which mounts the
 * creator dashboard's overlay cards with the global overlay's own REST
 * routes (`/admin/alerts/global-overlay`, `merchboard_manage_alerts`).
 */
final class GlobalOverlayTab {

	/**
	 * The tab's key in the page URL.
	 */
	public const TAB = 'global-overlay';

	/**
	 * Constructor.
	 *
	 * @param AdminBundle $bundle Loads the tab's script.
	 */
	public function __construct( private AdminBundle $bundle ) {
	}

	/**
	 * Renders the tab.
	 */
	public function render(): void {
		echo '<p>' . esc_html__( 'An overlay of its own for a browser source in OBS, independent of every creator: it shows the alerts of all creators, with its own URL, settings, design, sounds and images. Nothing here changes a creator\'s overlay.', 'merchboard' ) . '</p>';

		if ( ! $this->bundle->enqueue( 'alerts-global-admin' ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The global overlay could not be loaded. Please try again later.', 'merchboard' ) . '</p></div>';

			return;
		}

		echo '<div class="merchboard-app merchboard-global-overlay" style="min-height:0;background:transparent"><div id="merchboard-global-overlay-root"></div></div>';
	}
}
