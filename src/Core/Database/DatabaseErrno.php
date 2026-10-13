<?php
/**
 * Reads the error number of the last statement.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use mysqli;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The server's error number of the connection's last statement (E3.6), read
 * from wpdb's mysqli handle; wpdb itself only keeps the message. Read it
 * directly after the statement: any later statement replaces it.
 *
 * Null means the number cannot be read (no mysqli connection); the caller
 * then must not assume a particular error (F-5 D-3: reconcile, never an
 * automatic `error`).
 */
final class DatabaseErrno {

	/**
	 * Error number of the last statement on $wpdb's connection, 0 after a
	 * successful one, null when it cannot be read.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public static function last( wpdb $wpdb ): ?int {
		// wpdb's $dbh is protected and only exposed through its magic getter.
		$dbh = $wpdb->__get( 'dbh' );

		return $dbh instanceof mysqli ? $dbh->errno : null;
	}
}
