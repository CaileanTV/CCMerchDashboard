<?php
/**
 * Drops the plugin's tables.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use MerchBoard\Core\Logging\Logger;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Drops exactly the tables in Tables::all() and Tables::retired() for the
 * current site's prefix, by full name (never by pattern), so no table of
 * WordPress, another plugin or another site of a multisite network can be
 * affected. Used only by the opt-in uninstall (ADR-025).
 *
 * DROP TABLE commits implicitly, so this cannot be transactional. It is
 * idempotent instead (IF EXISTS) and reports failures, so the caller can stop
 * and retry later.
 */
final class SchemaEraser {

	/**
	 * Constructor.
	 *
	 * @param wpdb   $wpdb   WordPress database object.
	 * @param Logger $logger Technical log.
	 */
	public function __construct( private wpdb $wpdb, private Logger $logger ) {
	}

	/**
	 * Drops all plugin tables that exist.
	 *
	 * @return bool False when a table could not be dropped (logged); the
	 *              remaining tables are then left alone.
	 */
	public function erase(): bool {
		$wpdb = $this->wpdb;

		foreach ( array_merge( Tables::all(), Tables::retired() ) as $table ) {
			$name = Tables::name( $wpdb, $table );
			$sql  = $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $name );

			if ( ! is_string( $sql ) || false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the result of $wpdb->prepare() above.
				$this->logger->error( 'Could not drop a plugin table during uninstall.', array( 'table' => $name ) );
				return false;
			}
		}

		return true;
	}
}
