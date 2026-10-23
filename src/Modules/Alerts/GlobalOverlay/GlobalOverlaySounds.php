<?php
/**
 * Sounds of the global alert overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\GlobalOverlay;

use Closure;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Modules\Alerts\Sounds\AlertSound;
use MerchBoard\Modules\Alerts\Sounds\AlertSoundException;
use MerchBoard\Modules\Alerts\Sounds\AlertSoundLibrary;
use MerchBoard\Modules\Alerts\Sounds\SoundFiles;
use MerchBoard\Modules\Alerts\Sounds\SoundLimits;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * The global overlay's sounds (step 25.9.4): a default sound and a sound per
 * product category, for every category (as MerchAlert). A sound is the
 * global overlay's own upload (attachment meta OWNER_META, never a
 * creator's) or a sound of the administrator's library. Only its own files
 * are ever deleted; a creator's sound can neither be chosen nor deleted.
 * Uploads are checked against the site-wide limits (Settings → Alerts).
 */
final class GlobalOverlaySounds {

	/**
	 * Attachment meta of the global overlay's own sounds.
	 */
	public const OWNER_META = '_merchboard_alert_sound_global';

	/**
	 * Every product category: term id → name.
	 *
	 * @var Closure(): array<int, string>
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
	 * @param GlobalOverlayStore                   $store      The global overlay.
	 * @param SoundFiles                           $files      Sound files.
	 * @param AlertSoundLibrary|null               $library    The administrator's library; null for none.
	 * @param (Closure(): array<int, string>)|null $categories Every product category; defaults to the `product_cat` terms.
	 * @param (Closure(int): list<int>)|null       $ancestors  Parent categories; defaults to get_ancestors().
	 */
	public function __construct( private GlobalOverlayStore $store, private SoundFiles $files, private ?AlertSoundLibrary $library = null, ?Closure $categories = null, ?Closure $ancestors = null ) {
		$this->categories = $categories ?? static function (): array {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
				)
			);
			$names = array();

			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$names[ $term->term_id ] = $term->name;
			}

			return $names;
		};
		$this->ancestors  = $ancestors ?? static fn( int $term_id ): array => array_values( array_map( 'intval', get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) );
	}

	/**
	 * The sounds as the admin page shows them (the same shape as a creator's).
	 *
	 * @param GlobalOverlay $overlay Overlay.
	 * @return array{enabled: bool, default: array{url: string, name: string, seconds: int, library_id: int}|null, categories: list<array{term_id: int, name: string, sound: array{url: string, name: string, seconds: int, library_id: int}|null}>, library: list<array{id: int, url: string, name: string, seconds: int}>, max_bytes: int, max_seconds: int}
	 */
	public function describe( GlobalOverlay $overlay ): array {
		$values     = $overlay->settings->to_array();
		$categories = array();
		$library    = array();

		foreach ( ( $this->categories )() as $term_id => $name ) {
			$categories[] = array(
				'term_id' => $term_id,
				'name'    => $name,
				'sound'   => $this->sound( $values['audio_category_map'][ $term_id ] ?? 0 )?->to_array(),
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

		$limits = SoundLimits::site_wide();

		return array(
			'enabled'     => $values['audio_enabled'],
			'default'     => $this->sound( $values['audio_default_sound'] )?->to_array(),
			'categories'  => $categories,
			'library'     => $library,
			'max_bytes'   => $limits->max_bytes,
			'max_seconds' => $limits->max_seconds,
		);
	}

	/**
	 * The URL of the sound an alert plays, or '' for none: the first of the
	 * product's categories (or their parents) with a sound, else the default.
	 *
	 * @param GlobalOverlay $overlay Overlay.
	 * @param AlertEvent    $event   Alert.
	 */
	public function url_for( GlobalOverlay $overlay, AlertEvent $event ): string {
		$values = $overlay->settings->to_array();

		if ( ! $values['audio_enabled'] ) {
			return '';
		}

		$map = $values['audio_category_map'];

		foreach ( $event->category_ids as $term_id ) {
			foreach ( array_merge( array( $term_id ), ( $this->ancestors )( $term_id ) ) as $candidate ) {
				$sound = isset( $map[ $candidate ] ) ? $this->sound( $map[ $candidate ] ) : null;

				if ( null !== $sound ) {
					return $sound->url;
				}
			}
		}

		return $this->sound( $values['audio_default_sound'] )->url ?? '';
	}

	/**
	 * Uploads a sound into a slot (0: default, else a category) and replaces
	 * the slot's own previous file.
	 *
	 * @param int          $slot Slot.
	 * @param array<mixed> $file The upload (one entry of $_FILES).
	 * @return GlobalOverlay|null The changed overlay; null before it is set up.
	 * @throws AlertSoundException When the slot or the file is refused (nothing is kept).
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function upload( int $slot, array $file ): ?GlobalOverlay {
		$overlay = $this->store->find();

		if ( null === $overlay ) {
			return null;
		}

		$this->check_slot( $slot );

		return $this->assign_new( $overlay, $slot, $this->files->store( $file, self::OWNER_META, 1, SoundLimits::site_wide() ) );
	}

	/**
	 * Puts a sound of the library into a slot.
	 *
	 * @param int $slot  Slot.
	 * @param int $sound The library sound's attachment id.
	 * @return GlobalOverlay|null The changed overlay; null before it is set up.
	 * @throws AlertSoundException When the slot or the sound is unknown.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function choose( int $slot, int $sound ): ?GlobalOverlay {
		$overlay = $this->store->find();

		if ( null === $overlay ) {
			return null;
		}

		$this->check_slot( $slot );

		return $this->assign( $overlay, $slot, $this->library_sound( $sound ) );
	}

	/**
	 * Empties a slot (its own file is deleted when no other slot uses it).
	 *
	 * @param int $slot Slot.
	 * @return GlobalOverlay|null The changed overlay; null before it is set up.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function remove( int $slot ): ?GlobalOverlay {
		$overlay = $this->store->find();

		return null === $overlay ? null : $this->assign( $overlay, $slot, 0 );
	}

	/**
	 * Puts a new upload into a slot; deletes it again when that fails.
	 *
	 * @param GlobalOverlay $overlay    Overlay.
	 * @param int           $slot       Slot.
	 * @param int           $attachment The new attachment.
	 * @throws Throwable When it cannot be stored (the upload is deleted).
	 */
	private function assign_new( GlobalOverlay $overlay, int $slot, int $attachment ): GlobalOverlay {
		try {
			return $this->assign( $overlay, $slot, $attachment );
		} catch ( Throwable $e ) {
			wp_delete_attachment( $attachment, true );

			throw $e;
		}
	}

	/**
	 * A library sound's attachment id.
	 *
	 * @param int $sound Library sound id.
	 * @throws AlertSoundException When it is not in the library.
	 */
	private function library_sound( int $sound ): int {
		$found = $this->library?->find( $sound );

		if ( null === $found ) {
			throw new AlertSoundException( AlertSoundException::UNKNOWN_SOUND );
		}

		return $found->attachment_id;
	}

	/**
	 * Puts an attachment into a slot and deletes the slot's previous own file
	 * when no slot uses it any more.
	 *
	 * @param GlobalOverlay $overlay    Overlay.
	 * @param int           $slot       Slot.
	 * @param int           $attachment Attachment id; 0 empties the slot.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function assign( GlobalOverlay $overlay, int $slot, int $attachment ): GlobalOverlay {
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

		$changed = $this->store->update_settings( $overlay, OverlaySettings::from_array( $values ) );
		$in_use  = in_array( $previous, $values['audio_category_map'], true ) || $previous === $values['audio_default_sound'];

		if ( $previous > 0 && ! $in_use && self::owns( $previous ) ) {
			wp_delete_attachment( $previous, true );
		}

		return $changed;
	}

	/**
	 * Requires the default slot or a product category.
	 *
	 * @param int $slot Slot.
	 * @throws AlertSoundException When it is neither.
	 */
	private function check_slot( int $slot ): void {
		if ( 0 !== $slot && ! array_key_exists( $slot, ( $this->categories )() ) ) {
			throw new AlertSoundException( AlertSoundException::UNKNOWN_CATEGORY );
		}
	}

	/**
	 * A sound the global overlay may play: its own or a library sound.
	 *
	 * @param int $attachment Attachment id.
	 */
	private function sound( int $attachment ): ?AlertSound {
		if ( $attachment <= 0 ) {
			return null;
		}

		return self::owns( $attachment ) ? $this->files->sound( $attachment ) : $this->library?->find( $attachment );
	}

	/**
	 * Whether an attachment is one of the global overlay's own sounds.
	 *
	 * @param int $attachment Attachment id.
	 */
	public static function owns( int $attachment ): bool {
		return $attachment > 0 && 'attachment' === get_post_type( $attachment ) && '1' === (string) get_post_meta( $attachment, self::OWNER_META, true );
	}
}
