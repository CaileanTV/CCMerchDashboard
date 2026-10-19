<?php
/**
 * Script and style of the overlay page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

use MerchBoard\Core\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The overlay bundle (`build/overlay.js` and `build/overlay.css`, built
 * from `assets/src/overlay/` by `npm run build`). A separate, small bundle
 * without React: OBS loads it in every scene.
 */
final class OverlayAssets {

	/**
	 * Script and style handle.
	 */
	public const HANDLE = 'merchboard-overlay';

	/**
	 * Constructor.
	 *
	 * @param string $plugin_dir Plugin directory (with trailing slash).
	 * @param string $plugin_url Plugin URL (with trailing slash).
	 * @param Logger $logger     Technical log.
	 */
	public function __construct( private string $plugin_dir, private string $plugin_url, private Logger $logger ) {
	}

	/**
	 * Registers script and style.
	 *
	 * @return bool Whether the build exists and was registered.
	 */
	public function register(): bool {
		$file  = $this->plugin_dir . 'build/overlay.asset.php';
		$asset = is_readable( $file ) && is_readable( $this->plugin_dir . 'build/overlay.js' ) ? require $file : null;

		if ( ! is_array( $asset ) || ! is_string( $asset['version'] ?? null ) || ! is_array( $asset['dependencies'] ?? null ) ) {
			$this->logger->error( 'Alert overlay assets are missing or invalid; run npm run build.', array( 'file' => 'build/overlay.asset.php' ) );
			return false;
		}

		$dependencies = array_values( array_filter( $asset['dependencies'], static fn( mixed $handle ): bool => is_string( $handle ) && '' !== $handle ) );

		wp_register_script( self::HANDLE, $this->plugin_url . 'build/overlay.js', $dependencies, $asset['version'], true );
		wp_register_style( self::HANDLE, $this->plugin_url . 'build/overlay.css', array(), $asset['version'] );

		return true;
	}
}
