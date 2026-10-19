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
 * - **Files:** attachments in the Media Library, marked with their owner
 *   (OWNER_META). Only the owner's attachments are ever played, listed or
 *   deleted, so a settings value can never reach another creator's file.
 * - **Upload:** MP3, WAV or OGG. The extension and the file's real type are
 *   checked before the file is moved, the file is then read as audio (its
 *   format must match the extension), and its size and length must be
 *   within MAX_BYTES and MAX_SECONDS. Unlike MerchAlert, a file that cannot
 *   be read as audio is refused. A refused or unsaved file is deleted.
 * - **First sound:** uploading the overlay's first sound switches sounds on.
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
	 * Largest file (MerchAlert's default limit).
	 */
	public const MAX_BYTES = 10485760;

	/**
	 * Longest sound in seconds (MerchAlert's default limit).
	 */
	public const MAX_SECONDS = 30;

	/**
	 * Allowed extensions and types.
	 */
	public const TYPES = array(
		'mp3'     => 'audio/mpeg',
		'wav'     => 'audio/wav',
		'ogg|oga' => 'audio/ogg',
	);

	/**
	 * The getID3 file formats each extension may have.
	 */
	private const FORMATS = array(
		'mp3' => array( 'mp3' ),
		'wav' => array( 'wav', 'riff' ),
		'ogg' => array( 'ogg' ),
		'oga' => array( 'ogg' ),
	);

	/**
	 * The creator's categories: term id → name.
	 *
	 * @var Closure(int): array<int, string>
	 */
	private Closure $categories;

	/**
	 * Moves an uploaded file into the uploads folder; wp_handle_upload()'s result.
	 *
	 * @var Closure(array<string, mixed>, array<string, mixed>): array<string, mixed>
	 */
	private Closure $move;

	/**
	 * Reads audio metadata; wp_read_audio_metadata()'s result.
	 *
	 * @var Closure(string): mixed
	 */
	private Closure $analyze;

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
	 * @param AlertOverlayRepository                                                           $overlays   Overlays.
	 * @param Closure                                                                          $categories The creator's categories (term id → name).
	 * @param (Closure(array<string, mixed>, array<string, mixed>): array<string, mixed>)|null $move       File move; defaults to wp_handle_upload().
	 * @param (Closure(string): mixed)|null                                                    $analyze    Audio metadata; defaults to wp_read_audio_metadata().
	 * @param (Closure(int): list<int>)|null                                                   $ancestors  Parent categories; defaults to get_ancestors().
	 */
	public function __construct( private AlertOverlayRepository $overlays, Closure $categories, ?Closure $move = null, ?Closure $analyze = null, ?Closure $ancestors = null ) {
		$this->categories = $categories;
		$this->move       = $move ?? static function ( array $file, array $overrides ): array {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			return wp_handle_upload( $file, $overrides );
		};
		$this->analyze    = $analyze ?? static function ( string $path ): mixed {
			require_once ABSPATH . 'wp-admin/includes/media.php';

			return wp_read_audio_metadata( $path );
		};
		$this->ancestors  = $ancestors ?? static fn( int $term_id ): array => array_values( array_map( 'intval', get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) );
	}

	/**
	 * The largest file this site accepts.
	 */
	public static function max_bytes(): int {
		$site = (int) wp_max_upload_size();

		return $site > 0 ? min( self::MAX_BYTES, $site ) : self::MAX_BYTES;
	}

	/**
	 * The sounds of an overlay for the dashboard.
	 *
	 * @param AlertOverlay $overlay Overlay.
	 * @return array{enabled: bool, default: array{url: string, name: string, seconds: int}|null, categories: list<array{term_id: int, name: string, sound: array{url: string, name: string, seconds: int}|null}>, max_bytes: int, max_seconds: int}
	 */
	public function describe( AlertOverlay $overlay ): array {
		$values     = $overlay->settings->to_array();
		$categories = array();

		foreach ( ( $this->categories )( $overlay->creator_id ) as $term_id => $name ) {
			$categories[] = array(
				'term_id' => $term_id,
				'name'    => $name,
				'sound'   => $this->sound( $values['audio_category_map'][ $term_id ] ?? 0, $overlay->creator_id )?->to_array(),
			);
		}

		return array(
			'enabled'     => $values['audio_enabled'],
			'default'     => $this->sound( $values['audio_default_sound'], $overlay->creator_id )?->to_array(),
			'categories'  => $categories,
			'max_bytes'   => self::max_bytes(),
			'max_seconds' => self::MAX_SECONDS,
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
		$this->assign_new( $overlay, $slot, $this->store_file( $creator_id, $file ) );

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

		// The first sound switches sounds on: uploading one means wanting to hear it.
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
	 * Validates and stores an uploaded file as an attachment of the creator.
	 *
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $file       Upload.
	 * @return int Attachment id.
	 * @throws AlertSoundException When the file is refused (it is deleted again).
	 */
	private function store_file( int $creator_id, array $file ): int {
		$error = $file['error'] ?? null;

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			throw new AlertSoundException( AlertSoundException::TOO_LARGE );
		}

		if ( UPLOAD_ERR_OK !== $error || ! is_string( $file['tmp_name'] ?? null ) || ! is_string( $file['name'] ?? null ) || '' === $file['tmp_name'] ) {
			throw new AlertSoundException( AlertSoundException::NO_FILE );
		}

		$size = (int) filesize( $file['tmp_name'] );

		if ( $size <= 0 ) {
			throw new AlertSoundException( AlertSoundException::NO_FILE );
		}

		if ( $size > self::max_bytes() ) {
			throw new AlertSoundException( AlertSoundException::TOO_LARGE );
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::TYPES );
		$ext   = is_string( $check['ext'] ) ? strtolower( $check['ext'] ) : '';

		if ( ! isset( self::FORMATS[ $ext ] ) || ! is_string( $check['type'] ) ) {
			throw new AlertSoundException( AlertSoundException::INVALID_TYPE );
		}

		$moved = ( $this->move )(
			array(
				'name'     => $file['name'],
				'type'     => $check['type'],
				'tmp_name' => $file['tmp_name'],
				'error'    => UPLOAD_ERR_OK,
				'size'     => $size,
			),
			array(
				'test_form' => false,
				'mimes'     => self::TYPES,
			)
		);

		if ( ! is_string( $moved['file'] ?? null ) || isset( $moved['error'] ) ) {
			throw new AlertSoundException( AlertSoundException::NO_FILE );
		}

		$path = $moved['file'];

		try {
			$seconds = $this->audio_length( $path, $ext );

			return $this->insert_attachment( $creator_id, $path, $check['type'], $size, $seconds );
		} catch ( AlertSoundException $e ) {
			wp_delete_file( $path );

			throw $e;
		}
	}

	/**
	 * The length of a stored file in whole seconds.
	 *
	 * @param string $path File.
	 * @param string $ext  Extension.
	 * @throws AlertSoundException When it is not audio of the extension's format or too long.
	 */
	private function audio_length( string $path, string $ext ): int {
		wp_raise_memory_limit( 'admin' );
		$meta = ( $this->analyze )( $path );

		if ( ! is_array( $meta ) || ! in_array( $meta['fileformat'] ?? null, self::FORMATS[ $ext ], true ) || ! is_int( $meta['length'] ?? null ) || $meta['length'] < 0 ) {
			throw new AlertSoundException( AlertSoundException::UNREADABLE );
		}

		if ( $meta['length'] > self::MAX_SECONDS ) {
			throw new AlertSoundException( AlertSoundException::TOO_LONG );
		}

		return $meta['length'];
	}

	/**
	 * Adds a stored file to the Media Library, owned by the creator. The
	 * metadata is written directly: generating it would read embedded album
	 * art with GD or Imagick, which can exhaust the memory (as MerchAlert found).
	 *
	 * @param int    $creator_id Creator.
	 * @param string $path       File.
	 * @param string $type       MIME type.
	 * @param int    $size       Bytes.
	 * @param int    $seconds    Length.
	 * @return int Attachment id.
	 * @throws AlertSoundException When the attachment cannot be created.
	 */
	private function insert_attachment( int $creator_id, string $path, string $type, int $size, int $seconds ): int {
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $type,
				'post_title'     => sanitize_text_field( pathinfo( $path, PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$path,
			0,
			true
		);

		if ( ! is_int( $id ) ) {
			throw new AlertSoundException( AlertSoundException::NO_FILE );
		}

		wp_update_attachment_metadata(
			$id,
			array(
				'filesize'  => $size,
				'mime_type' => $type,
				'length'    => $seconds,
			)
		);
		update_post_meta( $id, self::OWNER_META, $creator_id );

		return $id;
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
	 * A sound the creator owns, or null.
	 *
	 * @param int $attachment Attachment id.
	 * @param int $creator_id Creator.
	 */
	private function sound( int $attachment, int $creator_id ): ?AlertSound {
		if ( $attachment <= 0 || ! $this->owns( $attachment, $creator_id ) ) {
			return null;
		}

		$url  = wp_get_attachment_url( $attachment );
		$host = is_string( $url ) ? wp_parse_url( $url, PHP_URL_HOST ) : null;
		$type = get_post_mime_type( $attachment );

		if ( ! is_string( $url ) || ! is_string( $host ) || wp_parse_url( home_url(), PHP_URL_HOST ) !== $host || ! is_string( $type ) || ! str_starts_with( $type, 'audio/' ) ) {
			return null;
		}

		$meta = wp_get_attachment_metadata( $attachment );

		return new AlertSound( $attachment, $url, wp_basename( $url ), is_array( $meta ) && is_int( $meta['length'] ?? null ) ? $meta['length'] : 0 );
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
