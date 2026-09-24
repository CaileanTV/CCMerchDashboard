<?php
/**
 * Constants that the integration test harness defines at runtime
 * (tests/Integration/config.php), declared for PHPStan only.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

foreach ( array(
	'MERCHBOARD_TEST_LOG'     => '',
	'MERCHBOARD_TEST_DB_PORT' => 3306,
	'DB_NAME'                 => '',
	'DB_USER'                 => '',
	'DB_PASSWORD'             => '',
) as $merchboard_name => $merchboard_value ) {
	if ( ! defined( $merchboard_name ) ) {
		define( $merchboard_name, $merchboard_value );
	}
}
