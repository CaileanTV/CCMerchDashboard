<?php
/**
 * "Global overlay" tab of the Alerts admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Logging\Logger;

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
	 * Script and style handle.
	 */
	public const HANDLE = 'merchboard-alerts-global-admin';

	/**
	 * Constructor.
	 *
	 * @param string $plugin_dir Plugin directory with a trailing slash.
	 * @param string $plugin_url Plugin URL with a trailing slash.
	 * @param Logger $logger     Technical log.
	 */
	public function __construct( private string $plugin_dir, private string $plugin_url, private Logger $logger ) {
	}

	/**
	 * Renders the tab.
	 */
	public function render(): void {
		echo '<p>' . esc_html__( 'An overlay of its own for a browser source in OBS, independent of every creator: it shows the alerts of all creators, with its own URL, settings, design, sounds and images. Nothing here changes a creator\'s overlay.', 'merchboard' ) . '</p>';

		if ( ! $this->enqueue() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The global overlay could not be loaded. Please try again later.', 'merchboard' ) . '</p></div>';

			return;
		}

		echo '<div class="merchboard-app merchboard-global-overlay" style="min-height:0;background:transparent"><div id="merchboard-global-overlay-root"></div></div>';
	}

	/**
	 * Loads the tab's script and style.
	 */
	private function enqueue(): bool {
		$file  = $this->plugin_dir . 'build/alerts-global-admin.asset.php';
		$asset = is_readable( $file ) && is_readable( $this->plugin_dir . 'build/alerts-global-admin.js' ) ? require $file : null;

		if ( ! is_array( $asset ) || ! is_string( $asset['version'] ?? null ) || ! is_array( $asset['dependencies'] ?? null ) ) {
			$this->logger->error( 'The global overlay assets are missing or invalid; run npm run build.', array( 'file' => 'build/alerts-global-admin.asset.php' ) );

			return false;
		}

		$dependencies = array();

		foreach ( $asset['dependencies'] as $dependency ) {
			if ( is_string( $dependency ) && '' !== $dependency ) {
				$dependencies[] = $dependency;
			}
		}

		wp_enqueue_script( self::HANDLE, $this->plugin_url . 'build/alerts-global-admin.js', $dependencies, $asset['version'], true );
		wp_enqueue_style( self::HANDLE, $this->plugin_url . 'build/alerts-global-admin.css', array(), $asset['version'] );
		wp_set_script_translations( self::HANDLE, 'merchboard', $this->plugin_dir . 'languages' );

		return true;
	}
}
