<?php
/**
 * Installs a fresh WordPress into the test database. Runs in its own process
 * (started by bootstrap.php) because WordPress cannot be installed and then
 * loaded normally in the same request.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

require __DIR__ . '/config.php';

// Create the test database if it does not exist yet (before WordPress connects).
mysqli_report( MYSQLI_REPORT_OFF );
$merchboard_host = explode( ':', DB_HOST )[0];
$merchboard_link = mysqli_connect( $merchboard_host, DB_USER, DB_PASSWORD, '', MERCHBOARD_TEST_DB_PORT );

if ( false === $merchboard_link ) {
	fwrite( STDERR, 'Cannot connect to the test database server: ' . mysqli_connect_error() . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI test harness.
	exit( 1 );
}

mysqli_query( $merchboard_link, 'CREATE DATABASE IF NOT EXISTS `' . str_replace( '`', '', DB_NAME ) . '`' );
mysqli_close( $merchboard_link );

define( 'WP_INSTALLING', true );

require ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

global $wpdb;

// Start from an empty site: drop every table of the test prefix.
$wpdb->suppress_errors();
$merchboard_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );

foreach ( $merchboard_tables as $merchboard_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $merchboard_table ) );
}

$wpdb->suppress_errors( false );

wp_install( 'Merch Dashboard integration tests', 'admin', 'admin@example.org', false, '', 'password' );
