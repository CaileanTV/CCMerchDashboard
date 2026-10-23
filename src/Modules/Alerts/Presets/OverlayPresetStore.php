<?php
/**
 * Storage of the standard overlays.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Presets;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;

defined( 'ABSPATH' ) || exit;

/**
 * The standard overlays in one option (step 25.9.5), not autoloaded and
 * read past WordPress' option cache: a list of `{ id, name, settings }`
 * (settings as JSON in the overlays' schema), in the order they were made.
 * At most MAX; a name is one line of at most NAME_MAX characters.
 */
final class OverlayPresetStore {

	/**
	 * The option.
	 */
	public const OPTION = 'merchboard_alert_overlay_presets';

	/**
	 * Most standard overlays.
	 */
	public const MAX = 20;

	/**
	 * Longest name.
	 */
	public const NAME_MAX = 60;

	/**
	 * Every standard overlay, oldest first.
	 *
	 * @return list<OverlayPreset>
	 */
	public function all(): array {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$stored  = get_option( self::OPTION, array() );
		$presets = array();

		foreach ( is_array( $stored ) ? $stored : array() as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['id'] ?? null ) || ! OverlayPreset::valid_id( $row['id'] ) || ! is_string( $row['name'] ?? null ) ) {
				continue;
			}

			$presets[] = new OverlayPreset( $row['id'], $row['name'], OverlaySettings::from_json( is_string( $row['settings'] ?? null ) ? $row['settings'] : '' ) );
		}

		return $presets;
	}

	/**
	 * A standard overlay, or null.
	 *
	 * @param string $id Id.
	 */
	public function find( string $id ): ?OverlayPreset {
		foreach ( $this->all() as $preset ) {
			if ( $preset->id === $id ) {
				return $preset;
			}
		}

		return null;
	}

	/**
	 * Adds a standard overlay with the default settings.
	 *
	 * @param string $name A valid name.
	 * @throws OverlayPresetException When MAX exist already.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function add( string $name ): OverlayPreset {
		$presets = $this->all();

		if ( count( $presets ) >= self::MAX ) {
			self::reject( OverlayPresetException::FULL );
		}

		$preset    = new OverlayPreset( bin2hex( random_bytes( 8 ) ), $name, OverlaySettings::defaults() );
		$presets[] = $preset;
		$this->save( $presets );

		return $preset;
	}

	/**
	 * Replaces a standard overlay (same id).
	 *
	 * @param OverlayPreset $preset The changed standard overlay.
	 * @throws OverlayPresetException When it does not exist.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function replace( OverlayPreset $preset ): OverlayPreset {
		$presets = $this->all();
		$found   = false;

		foreach ( $presets as $index => $existing ) {
			if ( $existing->id === $preset->id ) {
				$presets[ $index ] = $preset;
				$found             = true;
			}
		}

		if ( ! $found ) {
			self::reject( OverlayPresetException::UNKNOWN_PRESET );
		}

		$this->save( $presets );

		return $preset;
	}

	/**
	 * Deletes a standard overlay.
	 *
	 * @param string $id Id.
	 * @return OverlayPreset The deleted standard overlay.
	 * @throws OverlayPresetException When it does not exist.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function delete( string $id ): OverlayPreset {
		$presets = $this->all();
		$deleted = $this->find( $id );

		if ( null === $deleted ) {
			self::reject( OverlayPresetException::UNKNOWN_PRESET );
		}

		$this->save( array_values( array_filter( $presets, static fn( OverlayPreset $preset ): bool => $preset->id !== $id ) ) );

		return $deleted;
	}

	/**
	 * Stores the list and checks it by reading it back.
	 *
	 * @phpstan-param list<OverlayPreset> $presets
	 *
	 * @param OverlayPreset[] $presets Standard overlays.
	 * @throws DatabaseException When it was not stored.
	 */
	private function save( array $presets ): void {
		$rows = array_map(
			static fn( OverlayPreset $preset ): array => array(
				'id'       => $preset->id,
				'name'     => $preset->name,
				'settings' => $preset->settings->to_json(),
			),
			$presets
		);

		update_option( self::OPTION, $rows, false );

		$stored = array_map( static fn( OverlayPreset $preset ): array => array( $preset->id, $preset->name, $preset->settings->to_json() ), $this->all() );
		$wanted = array_map( static fn( array $row ): array => array( $row['id'], $row['name'], $row['settings'] ), $rows );

		if ( $stored !== $wanted ) {
			throw new DatabaseException( 'Could not save the standard overlays.' );
		}
	}

	/**
	 * Rejects a change.
	 *
	 * @param string $reason OverlayPresetException constant.
	 * @throws OverlayPresetException Always.
	 */
	private static function reject( string $reason ): never {
		throw new OverlayPresetException( $reason );
	}
}
