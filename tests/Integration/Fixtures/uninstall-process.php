<?php
/**
 * A separate "request" for UninstallTest, so `WP_UNINSTALL_PLUGIN` (which
 * cannot be undefined) stays out of the test process. Prints a JSON result.
 *
 * Usage: php uninstall-process.php <mode>
 * - unloaded:     WordPress without the plugin (the wp-admin flow: the plugin
 *                 is deactivated before it is deleted), then uninstall.php.
 * - loaded-other: the plugin is loaded (its guard is registered) while
 *                 WordPress uninstalls another plugin; tries to delete the
 *                 link meta of every post.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

require dirname( __DIR__ ) . '/config.php';

$merchboard_mode = (string) ( $argv[1] ?? '' );
$merchboard_file = dirname( __DIR__, 3 ) . '/merchboard.php';

if ( 'loaded-other' === $merchboard_mode ) {
	// Loaded as the test bootstrap loads it (must-use).
	$GLOBALS['wp_filter'] = array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Pre-initialized hooks, as in the test bootstrap.
		'muplugins_loaded' => array(
			10 => array(
				array(
					'function'      => static function () use ( $merchboard_file ): void {
						require $merchboard_file;
					},
					'accepted_args' => 0,
				),
			),
		),
	);
}

require ABSPATH . 'wp-settings.php';

$merchboard_result = array( 'guard' => false !== has_filter( 'delete_post_metadata' ) );

if ( 'unloaded' === $merchboard_mode ) {
	define( 'WP_UNINSTALL_PLUGIN', plugin_basename( $merchboard_file ) );
	include dirname( __DIR__, 3 ) . '/uninstall.php';
	$merchboard_result['uninstalled'] = true;
} elseif ( 'loaded-other' === $merchboard_mode ) {
	define( 'WP_UNINSTALL_PLUGIN', 'other-plugin/other-plugin.php' );
	$merchboard_result['deleted_link']   = delete_post_meta_by_key( '_merchboard_creator_id' );
	$merchboard_result['deleted_source'] = delete_post_meta_by_key( '_merchboard_creator_link_source' );
}

echo wp_json_encode( $merchboard_result );
