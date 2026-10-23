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

		return array( $this->save( new GlobalOverlay( OverlayId::generate(), false, OverlaySettings::defaults(), 1 ) ), true );
	}

	/**
	 * Stores MerchAlert's global overlay (the import, step 25.10.1): its id,
	 * so the old browser source URL keeps working, and its settings,
	 * switched off until the administrator switches it on; replaces an
	 * overlay with another id.
	 *
	 * @param string          $overlay_id MerchAlert's id (32 hex characters).
	 * @param OverlaySettings $settings   Settings.
	 * @throws DatabaseException When the id is invalid or it cannot be stored.
	 */
	public function import( string $overlay_id, OverlaySettings $settings ): GlobalOverlay {
		if ( ! OverlayId::valid( $overlay_id ) ) {
			throw new DatabaseException( 'Invalid global overlay id.' );
		}

		$existing = $this->find();

		return $this->save( new GlobalOverlay( $overlay_id, false, $settings, ( $existing->revision ?? 0 ) + 1 ) );
	}

	/**
	 * Stores new settings.
	 *
	 * @param GlobalOverlay   $overlay  Overlay.
	 * @param OverlaySettings $settings Settings.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function update_settings( GlobalOverlay $overlay, OverlaySettings $settings ): GlobalOverlay {
		return $this->save( new GlobalOverlay( $overlay->overlay_id, $overlay->enabled, $settings, $overlay->revision + 1 ) );
	}

	/**
	 * Switches the overlay on or off.
	 *
	 * @param GlobalOverlay $overlay Overlay.
	 * @param bool          $enabled Whether it is on.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set_enabled( GlobalOverlay $overlay, bool $enabled ): GlobalOverlay {
		return $this->save( new GlobalOverlay( $overlay->overlay_id, $enabled, $overlay->settings, $overlay->revision + 1 ) );
	}

	/**
	 * Replaces the secret id; the old browser source URL stops working.
	 *
	 * @param GlobalOverlay $overlay Overlay.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function regenerate( GlobalOverlay $overlay ): GlobalOverlay {
		return $this->save( new GlobalOverlay( OverlayId::generate(), $overlay->enabled, $overlay->settings, $overlay->revision + 1 ) );
	}

	/**
	 * Changes the version only ("Stop all alerts": open pages reload).
	 *
	 * @param GlobalOverlay $overlay Overlay.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function touch( GlobalOverlay $overlay ): GlobalOverlay {
		return $this->save( new GlobalOverlay( $overlay->overlay_id, $overlay->enabled, $overlay->settings, $overlay->revision + 1 ) );
	}

	/**
	 * Stores the overlay and reads it back.
	 *
	 * @param GlobalOverlay $overlay Overlay.
	 * @throws DatabaseException When it was not stored.
	 */
	private function save( GlobalOverlay $overlay ): GlobalOverlay {
		update_option(
			self::OPTION,
			array(
				'overlay_id' => $overlay->overlay_id,
				'enabled'    => $overlay->enabled,
				'settings'   => $overlay->settings->to_json(),
				'revision'   => $overlay->revision,
			),
			false
		);

		$stored = $this->find();

		if ( null === $stored || $stored->revision !== $overlay->revision || $stored->overlay_id !== $overlay->overlay_id ) {
			throw new DatabaseException( 'Could not save the global alert overlay.' );
		}

		return $stored;
	}
}
