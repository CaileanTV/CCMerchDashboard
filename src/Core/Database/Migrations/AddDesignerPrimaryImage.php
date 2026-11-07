<?php
/**
 * Migration 47: the product image a creator chooses, and longer provider task keys (phase 34).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database\Migrations;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migration;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-096:
 * - `merchboard_designer_versions.primary_image` (varchar(64), NULL by
 *   default) is the mockup the creator chose as the product's image,
 *   `<side>:<colour>`. Existing versions have none and keep the order they
 *   had.
 * - `merchboard_designer_provider_tasks.side` grows to varchar(48): a task of
 *   a colour design adds `@d1` … to the side's key (32 characters at most).
 *
 * Idempotent: the column is added only when missing, and a column that holds
 * 48 characters or more is not changed.
 */
final class AddDesignerPrimaryImage implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a column is missing or too short afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		$versions = Tables::name( $wpdb, Tables::DESIGNER_VERSIONS );
		$tasks    = Tables::name( $wpdb, Tables::DESIGNER_PROVIDER_TASKS );

		if ( null === self::type( $wpdb, $versions, 'primary_image' ) ) {
			$wpdb->query( "ALTER TABLE `{$versions}` ADD COLUMN `primary_image` varchar(64) NULL DEFAULT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Constant table name from Tables; versioned migration.
		}

		if ( self::too_short( self::type( $wpdb, $tasks, 'side' ) ) ) {
			$wpdb->query( "ALTER TABLE `{$tasks}` MODIFY `side` varchar(48) NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Constant table name from Tables; versioned migration.
		}

		if ( null === self::type( $wpdb, $versions, 'primary_image' ) || self::too_short( self::type( $wpdb, $tasks, 'side' ) ) ) {
			throw new DatabaseException( 'Could not add the product image of designs.' );
		}
	}

	/**
	 * Whether a column type holds fewer than 48 characters (or is missing).
	 *
	 * @param string|null $type Column type, lower case.
	 */
	private static function too_short( ?string $type ): bool {
		if ( null === $type ) {
			return true;
		}

		return 1 === preg_match( '/^(?:var)?char\((\d+)\)$/', $type, $match ) && (int) $match[1] < 48;
	}

	/**
	 * The type of a column, lower case, or null when it is missing.
	 *
	 * @param wpdb   $wpdb   WordPress database object.
	 * @param string $table  Full table name.
	 * @param string $column Column.
	 */
	private static function type( wpdb $wpdb, string $table, string $column ): ?string {
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ), ARRAY_A );

		return is_array( $row ) && is_string( $row['Type'] ?? null ) ? strtolower( $row['Type'] ) : null;
	}
}
