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
