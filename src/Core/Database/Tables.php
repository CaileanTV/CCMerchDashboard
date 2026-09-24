<?php
/**
 * Registry of the plugin's database tables.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use InvalidArgumentException;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The only place where table names are built.
 *
 * Table names can never come from input: `name()` accepts only the constants
 * below, so a caller cannot make a repository read an arbitrary table.
 */
final class Tables {

	/**
	 * Creators (see docs/DATABASE.md).
	 */
	public const CREATORS = 'merchboard_creators';

	/**
	 * Audit trail of business changes (see docs/DATABASE.md, ADR-023).
	 */
	public const AUDIT_LOG = 'merchboard_audit_log';

	/**
	 * All tables owned by the plugin.
	 */
	private const ALL = array(
		self::CREATORS,
		self::AUDIT_LOG,
	);

	/**
	 * Returns the full, site-prefixed table name.
	 *
	 * @param wpdb   $wpdb  WordPress database object (provides the site prefix).
	 * @param string $table One of the class constants.
	 * @throws InvalidArgumentException When the table is not owned by the plugin.
	 */
	public static function name( wpdb $wpdb, string $table ): string {
		if ( ! in_array( $table, self::ALL, true ) ) {
			throw new InvalidArgumentException( 'Unknown table.' );
		}

		return $wpdb->prefix . $table;
	}

	/**
	 * Unprefixed names of all plugin tables.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return self::ALL;
	}
}
