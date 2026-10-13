<?php
/**
 * Checks the storage engine of the options table.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The schema version row lives in WordPress' `options` table, which WordPress
 * creates without an ENGINE. Its shared lock (R0, E3.3) only exists on
 * InnoDB, so the G1 activation refuses any other engine (fail closed): a
 * failed read, a missing table or an unknown engine all count as "not
 * InnoDB".
 */
final class OptionsTableEngine {

	/**
	 * Whether the options table is an InnoDB table.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public static function is_innodb( wpdb $wpdb ): bool {
		$sql = $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->options );

		if ( null === $sql ) {
			return false;
		}

		$engine = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the result of $wpdb->prepare().

		return '' === $wpdb->last_error && is_string( $engine ) && 'innodb' === strtolower( $engine );
	}
}
