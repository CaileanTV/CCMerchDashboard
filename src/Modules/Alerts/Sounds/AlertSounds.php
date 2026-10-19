<?php
/**
 * The creator's alert sounds.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Sounds;

use Closure;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlay;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Sounds of a creator's overlay (ADR-065 pkt. 3): a default sound and one
 * sound per category of the creator, as in MerchAlert.
 *
 * - **Own files:** the creator's uploads (validated by SoundFiles) are
 *   attachments marked with their owner (OWNER_META). Only the owner's
 *   attachments are ever played, listed or deleted, so a settings value can
 *   never reach another creator's file.
 * - **Library:** instead of uploading, a creator can choose a sound of the
 *   administrator's library (AlertSoundLibrary, step 6.3); library files are
 *   never deleted by creators.
 * - **First sound:** the overlay's first sound switches sounds on.
 * - **Replace and remove:** the previous file of a slot is deleted after the
 *   new settings are saved, only when the creator owns it and no other
 *   slot uses it.
 * - **Playback:** with sounds switched on, the sound of the first of the
 *   product's categories (or their parents) that has one, else the default
 *   sound.
 *
 * Changes are not audited (like the other look-and-feel settings).
 */
final class AlertSounds {

	/**
	 * Attachment meta: the creator who uploaded the sound.
	 */
	public const OWNER_META = '_merchboard_alert_sound_creator';

	/**
	 * The creator's categories: term id → name.
	 *
	 * @var Closure(int): array<int, string>
	 */
	private Closure $categories;

	/**
	 * The parent terms of a category.
	 *
	 * @var Closure(int): list<int>
	 */
	private Closure $ancestors;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): array<int, string> $categories
	 *
	 * @param AlertOverlayRepository         $overlays   Overlays.
	 * @param Closure                        $categories The creator's categories (term id → name).
	 * @param SoundFiles                     $files      Sound files.
	 * @param AlertSoundLibrary|null         $library    The administrator's library; null for none.
	 * @param (Closure(int): list<int>)|null $ancestors  Parent categories; defaults to get_ancestors().
	 */
	public function __construct( private AlertOverlayRepository $overlays, Closure $categories, private SoundFiles $files, private ?AlertSoundLibrary $library = null, ?Closure $ancestors = null ) {
		$this->categories = $categories;
		$this->ancestors  = $ancestors ?? static fn( int $term_id ): array => array_values( array_map( 'intval', get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) );
	}

	/**
	 * The sounds of an overlay for the dashboard.
	 *
	 * @param AlertOverlay $overlay Overlay.
	 * @return array{enabled: bool, default: array{url: string, name: string, seconds: int, library_id: int}|null, categories: list<array{term_id: int, name: string, sound: array{url: string, name: string, seconds: int, library_id: int}|null}>, library: list<array{id: int, url: string, name: string, seconds: int}>, max_bytes: int, max_seconds: int}
	 */
	public function describe( AlertOverlay $overlay ): array {
		$values     = $overlay->settings->to_array();
		$categories = array();
		$library    = array();

		foreach ( ( $this->categories )( $overlay->creator_id ) as $term_id => $name ) {
			$categories[] = array(
				'term_id' => $term_id,
				'name'    => $name,
				'sound'   => $this->sound( $values['audio_category_map'][ $term_id ] ?? 0, $overlay->creator_id )?->to_array(),
			);
		}

		foreach ( null === $this->library ? array() : $this->library->all() as $sound ) {
			$library[] = array(
				'id'      => $sound->attachment_id,
				'url'     => $sound->url,
				'name'    => $sound->name,
				'seconds' => $sound->seconds,
			);
		}

		return array(
			'enabled'     => $values['audio_enabled'],
			'default'     => $this->sound( $values['audio_default_sound'], $overlay->creator_id )?->to_array(),
			'categories'  => $categories,
			'library'     => $library,
			'max_bytes'   => SoundFiles::max_bytes(),
			'max_seconds' => SoundFiles::MAX_SECONDS,
		);
	}

	/**
	 * The URL of the sound an alert plays, or '' for none.
	 *
	 * @param AlertOverlay $overlay Overlay.
	 * @param AlertEvent   $event   Alert.
	 */
	public function url_for( AlertOverlay $overlay, AlertEvent $event ): string {
		$values = $overlay->settings->to_array();

		if ( ! $values['audio_enabled'] ) {
			return '';
		}

		$map = $values['audio_category_map'];

		foreach ( $event->category_ids as $term_id ) {
			foreach ( array_merge( array( $term_id ), ( $this->ancestors )( $term_id ) ) as $candidate ) {
				$sound = isset( $map[ $candidate ] ) ? $this->sound( $map[ $candidate ], $overlay->creator_id ) : null;

				if ( null !== $sound ) {
					return $sound->url;
				}
			}
		}

		return $this->sound( $values['audio_default_sound'], $overlay->creator_id )->url ?? '';
	}

	/**
	 * Stores an uploaded sound in a slot and replaces the slot's old sound.
	 *
	 * @param int          $creator_id Creator.
	 * @param int          $slot       0 for the default sound, else a term id of the creator's categories.
	 * @param array<mixed> $file       The upload (one entry of $_FILES).
	 * @return AlertOverlay|null The changed overlay; null before it is set up.
	 * @throws AlertSoundException When the slot or the file is refused (nothing is kept).
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function upload( int $creator_id, int $slot, array $file ): ?AlertOverlay {
		$overlay = $this->overlays->find_for_creator( $creator_id );

		if ( null === $overlay ) {
			return null;
		}

		$this->check_slot( $creator_id, $slot );
		$this->assign_new( $overlay, $slot, $this->files->store( $file, self::OWNER_META, $creator_id ) );

		return $this->overlays->find_for_creator( $creator_id );
	}

	/**
	 * Puts a sound of the library into a slot and replaces the slot's old sound.
	 *
	 * @param int $creator_id Creator.
	 * @param int $slot       0 for the default sound, else a term id of the creator's categories.
	 * @param int $sound      Library sound (attachment id).
	 * @return AlertOverlay|null The changed overlay; null before it is set up.
	 * @throws AlertSoundException When the slot or the sound is unknown.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function choose( int $creator_id, int $slot, int $sound ): ?AlertOverlay {
		$overlay = $this->overlays->find_for_creator( $creator_id );

		if ( null === $overlay ) {
			return null;
		}

		$this->check_slot( $creator_id, $slot );
		$this->assign( $overlay, $slot, $this->library_sound( $sound ) );

		return $this->overlays->find_for_creator( $creator_id );
	}

	/**
	 * Removes the sound of a slot.
	 *
	 * @param int $creator_id Creator.
	 * @param int $slot       0 for the default sound, else a term id.
	 * @return AlertOverlay|null The changed overlay; null before it is set up.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function remove( int $creator_id, int $slot ): ?AlertOverlay {
		$overlay = $this->overlays->find_for_creator( $creator_id );

		if ( null === $overlay ) {
			return null;
		}

		$this->assign( $overlay, $slot, 0 );

		return $this->overlays->find_for_creator( $creator_id );
	}

	/**
	 * Puts a new attachment into a slot; deletes it when that fails.
	 *
	 * @param AlertOverlay $overlay    Overlay.
	 * @param int          $slot       Slot.
	 * @param int          $attachment New attachment.
	 * @throws Throwable When it cannot be stored (DatabaseException); the attachment is deleted.
	 */
	private function assign_new( AlertOverlay $overlay, int $slot, int $attachment ): void {
		try {
			$this->assign( $overlay, $slot, $attachment );
		} catch ( Throwable $e ) {
			wp_delete_attachment( $attachment, true );

			throw $e;
		}
	}

	/**
	 * Puts an attachment (0: none) into a slot, then deletes the slot's
	 * previous file when the creator owns it and nothing else uses it.
	 *
	 * @param AlertOverlay $overlay    Overlay.
	 * @param int          $slot       Slot.
	 * @param int          $attachment Attachment, 0 to clear the slot.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function assign( AlertOverlay $overlay, int $slot, int $attachment ): void {
		$values = $overlay->settings->to_array();

		// The first sound switches sounds on: adding one means wanting to hear it.
		if ( $attachment > 0 && 0 === $values['audio_default_sound'] && array() === $values['audio_category_map'] ) {
			$values['audio_enabled'] = true;
		}

		if ( 0 === $slot ) {
			$previous                      = $values['audio_default_sound'];
			$values['audio_default_sound'] = $attachment;
		} else {
			$previous = $values['audio_category_map'][ $slot ] ?? 0;
			unset( $values['audio_category_map'][ $slot ] );

			if ( $attachment > 0 ) {
				$values['audio_category_map'][ $slot ] = $attachment;
			}
		}

		$this->overlays->update_settings( $overlay->id, OverlaySettings::from_array( $values ) );

		$in_use = in_array( $previous, $values['audio_category_map'], true ) || $previous === $values['audio_default_sound'];

		if ( $previous > 0 && ! $in_use && $this->owns( $previous, $overlay->creator_id ) ) {
			wp_delete_attachment( $previous, true );
		}
	}

	/**
	 * Requires the default slot or one of the creator's categories.
	 *
	 * @param int $creator_id Creator.
	 * @param int $slot       Slot.
	 * @throws AlertSoundException When the category is not the creator's.
	 */
	private function check_slot( int $creator_id, int $slot ): void {
		if ( 0 !== $slot && ! array_key_exists( $slot, ( $this->categories )( $creator_id ) ) ) {
			throw new AlertSoundException( AlertSoundException::UNKNOWN_CATEGORY );
		}
	}

	/**
	 * A sound of the library.
	 *
	 * @param int $sound Attachment id.
	 * @return int The attachment id.
	 * @throws AlertSoundException When it is not a library sound.
	 */
	private function library_sound( int $sound ): int {
		$found = $this->library?->find( $sound );

		if ( null === $found ) {
			throw new AlertSoundException( AlertSoundException::UNKNOWN_SOUND );
		}

		return $found->attachment_id;
	}

	/**
	 * A sound the creator owns or a library sound, or null.
	 *
	 * @param int $attachment Attachment id.
	 * @param int $creator_id Creator.
	 */
	private function sound( int $attachment, int $creator_id ): ?AlertSound {
		if ( $attachment <= 0 ) {
			return null;
		}

		return $this->owns( $attachment, $creator_id ) ? $this->files->sound( $attachment ) : $this->library?->find( $attachment );
	}

	/**
	 * Whether the creator uploaded an attachment.
	 *
	 * @param int $attachment Attachment id.
	 * @param int $creator_id Creator.
	 */
	private function owns( int $attachment, int $creator_id ): bool {
		return $creator_id > 0 && 'attachment' === get_post_type( $attachment ) && (string) get_post_meta( $attachment, self::OWNER_META, true ) === (string) $creator_id;
	}
}
