<?php
/**
 * Constants that the integration test harness (tests/Integration/config.php)
 * or WordPress (wp_cookie_constants()) defines at runtime, declared for
 * PHPStan only.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

foreach ( array(
	'MERCHBOARD_TEST_LOG'     => '',
	'MERCHBOARD_TEST_ROOT'    => '',
	'MERCHBOARD_TEST_DB_PORT' => 3306,
	'DB_NAME'                 => '',
	'DB_USER'                 => '',
	'DB_PASSWORD'             => '',
	'LOGGED_IN_COOKIE'        => '',
) as $merchboard_name => $merchboard_value ) {
	if ( ! defined( $merchboard_name ) ) {
		define( $merchboard_name, $merchboard_value );
	}
}
