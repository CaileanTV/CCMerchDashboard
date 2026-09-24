<?php
/**
 * PHPUnit bootstrap for unit tests (no WordPress loaded; WordPress functions are
 * mocked per test with Brain Monkey).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

// Satisfies the `defined( 'ABSPATH' ) || exit;` guards in plugin files.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/Fixtures/wpdb.php';
require_once __DIR__ . '/Fixtures/wp-role.php';

// Code under test reports failures with error_log(); keep test output clean.
ini_set( 'error_log', 'Windows' === PHP_OS_FAMILY ? 'NUL' : '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test process only.
