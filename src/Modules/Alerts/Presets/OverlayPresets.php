<?php
/**
 * Managing and using the standard overlays.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Presets;

use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Images\AlertImage;
use MerchBoard\Modules\Alerts\Images\AlertImageLibrary;
use MerchBoard\Modules\Alerts\Images\AlertImages;
use MerchBoard\Modules\Alerts\Images\ImageFiles;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlay;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlays;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;

defined( 'ABSPATH' ) || exit;

/**
 * The standard overlays (step 25.9.5): the administrator makes them from the
 * defaults, names, designs (the same fields and rules as a creator's
 * overlay, AlertOverlays::merge()) and deletes them; a creator copies one
 * into their own overlay as a starting point. The copy takes the look and
 * the texts; the creator's sounds, sound timing and URL stay, and later
 * changes of the standard do not change the copy. A standard's text fills
 * use only the image library (a creator may use those, never the
 * administrator's own uploads). Making, renaming and deleting are audited;
 * designing and copying are not (look-and-feel, as a creator's).
 */
final class OverlayPresets {

	/**
	 * Settings a copy never changes: the creator's sounds and whether the
	 * alert lasts as long as its sound.
	 */
	public const KEPT = array( 'audio_enabled', 'audio_default_sound', 'audio_category_map', 'display_from_sound' );

	/**
	 * Constructor.
	 *
	 * @param OverlayPresetStore     $store    The standard overlays.
	 * @param AuditTrail             $audit    Audit log.
	 * @param AlertOverlayRepository $overlays The creators' overlays (for copies only).
	 * @param AlertFonts|null        $fonts    Approved fonts.
	 * @param AlertImageLibrary|null $library  The image library (text fills).
	 * @param AlertImages|null       $images   The creators' images (cleaning a copy's fills).
	 */
	public function __construct(
		private OverlayPresetStore $store,
		private AuditTrail $audit,
		private AlertOverlayRepository $overlays,
		private ?AlertFonts $fonts = null,
		private ?AlertImageLibrary $library = null,
		private ?AlertImages $images = null
	) {
	}

	/**
	 * Every standard overlay, oldest first.
	 *
	 * @return list<OverlayPreset>
	 */
	public function all(): array {
		return $this->store->all();
	}

	/**
	 * A standard overlay, or null.
	 *
	 * @param string $id Id.
	 */
	public function find( string $id ): ?OverlayPreset {
		return OverlayPreset::valid_id( $id ) ? $this->store->find( $id ) : null;
	}

	/**
	 * Makes a standard overlay from the defaults.
	 *
	 * @param string $name Name.
	 * @throws OverlayPresetException When the name is invalid or MAX exist.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create( string $name ): OverlayPreset {
		$preset = $this->store->add( self::name( $name ) );
		$this->audit->record( AuditAction::AlertOverlayPresetCreated, null, array( 'name' => $preset->name ) );

		return $preset;
	}

	/**
	 * Renames a standard overlay.
	 *
	 * @param string $id   Id.
	 * @param string $name New name.
	 * @throws OverlayPresetException When it does not exist or the name is invalid.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function rename( string $id, string $name ): OverlayPreset {
		$preset = $this->require( $id );
		$name   = self::name( $name );

		if ( $name === $preset->name ) {
			return $preset;
		}

		$renamed = $this->store->replace( new OverlayPreset( $preset->id, $name, $preset->settings ) );
		$this->audit->record(
			AuditAction::AlertOverlayPresetRenamed,
			null,
			array(
				'name'          => $name,
				'previous_name' => $preset->name,
			)
		);

		return $renamed;
	}

	/**
	 * Deletes a standard overlay (copies made from it stay).
	 *
	 * @param string $id Id.
	 * @throws OverlayPresetException When it does not exist.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function delete( string $id ): void {
		$deleted = $this->store->delete( $this->require( $id )->id );
		$this->audit->record( AuditAction::AlertOverlayPresetDeleted, null, array( 'name' => $deleted->name ) );
	}

	/**
	 * Changes a standard overlay's design fields.
	 *
	 * @param string       $id    Id.
	 * @param array<mixed> $input Submitted fields; unknown keys are ignored.
	 * @throws OverlayPresetException When it does not exist.
	 * @throws InvalidArgumentException When the font is not approved (message: the field).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function update_settings( string $id, array $input ): OverlayPreset {
		$preset = $this->require( $id );
		$values = AlertOverlays::merge( $preset->settings, $input, $this->fonts )->to_array();

		$values['text_fills'] = $this->library_fills( $values['text_fills'] );

		return $this->store->replace( new OverlayPreset( $preset->id, $preset->name, OverlaySettings::from_array( $values ) ) );
	}

	/**
	 * The image library as a standard overlay's images card reads it (no own
	 * uploads).
	 *
	 * @return array{own: list<array{id: int, url: string, name: string, width: int, height: int}>, library: list<array{id: int, url: string, name: string, width: int, height: int}>, max_bytes: int, max_pixels: int, max_own: int}
	 */
	public function images(): array {
		return array(
			'own'        => array(),
			'library'    => array_map( static fn( AlertImage $image ): array => $image->to_array(), $this->library?->all() ?? array() ),
			'max_bytes'  => ImageFiles::max_bytes(),
			'max_pixels' => ImageFiles::MAX_PIXELS,
			'max_own'    => 0,
		);
	}

	/**
	 * Copies a standard overlay's look and texts into a creator's overlay.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $id         The standard overlay's id.
	 * @return AlertOverlay|null The changed overlay; null before it is set up.
	 * @throws OverlayPresetException When the standard overlay does not exist.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function apply( int $creator_id, string $id ): ?AlertOverlay {
		$preset  = $this->require( $id );
		$overlay = $this->overlays->find_for_creator( $creator_id );

		if ( null === $overlay ) {
			return null;
		}

		$copied = $overlay->settings->to_array();

		foreach ( $preset->settings->to_array() as $key => $value ) {
			if ( ! in_array( $key, self::KEPT, true ) ) {
				$copied[ $key ] = $value;
			}
		}

		$values = OverlaySettings::from_array( $copied )->to_array();

		// A font that is no longer approved gives the default font.
		if ( 'inherit' !== $values['font_family'] && null === $this->fonts?->find( $values['font_family'] ) ) {
			$values['font_family'] = 'inherit';
		}

		$values['text_fills'] = null === $this->images ? AlertImages::without_images( $values['text_fills'] ) : $this->images->clean( $creator_id, $values['text_fills'] );

		$this->overlays->update_settings( $overlay->id, OverlaySettings::from_array( $values ) );

		return $this->overlays->find_for_creator( $creator_id );
	}

	/**
	 * Fills that keep only library images.
	 *
	 * @param array{main_text: array{type: string, preset: string, image: int, motion: string}, secondary_text: array{type: string, preset: string, image: int, motion: string}} $fills Fills.
	 * @return array{main_text: array{type: string, preset: string, image: int, motion: string}, secondary_text: array{type: string, preset: string, image: int, motion: string}}
	 */
	private function library_fills( array $fills ): array {
		foreach ( $fills as $text => $fill ) {
			if ( 0 !== $fill['image'] && null === $this->library?->find( $fill['image'] ) ) {
				$fills[ $text ] = array(
					'type'   => 'image' === $fill['type'] ? 'none' : $fill['type'],
					'preset' => $fill['preset'],
					'image'  => 0,
					'motion' => $fill['motion'],
				);
			}
		}

		return $fills;
	}

	/**
	 * A standard overlay that exists.
	 *
	 * @param string $id Id.
	 * @throws OverlayPresetException When it does not.
	 */
	private function require( string $id ): OverlayPreset {
		$preset = $this->find( $id );

		if ( null === $preset ) {
			throw new OverlayPresetException( OverlayPresetException::UNKNOWN_PRESET );
		}

		return $preset;
	}

	/**
	 * A cleaned name: one plain line of 1 to NAME_MAX characters.
	 *
	 * @param string $name Submitted name.
	 * @throws OverlayPresetException When it is empty or too long.
	 */
	private static function name( string $name ): string {
		$name = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $name ) ) );

		if ( '' === $name || mb_strlen( $name ) > OverlayPresetStore::NAME_MAX ) {
			throw new OverlayPresetException( OverlayPresetException::INVALID_NAME );
		}

		return $name;
	}
}
