<?php
/**
 * Schema version 28: keeps the alert API working for the creators who use it (ADR-073, step 25.12.4).
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
 * From step 25.12.4 a creator needs the administrator's access to use API
 * keys and webhooks (off by default). So that nothing stops working, a
 * creator who already has a working key gets access to API keys, and one
 * who already has a webhook gets access to webhooks (the project owner's
 * choice 2026-10-24): creator meta `alert_api_access` (`{ keys, webhooks }`,
 * serialized like the metadata API does). A creator who already has the row
 * keeps it. Data only; no table changes.
 *
 * Idempotent: running it again finds the rows and changes nothing.
 */
final class GrantExistingAlertApiAccess implements Migration {

	/**
	 * The creator meta key (CreatorMetaStore::ALERT_API_ACCESS).
	 */
	private const META_KEY = 'alert_api_access';

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When the database cannot be read or written.
	 */
	public function up( wpdb $wpdb ): void {
		$keys     = self::creators( $wpdb, $wpdb->prepare( 'SELECT DISTINCT creator_id FROM %i WHERE revoked_at IS NULL', Tables::name( $wpdb, Tables::ALERT_API_KEYS ) ) );
		$webhooks = self::creators( $wpdb, $wpdb->prepare( 'SELECT DISTINCT creator_id FROM %i', Tables::name( $wpdb, Tables::ALERT_WEBHOOKS ) ) );
		$meta     = Tables::name( $wpdb, Tables::CREATOR_META );

		foreach ( array_unique( array_merge( $keys, $webhooks ) ) as $creator_id ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_id FROM %i WHERE merchboard_creator_id = %d AND meta_key = %s LIMIT 1', $meta, $creator_id, self::META_KEY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A custom table.

			if ( '' !== $wpdb->last_error ) {
				throw new DatabaseException( 'Could not read the creator meta.' );
			}

			if ( null !== $exists ) {
				continue;
			}

			$value = serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The metadata API's own format, read back with maybe_unserialize().
				array(
					'keys'     => in_array( $creator_id, $keys, true ),
					'webhooks' => in_array( $creator_id, $webhooks, true ),
				)
			);

			if ( false === $wpdb->insert( $meta, array( 'merchboard_creator_id' => $creator_id, 'meta_key' => self::META_KEY, 'meta_value' => $value ), array( '%d', '%s', '%s' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- A custom table; one row per creator.
				throw new DatabaseException( 'Could not keep the alert API access.' );
			}

			// The metadata API's cache of the creator (as CreatorMetaStore).
			wp_cache_delete( $creator_id, 'merchboard_creator_meta' );
		}
	}

	/**
	 * The creator ids of a prepared query.
	 *
	 * @param wpdb        $wpdb WordPress database object.
	 * @param string|null $sql  Prepared query.
	 * @return list<int>
	 * @throws DatabaseException When it cannot be read.
	 */
	private static function creators( wpdb $wpdb, ?string $sql ): array {
		$ids = null === $sql ? false : $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; a custom table.

		if ( ! is_array( $ids ) || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the alert API users.' );
		}

		return array_map( 'intval', $ids );
	}
}
