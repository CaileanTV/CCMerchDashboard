<?php
/**
 * "Standard overlays" tab of the Alerts admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The standard overlays (step 25.9.5): the tab only prints the root element
 * and loads build/alerts-presets-admin.js, which lists, makes, renames and
 * deletes them and designs one with the creator dashboard's settings form
 * and designer (`/admin/alerts/presets`, `merchboard_manage_alerts`).
 */
final class OverlayPresetsTab {

	/**
	 * The tab's key in the page URL.
	 */
	public const TAB = 'presets';

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
		echo '<p>' . esc_html__( 'Overlays you design and name, which creators can copy into their own overlay as a starting point: the look and the texts. A creator\'s sounds and URL stay, and later changes here do not change their copy.', 'merchboard' ) . '</p>';

		if ( ! $this->bundle->enqueue( 'alerts-presets-admin' ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The standard overlays could not be loaded. Please try again later.', 'merchboard' ) . '</p></div>';

			return;
		}

		echo '<div class="merchboard-app merchboard-presets" style="min-height:0;background:transparent"><div id="merchboard-presets-root"></div></div>';
	}
}
