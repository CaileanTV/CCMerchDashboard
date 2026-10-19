<?php
/**
 * Storage of alert overlays.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_alert_overlays` (ADR-065): one overlay per creator
 * (`UNIQUE creator_id`) with a unique secret id. Lookups by id compare the
 * stored id with `hash_equals()` as well, so the comparison of the secret
 * never depends on the database collation.
 */
final class AlertOverlayRepository {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * The overlay with this secret id, or null.
	 *
	 * @param string $overlay_id Secret id.
	 * @throws DatabaseException When the database cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function find_by_overlay_id( string $overlay_id ): ?AlertOverlay {
		if ( ! OverlayId::valid( $overlay_id ) ) {
			return null;
		}

		$wpdb    = $this->wpdb;
		$overlay = $this->hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT id, creator_id, overlay_id, settings, updated_at FROM %i WHERE overlay_id = %s', $this->table(), $overlay_id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.

		return null !== $overlay && hash_equals( $overlay->overlay_id, $overlay_id ) ? $overlay : null;
	}

	/**
	 * The creator's overlay, or null.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the database cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function find_for_creator( int $creator_id ): ?AlertOverlay {
		if ( $creator_id < 1 ) {
			return null;
		}

		$wpdb = $this->wpdb;

		return $this->hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT id, creator_id, overlay_id, settings, updated_at FROM %i WHERE creator_id = %d', $this->table(), $creator_id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
	}

	/**
	 * Creates the creator's overlay unless one exists; returns the stored one.
	 *
	 * @param int             $creator_id Creator.
	 * @param string          $overlay_id Secret id (OverlayId format).
	 * @param OverlaySettings $settings   Settings.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create( int $creator_id, string $overlay_id, OverlaySettings $settings ): AlertOverlay {
		if ( $creator_id < 1 || ! OverlayId::valid( $overlay_id ) ) {
			throw new DatabaseException( 'Invalid alert overlay.' );
		}

		$now  = gmdate( 'Y-m-d H:i:s' );
		$wpdb = $this->wpdb;
		$sql  = $wpdb->prepare(
			'INSERT INTO %i (creator_id, overlay_id, settings, created_at, updated_at) VALUES (%d, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE id = id',
			$this->table(),
			$creator_id,
			$overlay_id,
			$settings->to_json(),
			$now,
			$now
		);

		if ( null === $sql || false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared above; a custom table.
			throw new DatabaseException( 'Could not store the alert overlay.' );
		}

		return $this->find_for_creator( $creator_id ) ?? throw new DatabaseException( 'Could not store the alert overlay.' );
	}

	/**
	 * Stores new settings; the new `updated_at` changes the overlay's version.
	 *
	 * @param int             $id       Overlay row id.
	 * @param OverlaySettings $settings Settings.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function update_settings( int $id, OverlaySettings $settings ): void {
		$this->update( $id, array( 'settings' => $settings->to_json() ) );
	}

	/**
	 * Gives the overlay a new version without changing it ("Stop all alerts",
	 * step 25.7): the open overlay page reloads. The new `updated_at` is at
	 * least a second after the old one, so the version also changes within
	 * the second of the last change.
	 *
	 * @param int $id Overlay row id.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function touch( int $id ): void {
		$wpdb = $this->wpdb;
		$sql  = $wpdb->prepare( 'UPDATE %i SET updated_at = GREATEST(UTC_TIMESTAMP(), updated_at + INTERVAL 1 SECOND) WHERE id = %d', $this->table(), $id );

		if ( null === $sql ) {
			throw new DatabaseException( 'Could not prepare the alert overlay update.' );
		}

		$updated = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared above; a custom table.

		if ( false === $updated || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not update the alert overlay.' );
		}
	}

	/**
	 * Replaces the secret id; the old OBS URL stops working at once.
	 *
	 * @param int    $id         Overlay row id.
	 * @param string $overlay_id New secret id (OverlayId format).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function update_overlay_id( int $id, string $overlay_id ): void {
		if ( ! OverlayId::valid( $overlay_id ) ) {
			throw new DatabaseException( 'Invalid alert overlay id.' );
		}

		$this->update( $id, array( 'overlay_id' => $overlay_id ) );
	}

	/**
	 * Updates one overlay row and its `updated_at`.
	 *
	 * @param int                   $id     Overlay row id.
	 * @param array<string, string> $values Column values (strings).
	 * @throws DatabaseException When the row was not updated.
	 */
	private function update( int $id, array $values ): void {
		$values['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$updated              = $this->wpdb->update( $this->table(), $values, array( 'id' => $id ), array_fill( 0, count( $values ), '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.

		if ( false === $updated ) {
			throw new DatabaseException( 'Could not update the alert overlay.' );
		}
	}

	/**
	 * An overlay from a row.
	 *
	 * @param mixed $row Row (ARRAY_A) or null.
	 * @throws DatabaseException When the database could not be read or the row is invalid.
	 */
	private function hydrate( mixed $row ): ?AlertOverlay {
		if ( ! is_array( $row ) ) {
			if ( '' !== $this->wpdb->last_error ) {
				throw new DatabaseException( 'Could not read the alert overlay.' );
			}

			return null;
		}

		if ( ! is_numeric( $row['id'] ?? null ) || ! is_numeric( $row['creator_id'] ?? null ) || ! is_string( $row['overlay_id'] ?? null ) ) {
			throw new DatabaseException( 'Invalid alert overlay row.' );
		}

		return new AlertOverlay(
			(int) $row['id'],
			(int) $row['creator_id'],
			$row['overlay_id'],
			OverlaySettings::from_json( (string) ( $row['settings'] ?? '' ) ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	/**
	 * The table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::ALERT_OVERLAYS );
	}
}
