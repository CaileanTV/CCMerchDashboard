<?php
/**
 * A React bundle of the Alerts admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Loads a bundle of build/ (script with its dependencies, style and Danish
 * strings) for a tab that mounts the creator dashboard's overlay cards: the
 * global overlay (step 25.9.4) and the standard overlays (step 25.9.5).
 */
final class AdminBundle {

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
	 * Enqueues a bundle; false when it is missing (npm run build).
	 *
	 * @param string $name Bundle name in build/ (e.g. alerts-global-admin).
	 */
	public function enqueue( string $name ): bool {
		$file  = $this->plugin_dir . 'build/' . $name . '.asset.php';
		$asset = is_readable( $file ) && is_readable( $this->plugin_dir . 'build/' . $name . '.js' ) ? require $file : null;

		if ( ! is_array( $asset ) || ! is_string( $asset['version'] ?? null ) || ! is_array( $asset['dependencies'] ?? null ) ) {
			$this->logger->error( 'An alerts admin bundle is missing or invalid; run npm run build.', array( 'file' => 'build/' . $name . '.asset.php' ) );

			return false;
		}

		$dependencies = array();

		foreach ( $asset['dependencies'] as $dependency ) {
			if ( is_string( $dependency ) && '' !== $dependency ) {
				$dependencies[] = $dependency;
			}
		}

		$handle = 'merchboard-' . $name;

		wp_enqueue_script( $handle, $this->plugin_url . 'build/' . $name . '.js', $dependencies, $asset['version'], true );
		wp_enqueue_style( $handle, $this->plugin_url . 'build/' . $name . '.css', array(), $asset['version'] );
		wp_set_script_translations( $handle, 'merchboard', $this->plugin_dir . 'languages' );

		return true;
	}
}
