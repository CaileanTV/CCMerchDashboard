<?php
/**
 * Storage of the global alert overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\GlobalOverlay;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Alerts\Overlay\OverlayId;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;

defined( 'ABSPATH' ) || exit;

/**
 * The global overlay in its own option (step 25.9.4), never in the creators'
 * overlay table: `{ overlay_id, enabled, settings (JSON), revision }`.
 * Not autoloaded, so the secret id is not loaded on every request, and read
 * past WordPress' option cache, so a long-poll sees a change at once.
 */
final class GlobalOverlayStore {

	/**
	 * The option.
	 */
	public const OPTION = 'merchboard_alert_global_overlay';

	/**
	 * The overlay, or null before it is set up.
	 */
	public function find(): ?GlobalOverlay {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) || ! is_string( $stored['overlay_id'] ?? null ) || ! OverlayId::valid( $stored['overlay_id'] ) ) {
			return null;
		}

		$enabled = true === ( $stored['enabled'] ?? false );

		return new GlobalOverlay(
			$stored['overlay_id'],
			$enabled,
			OverlaySettings::from_json( is_string( $stored['settings'] ?? null ) ? $stored['settings'] : '' ),
			is_int( $stored['revision'] ?? null ) ? $stored['revision'] : 0
		);
	}

	/**
	 * Sets the overlay up (switched off, default settings, a new id); the
	 * existing one when it is set up.
	 *
	 * @return array{0: GlobalOverlay, 1: bool} The overlay and whether it was created now.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create(): array {
		$existing = $this->find();

		if ( null !== $existing ) {
			return array( $existing, false );
		}

		global $wpdb;

		$overlay = new GlobalOverlay( OverlayId::generate(), false, OverlaySettings::defaults(), 1 );
		// add_option() can overwrite a concurrent insert via ON DUPLICATE KEY UPDATE.
		$created = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Insert only; find() invalidates the option caches.
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
				$wpdb->options,
				self::OPTION,
				maybe_serialize( self::values( $overlay ) ),
				'off'
			)
		);
		$stored  = $this->find();

		if ( false === $created || null === $stored ) {
			throw new DatabaseException( 'Could not create the global alert overlay.' );
		}

		return array( $stored, 1 === $created );
	}

	/**
	 * Stores new settings.
	 *
	 * @param GlobalOverlay   $overlay  Overlay.
	 * @param OverlaySettings $settings Settings.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function update_settings( GlobalOverlay $overlay, OverlaySettings $settings ): GlobalOverlay {
		return $this->save( $overlay, new GlobalOverlay( $overlay->overlay_id, $overlay->enabled, $settings, $overlay->revision + 1 ) );
	}

	/**
	 * Switches the overlay on or off.
	 *
	 * @param GlobalOverlay $overlay Overlay.
	 * @param bool          $enabled Whether it is on.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set_enabled( GlobalOverlay $overlay, bool $enabled ): GlobalOverlay {
		return $this->save( $overlay, new GlobalOverlay( $overlay->overlay_id, $enabled, $overlay->settings, $overlay->revision + 1 ) );
	}

	/**
	 * Replaces the secret id; the old browser source URL stops working.
	 *
	 * @param GlobalOverlay $overlay Overlay.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function regenerate( GlobalOverlay $overlay ): GlobalOverlay {
		return $this->save( $overlay, new GlobalOverlay( OverlayId::generate(), $overlay->enabled, $overlay->settings, $overlay->revision + 1 ) );
	}

	/**
	 * Changes the version only ("Stop all alerts": open pages reload).
	 *
	 * @param GlobalOverlay $overlay Overlay.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function touch( GlobalOverlay $overlay ): GlobalOverlay {
		return $this->save( $overlay, new GlobalOverlay( $overlay->overlay_id, $overlay->enabled, $overlay->settings, $overlay->revision + 1 ) );
	}

	/**
	 * Atomically replaces the snapshot. A stale request must fail rather than
	 * restore an old secret id or enabled flag after another request revokes it.
	 *
	 * @param GlobalOverlay $previous Snapshot the mutation was based on.
	 * @param GlobalOverlay $overlay  Replacement.
	 * @throws DatabaseException When it was not stored or the snapshot is stale.
	 */
	private function save( GlobalOverlay $previous, GlobalOverlay $overlay ): GlobalOverlay {
		global $wpdb;

		$raw     = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read the persisted snapshot, bypassing option caches.
		$current = maybe_unserialize( $raw );

		if ( ! is_array( $current ) || ( $current['revision'] ?? 0 ) !== $previous->revision || ( $current['overlay_id'] ?? null ) !== $previous->overlay_id || ( $current['enabled'] ?? false ) !== $previous->enabled ) {
			throw new DatabaseException( 'The global alert overlay changed. Please try again.' );
		}

		// Compare the exact bytes read above so a concurrent write cannot slip
		// between the snapshot check and replacement (including case-only ids).
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic compare-and-swap; invalidate the option cache below.
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s',
				$wpdb->options,
				maybe_serialize( self::values( $overlay ) ),
				self::OPTION,
				$raw
			)
		);
		wp_cache_delete( self::OPTION, 'options' );

		if ( 1 !== $updated ) {
			throw new DatabaseException( 'Could not save the global alert overlay. Please try again.' );
		}

		return $overlay;
	}

	/**
	 * The stored option fields.
	 *
	 * @param GlobalOverlay $overlay Overlay.
	 * @return array{overlay_id: string, enabled: bool, settings: string, revision: int}
	 */
	private static function values( GlobalOverlay $overlay ): array {
		return array(
			'overlay_id' => $overlay->overlay_id,
			'enabled'    => $overlay->enabled,
			'settings'   => $overlay->settings->to_json(),
			'revision'   => $overlay->revision,
		);
	}
}
