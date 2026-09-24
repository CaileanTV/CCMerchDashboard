<?php
/**
 * One of several concurrent "requests" in MigrationTest: waits for a common
 * start time, then runs the real Migrator with a slow migration that counts
 * how often it runs. Prints "migrated" or "locked".
 *
 * Usage: php migrate-contender.php <start unix time with microseconds>
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

require dirname( __DIR__ ) . '/config.php';
require ABSPATH . 'wp-settings.php';
require_once dirname( __DIR__, 3 ) . '/src/Core/Autoloader.php';

MerchBoard\Core\Autoloader::register( 'MerchBoard\\', dirname( __DIR__, 3 ) . '/src/' );

$merchboard_start = (float) ( $argv[1] ?? 0 );

while ( microtime( true ) < $merchboard_start ) {
	usleep( 1000 );
}

$merchboard_migrator = new MerchBoard\Core\Database\Migrator(
	$GLOBALS['wpdb'],
	array(
		1 => new class() implements MerchBoard\Core\Database\Migration {
			/**
			 * Counts the run and holds the lock long enough for the others to collide.
			 *
			 * @param wpdb $wpdb Database.
			 */
			public function up( wpdb $wpdb ): void {
				$sql = $wpdb->prepare( 'UPDATE %i SET option_value = option_value + 1 WHERE option_name = %s', $wpdb->options, 'merchboard_test_runs' );

				if ( is_string( $sql ) ) {
					$wpdb->query( $sql );
				}

				usleep( 1500000 );
			}
		},
	)
);

echo $merchboard_migrator->migrate() ? 'migrated' : 'locked';
