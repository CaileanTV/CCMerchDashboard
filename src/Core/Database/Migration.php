<?php
/**
 * Contract for schema migrations.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database;

use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * One forward-only schema step. The schema version it produces is its key in
 * Schema::migrations(), not a property of the migration.
 *
 * Migrations must be idempotent: if a run fails after the change was applied
 * but before the version was saved, the migration runs again.
 */
interface Migration {

	/**
	 * Applies the change.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When the change could not be applied or verified.
	 */
	public function up( wpdb $wpdb ): void;
}
