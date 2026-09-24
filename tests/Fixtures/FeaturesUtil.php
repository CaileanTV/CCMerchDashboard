<?php
/**
 * Stand-in for WooCommerce's FeaturesUtil, loaded only in an isolated test process.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Utilities;

/**
 * Records declare_compatibility() calls.
 */
final class FeaturesUtil {

	/**
	 * Recorded calls.
	 *
	 * @var list<array{0: string, 1: string, 2: bool}>
	 */
	public static array $calls = array();

	/**
	 * Mirrors the WooCommerce signature.
	 *
	 * @param string $feature_id  Feature id.
	 * @param string $plugin_file Main plugin file.
	 * @param bool   $compatible  Whether the plugin is compatible.
	 */
	public static function declare_compatibility( string $feature_id, string $plugin_file, bool $compatible = true ): bool {
		self::$calls[] = array( $feature_id, $plugin_file, $compatible );
		return true;
	}
}
