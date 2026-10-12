<?php
/**
 * The marker of creators archived by the opt-in uninstall.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Lifecycle;

use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * The option `merchboard_restored_history` (ADR-059): written by the opt-in
 * uninstall right after it archived the kept creators, before any table is
 * dropped, with the time and the highest creator id it archived. It is the
 * only plugin option the uninstall keeps.
 *
 * The creators table is kept, so its AUTO_INCREMENT continues above that id:
 * every creator with an id up to it was archived; every creator created after
 * a reinstall has a higher id. A reinstall is supported for historical
 * documentation only; reactivating an archived creator is not supported and
 * only warned about (no block, no migration).
 *
 * The Installer never derives the marker. On a fresh install (schema version
 * 0) it only removes a marker whose creators table is missing, so new
 * creators that start at id 1 again are never shown as archived.
 */
final class RestoredHistory {

	/**
	 * Option name.
	 */
	public const OPTION = 'merchboard_restored_history';

	/**
	 * Stores the marker (replaces an earlier one; a repeated uninstall writes
	 * the same id again).
	 *
	 * @param int    $max_creator_id Highest archived creator id (≥ 1).
	 * @param string $archived_at    UTC time, MySQL format.
	 */
	public static function record( int $max_creator_id, string $archived_at ): bool {
		if ( $max_creator_id < 1 ) {
			return false;
		}

		$value = array(
			'archived_at'    => $archived_at,
			'max_creator_id' => $max_creator_id,
		);

		return get_option( self::OPTION ) === $value || update_option( self::OPTION, $value, false );
	}

	/**
	 * The highest archived creator id, or null without a valid marker.
	 */
	public static function max_creator_id(): ?int {
		$value = get_option( self::OPTION );
		$id    = is_array( $value ) ? ( $value['max_creator_id'] ?? null ) : null;

		return is_int( $id ) && $id > 0 ? $id : null;
	}

	/**
	 * When the creators were archived (UTC, MySQL format), or null.
	 */
	public static function archived_at(): ?string {
		$value = get_option( self::OPTION );
		$at    = is_array( $value ) ? ( $value['archived_at'] ?? null ) : null;

		return is_string( $at ) && '' !== $at ? $at : null;
	}

	/**
	 * Whether the creator was archived by an uninstall.
	 *
	 * @param int $creator_id Creator.
	 */
	public static function is_archived( int $creator_id ): bool {
		$max = self::max_creator_id();

		return null !== $max && $creator_id > 0 && $creator_id <= $max;
	}

	/**
	 * On a fresh install: removes the marker when the creators table is gone
	 * (the kept tables were removed by hand). Never called on an upgrade.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @return bool Whether a marker was removed.
	 */
	public static function forget_without_creators( wpdb $wpdb ): bool {
		if ( false === get_option( self::OPTION, false ) ) {
			return false;
		}

		$name   = Tables::name( $wpdb, Tables::CREATORS );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time check on a fresh install.

		// A failed read keeps the marker: a warning too many is safer than none.
		if ( '' !== $wpdb->last_error || null !== $exists ) {
			return false;
		}

		return delete_option( self::OPTION );
	}
}
