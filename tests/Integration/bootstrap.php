<?php
/**
 * PHPUnit bootstrap for integration tests: a real WordPress on a real
 * MySQL/MariaDB with the plugin loaded as a must-use plugin would be.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require __DIR__ . '/config.php';

file_put_contents( MERCHBOARD_TEST_LOG, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Runs before WordPress is loaded.

// Fresh WordPress install in a separate process.
passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/install.php' ), $merchboard_status );

if ( 0 !== $merchboard_status ) {
	fwrite( STDERR, "Installing the WordPress test site failed.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI test harness.
	exit( 1 );
}

// Hooks registered before WordPress loads (WP_Hook::build_preinitialized_hooks format).
$GLOBALS['wp_filter'] = array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Pre-initialized hooks, as in WordPress' own test suite.
	'muplugins_loaded'  => array(
		10 => array(
			array(
				'function'      => static function (): void {
					require MERCHBOARD_TEST_ROOT . '/merchboard.php';
				},
				'accepted_args' => 0,
			),
		),
	),
	'merchboard_loaded' => array(
		10 => array(
			array(
				'function'      => static function ( MerchBoard\Core\Container $container ): void {
					MerchBoard\Tests\Integration\IntegrationTestCase::$container = $container;
				},
				'accepted_args' => 1,
			),
		),
	),
);

require ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;

// Record which database engine the results apply to.
fwrite( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI test harness.
	STDERR,
	sprintf(
		"Integration database: %s | default engine %s | charset %s | collation %s | sql_mode (WordPress session) %s\n",
		(string) $wpdb->get_var( 'SELECT VERSION()' ),
		(string) $wpdb->get_var( 'SELECT @@default_storage_engine' ),
		$wpdb->charset,
		$wpdb->collate,
		(string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' )
	)
);
