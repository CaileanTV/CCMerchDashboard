<?php
/**
 * Creator dashboard assets.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard;

use MerchBoard\Core\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the built dashboard bundle (`npm run build` → build/dashboard.*)
 * with the dependencies and version webpack wrote to dashboard.asset.php,
 * and attaches the bootstrap data (docs/FRONTEND.md).
 *
 * React, the JSX runtime and the WordPress packages are WordPress' own
 * script handles (dependency extraction); nothing is bundled twice.
 */
final class DashboardAssets {

	/**
	 * Script and style handle.
	 */
	public const HANDLE = 'merchboard-dashboard';

	/**
	 * Global the bootstrap data is assigned to.
	 */
	public const BOOTSTRAP_GLOBAL = 'merchboardDashboard';

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
	 * Registers script and style and attaches the bootstrap data.
	 *
	 * @param array<string, mixed> $bootstrap Non-sensitive bootstrap data.
	 * @return bool Whether the build exists and was registered.
	 */
	public function register( array $bootstrap ): bool {
		$asset = $this->asset();

		if ( null === $asset ) {
			$this->logger->error( 'Creator dashboard assets are missing or invalid; run npm run build.', array( 'file' => 'build/dashboard.asset.php' ) );
			return false;
		}

		wp_register_script( self::HANDLE, $this->plugin_url . 'build/dashboard.js', $asset['dependencies'], $asset['version'], true );
		wp_register_style( self::HANDLE, $this->plugin_url . 'build/dashboard.css', array(), $asset['version'] );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );

		$json = wp_json_encode( $bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			$this->logger->error( 'Creator dashboard bootstrap data could not be encoded.' );
			return false;
		}

		wp_add_inline_script( self::HANDLE, 'window.' . self::BOOTSTRAP_GLOBAL . ' = ' . $json . ';', 'before' );

		// Danish (and future) UI strings from languages/merchboard-{locale}-{md5}.json.
		wp_set_script_translations( self::HANDLE, 'merchboard', $this->plugin_dir . 'languages' );

		return true;
	}

	/**
	 * The validated asset manifest, or null.
	 *
	 * @return array{dependencies: array<int, non-empty-string>, version: string}|null
	 */
	public function asset(): ?array {
		$file = $this->plugin_dir . 'build/dashboard.asset.php';

		if ( ! is_readable( $file ) || ! is_readable( $this->plugin_dir . 'build/dashboard.js' ) ) {
			return null;
		}

		$asset = require $file;

		if ( ! is_array( $asset ) || ! isset( $asset['dependencies'], $asset['version'] ) || ! is_array( $asset['dependencies'] ) || ! is_string( $asset['version'] ) ) {
			return null;
		}

		$dependencies = array();

		foreach ( $asset['dependencies'] as $dependency ) {
			if ( ! is_string( $dependency ) || '' === $dependency ) {
				return null;
			}

			$dependencies[] = $dependency;
		}

		return array(
			'dependencies' => $dependencies,
			'version'      => $asset['version'],
		);
	}
}
