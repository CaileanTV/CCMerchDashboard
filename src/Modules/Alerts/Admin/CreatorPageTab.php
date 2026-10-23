<?php
/**
 * "Creator page" tab of the Alerts admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The setup of the creator's overlay settings page (step 25.9.6): the tab
 * only prints the root element and loads build/alerts-page-admin.js, which
 * moves the cards and their fields (`/admin/alerts/creator-page`,
 * `merchboard_manage_alerts`).
 */
final class CreatorPageTab {

	/**
	 * The tab's key in the page URL.
	 */
	public const TAB = 'creator-page';

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
		echo '<p>' . esc_html__( 'How the creators\' page "Overlay settings" looks: drag the cards into full width at the top or the left or right column, and the fields of a card into the order you want, or use the buttons. Every card and field stays on the page; the designer is always last. Changes are saved automatically and apply to every creator.', 'merchboard' ) . '</p>';

		if ( ! $this->bundle->enqueue( 'alerts-page-admin' ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The creator page setup could not be loaded. Please try again later.', 'merchboard' ) . '</p></div>';

			return;
		}

		echo '<div class="merchboard-app merchboard-page-setup" style="min-height:0;background:transparent"><div id="merchboard-page-setup-root"></div></div>';
	}
}
