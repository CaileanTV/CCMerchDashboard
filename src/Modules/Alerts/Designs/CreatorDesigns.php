<?php
/**
 * A creator's saved overlay designs.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Designs;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Images\AlertImages;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlay;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Modules\Alerts\Sounds\AlertSounds;
use MerchBoard\Modules\Creators\CreatorMetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * A creator saves their overlay's design under a name and takes it into use
 * again later, e.g. one design per part of a stream (the project owner's
 * wish 2026-10-23, after step 25.9.5). Only the creator sees and uses their
 * designs (creator meta `alert_designs`, reached only through `/me`).
 *
 * - **Content:** the whole design: look, texts and sounds; not the URL.
 * - **At most MAX**, a name of 1 to NAME_MAX characters.
 * - **Sounds:** a creator's own sound file stays while their overlay or one
 *   of their designs uses it (AlertSounds asks sounds_in_use()); deleting,
 *   overwriting or using a design deletes own files nothing uses any more.
 * - **Taking a design into use** replaces the overlay's settings; a font that
 *   is no longer approved gives the default font, and text fill images the
 *   creator may no longer use are dropped.
 *
 * Not audited (look-and-feel, as the overlay's settings).
 */
final class CreatorDesigns {

	/**
	 * Most designs per creator.
	 */
	public const MAX = 10;

	/**
	 * Longest name.
	 */
	public const NAME_MAX = 60;

	/**
	 * Constructor.
	 *
	 * @param CreatorMetaStore       $meta     Creator meta.
	 * @param AlertOverlayRepository $overlays Overlays.
	 * @param AlertFonts|null        $fonts    Approved fonts.
	 * @param AlertImages|null       $images   Text fill images.
	 */
	public function __construct( private CreatorMetaStore $meta, private AlertOverlayRepository $overlays, private ?AlertFonts $fonts = null, private ?AlertImages $images = null ) {
	}

	/**
	 * The creator's designs, oldest first.
	 *
	 * @param int $creator_id Creator.
	 * @return list<CreatorDesign>
	 */
	public function all( int $creator_id ): array {
		$stored  = $this->meta->get( $creator_id, CreatorMetaStore::ALERT_DESIGNS );
		$designs = array();

		foreach ( is_array( $stored ) ? $stored : array() as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['id'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{16}$/', $row['id'] ) || ! is_string( $row['name'] ?? null ) ) {
				continue;
			}

			$designs[] = new CreatorDesign( $row['id'], $row['name'], OverlaySettings::from_json( is_string( $row['settings'] ?? null ) ? $row['settings'] : '' ), is_string( $row['saved_at'] ?? null ) ? $row['saved_at'] : '' );
		}

		return $designs;
	}

	/**
	 * The sounds the creator's designs use (AlertSounds keeps these files).
	 *
	 * @param int $creator_id Creator.
	 * @return list<int>
	 */
	public function sounds_in_use( int $creator_id ): array {
		$sounds = array();

		foreach ( $this->all( $creator_id ) as $design ) {
			$sounds = array_merge( $sounds, $design->sounds() );
		}

		return array_values( array_unique( $sounds ) );
	}

	/**
	 * Saves the overlay's current design as a new one.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $name       Name.
	 * @return list<CreatorDesign>
	 * @throws CreatorDesignException When there is no overlay, the name is invalid or MAX exist.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save_current( int $creator_id, string $name ): array {
		$overlay = $this->overlay( $creator_id );
		$name    = self::name( $name );
		$designs = $this->all( $creator_id );

		if ( count( $designs ) >= self::MAX ) {
			self::reject( CreatorDesignException::FULL );
		}

		$designs[] = new CreatorDesign( bin2hex( random_bytes( 8 ) ), $name, $overlay->settings, gmdate( 'Y-m-d H:i:s' ) );
		$this->store( $creator_id, $designs );

		return $designs;
	}

	/**
	 * Overwrites a design with the overlay's current design (same name).
	 *
	 * @param int    $creator_id Creator.
	 * @param string $id         Design.
	 * @return list<CreatorDesign>
	 * @throws CreatorDesignException When there is no overlay or no such design.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function update_from_current( int $creator_id, string $id ): array {
		$overlay = $this->overlay( $creator_id );
		$design  = $this->find( $creator_id, $id );

		return $this->replace( $creator_id, new CreatorDesign( $design->id, $design->name, $overlay->settings, gmdate( 'Y-m-d H:i:s' ) ), $overlay );
	}

	/**
	 * Renames a design.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $id         Design.
	 * @param string $name       New name.
	 * @return list<CreatorDesign>
	 * @throws CreatorDesignException When there is no such design or the name is invalid.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function rename( int $creator_id, string $id, string $name ): array {
		$design = $this->find( $creator_id, $id );

		return $this->replace( $creator_id, new CreatorDesign( $design->id, self::name( $name ), $design->settings, $design->saved_at ), null );
	}

	/**
	 * Deletes a design; its own sound files nothing else uses are deleted.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $id         Design.
	 * @return list<CreatorDesign>
	 * @throws CreatorDesignException When there is no such design.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function delete( int $creator_id, string $id ): array {
		$deleted = $this->find( $creator_id, $id );
		$designs = array_values( array_filter( $this->all( $creator_id ), static fn( CreatorDesign $design ): bool => $design->id !== $id ) );

		$this->store( $creator_id, $designs );
		$this->clean_sounds( $creator_id, $deleted->sounds(), $this->overlays->find_for_creator( $creator_id ) );

		return $designs;
	}

	/**
	 * Takes a design into use: its look, texts and sounds replace the
	 * overlay's (the URL stays).
	 *
	 * @param int    $creator_id Creator.
	 * @param string $id         Design.
	 * @return AlertOverlay The changed overlay.
	 * @throws CreatorDesignException When there is no overlay or no such design.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function apply( int $creator_id, string $id ): AlertOverlay {
		$overlay  = $this->overlay( $creator_id );
		$design   = $this->find( $creator_id, $id );
		$previous = ( new CreatorDesign( '', '', $overlay->settings, '' ) )->sounds();
		$values   = $design->settings->to_array();

		// A font that is no longer approved gives the default font.
		if ( 'inherit' !== $values['font_family'] && null === $this->fonts?->find( $values['font_family'] ) ) {
			$values['font_family'] = 'inherit';
		}

		$values['text_fills'] = null === $this->images ? AlertImages::without_images( $values['text_fills'] ) : $this->images->clean( $creator_id, $values['text_fills'] );

		$this->overlays->update_settings( $overlay->id, OverlaySettings::from_array( $values ) );
		$changed = $this->overlays->find_for_creator( $creator_id ) ?? $overlay;
		$this->clean_sounds( $creator_id, $previous, $changed );

		return $changed;
	}

	/**
	 * A design of the creator.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $id         Design.
	 * @throws CreatorDesignException When there is none.
	 */
	public function find( int $creator_id, string $id ): CreatorDesign {
		foreach ( $this->all( $creator_id ) as $design ) {
			if ( $design->id === $id ) {
				return $design;
			}
		}

		self::reject( CreatorDesignException::UNKNOWN_DESIGN );
	}

	/**
	 * Replaces a design (same id) and deletes its old own sounds nothing uses.
	 *
	 * @param int               $creator_id Creator.
	 * @param CreatorDesign     $design     The changed design.
	 * @param AlertOverlay|null $overlay    The overlay (null: read it).
	 * @return list<CreatorDesign>
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function replace( int $creator_id, CreatorDesign $design, ?AlertOverlay $overlay ): array {
		$designs = $this->all( $creator_id );
		$old     = array();

		foreach ( $designs as $index => $existing ) {
			if ( $existing->id === $design->id ) {
				$old               = $existing->sounds();
				$designs[ $index ] = $design;
			}
		}

		$this->store( $creator_id, $designs );
		$this->clean_sounds( $creator_id, $old, $overlay ?? $this->overlays->find_for_creator( $creator_id ) );

		return $designs;
	}

	/**
	 * Deletes the creator's own sound files among some that neither the
	 * overlay nor a design uses any more.
	 *
	 * @phpstan-param list<int> $candidates
	 *
	 * @param int               $creator_id Creator.
	 * @param int[]             $candidates Attachment ids.
	 * @param AlertOverlay|null $overlay    The overlay now.
	 */
	private function clean_sounds( int $creator_id, array $candidates, ?AlertOverlay $overlay ): void {
		$used = $this->sounds_in_use( $creator_id );

		if ( null !== $overlay ) {
			$used = array_merge( $used, ( new CreatorDesign( '', '', $overlay->settings, '' ) )->sounds() );
		}

		foreach ( array_unique( $candidates ) as $attachment ) {
			if ( ! in_array( $attachment, $used, true ) && 'attachment' === get_post_type( $attachment ) && (string) get_post_meta( $attachment, AlertSounds::OWNER_META, true ) === (string) $creator_id ) {
				wp_delete_attachment( $attachment, true );
			}
		}
	}

	/**
	 * The creator's overlay.
	 *
	 * @param int $creator_id Creator.
	 * @throws CreatorDesignException When there is none yet.
	 */
	private function overlay( int $creator_id ): AlertOverlay {
		$overlay = $this->overlays->find_for_creator( $creator_id );

		if ( null === $overlay ) {
			self::reject( CreatorDesignException::NO_OVERLAY );
		}

		return $overlay;
	}

	/**
	 * Stores the designs.
	 *
	 * @phpstan-param list<CreatorDesign> $designs
	 *
	 * @param int             $creator_id Creator.
	 * @param CreatorDesign[] $designs    Designs.
	 * @throws DatabaseException When they cannot be stored.
	 */
	private function store( int $creator_id, array $designs ): void {
		$this->meta->set(
			$creator_id,
			CreatorMetaStore::ALERT_DESIGNS,
			array_map(
				static fn( CreatorDesign $design ): array => array(
					'id'       => $design->id,
					'name'     => $design->name,
					'settings' => $design->settings->to_json(),
					'saved_at' => $design->saved_at,
				),
				$designs
			)
		);
	}

	/**
	 * A cleaned name: one plain line of 1 to NAME_MAX characters.
	 *
	 * @param string $name Submitted name.
	 * @throws CreatorDesignException When it is empty or too long.
	 */
	private static function name( string $name ): string {
		$name = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $name ) ) );

		if ( '' === $name || mb_strlen( $name ) > self::NAME_MAX ) {
			self::reject( CreatorDesignException::INVALID_NAME );
		}

		return $name;
	}

	/**
	 * Rejects a change.
	 *
	 * @param string $reason CreatorDesignException constant.
	 * @throws CreatorDesignException Always.
	 */
	private static function reject( string $reason ): never {
		throw new CreatorDesignException( $reason );
	}
}
