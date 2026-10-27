<?php
/**
 * WordPress configuration for the integration tests (the wp-config.php
 * equivalent), shared by install.php and bootstrap.php.
 *
 * Connection data comes only from MERCHBOARD_TEST_DB_* environment variables
 * (set by bin/integration.mjs or CI); nothing is read from other projects.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

$merchboard_env = static function ( string $name, ?string $fallback = null ): string {
	$value = getenv( 'MERCHBOARD_TEST_DB_' . $name );

	if ( false === $value ) {
		if ( null === $fallback ) {
			fwrite( STDERR, "MERCHBOARD_TEST_DB_{$name} is not set. Run the integration tests with `npm run test:integration` (see docs/TESTING.md).\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI test harness.
			exit( 1 );
		}

		return $fallback;
	}

	return $value;
};

define( 'MERCHBOARD_TEST_ROOT', dirname( __DIR__, 2 ) );
define( 'MERCHBOARD_TEST_DB_PORT', (int) $merchboard_env( 'PORT', '3306' ) );

define( 'DB_NAME', $merchboard_env( 'NAME', 'merchboard_tests' ) );
define( 'DB_USER', $merchboard_env( 'USER' ) );
define( 'DB_PASSWORD', $merchboard_env( 'PASSWORD', '' ) );
define( 'DB_HOST', $merchboard_env( 'HOST' ) . ':' . MERCHBOARD_TEST_DB_PORT );
// WordPress' defaults: utf8mb4 and the server's best matching collation.
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

// Tables of the test site only; install.php drops everything with this prefix.
$table_prefix = 'mbtest_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress reads this global.

define( 'ABSPATH', MERCHBOARD_TEST_ROOT . '/vendor/roots/wordpress-no-content/' );
define( 'WP_CONTENT_DIR', MERCHBOARD_TEST_ROOT . '/.tools/wp-content' );
define( 'WP_HOME', 'http://merchboard.test' );
define( 'WP_SITEURL', 'http://merchboard.test' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', true );
define( 'WP_DEBUG_LOG', false );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );

// Values WordPress expects from a web request.
$_SERVER['HTTP_HOST']       = 'merchboard.test';
$_SERVER['SERVER_NAME']     = 'merchboard.test';
$_SERVER['SERVER_PORT']     = '80';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

// wp-content without themes; WordPress only registers an existing theme directory.
if ( ! is_dir( WP_CONTENT_DIR . '/themes' ) ) {
	mkdir( WP_CONTENT_DIR . '/themes', 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Runs before WordPress is loaded.
}

// The plugin's technical log (error_log backend without WooCommerce) goes to a
// file that tests can inspect instead of cluttering the test output.
define( 'MERCHBOARD_TEST_LOG', WP_CONTENT_DIR . '/debug.log' );
ini_set( 'error_log', MERCHBOARD_TEST_LOG ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test process only.

// No mail from the test site (pluggable; defined before WordPress loads).
if ( ! function_exists( 'wp_mail' ) ) {
	/**
	 * Keeps mail in $GLOBALS['merchboard_test_mails'] instead of sending it.
	 *
	 * @param string|string[] $to          Recipients.
	 * @param string          $subject     Subject.
	 * @param string          $message     Body.
	 * @param string|string[] $headers     Headers.
	 * @param string|string[] $attachments Attachments.
	 * @param string|string[] $embeds      Embedded files.
	 * @return bool
	 */
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array(), $embeds = array() ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress' signature.
		$GLOBALS['merchboard_test_mails'][] = array(
			'to'      => $to,
			'subject' => $subject,
			'message' => $message,
			'headers' => $headers,
		);

		return true;
	}
}
