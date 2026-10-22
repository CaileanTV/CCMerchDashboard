<?php
/**
 * Alert sound files.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Sounds;

use Closure;
use MerchBoard\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Validates, stores and reads alert sound files (ADR-065 pkt. 3), for the
 * creators' own sounds (AlertSounds) and the administrator's library
 * (AlertSoundLibrary).
 *
 * - **Upload:** MP3, WAV or OGG. The extension and the file's real type are
 *   checked before the file is moved, the file is then read as audio (its
 *   format must match the extension), and its size and length must be
 *   within the limits of the upload: the site-wide max_bytes() and
 *   max_seconds() (Settings → Alerts, step 25.9.2), or a creator's own
 *   (CreatorSoundLimits); checked at upload only, so stored sounds stay. Unlike MerchAlert, a
 *   file that cannot be read as audio is refused. A refused file is deleted
 *   at once.
 * - **Storage:** an attachment in the Media Library, marked with one meta
 *   key (the owner or the library), so the callers can tell their files
 *   apart from any other attachment.
 * - **Reading:** only audio attachments with a URL on this site.
 */
final class SoundFiles {

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
	 * Constructor.
	 *
	 * @param (Closure(array<string, mixed>, array<string, mixed>): array<string, mixed>)|null $move    File move; defaults to wp_handle_upload().
	 * @param (Closure(string): mixed)|null                                                    $analyze Audio metadata; defaults to wp_read_audio_metadata().
	 */
	public function __construct( ?Closure $move = null, ?Closure $analyze = null ) {
		$this->move    = $move ?? static function ( array $file, array $overrides ): array {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			return wp_handle_upload( $file, $overrides );
		};
		$this->analyze = $analyze ?? static function ( string $path ): mixed {
			require_once ABSPATH . 'wp-admin/includes/media.php';

			return wp_read_audio_metadata( $path );
		};
	}

	/**
	 * The largest file: the administrator's limit, or the site's upload
	 * limit when that is smaller.
	 */
	public static function max_bytes(): int {
		return self::within_site_limit( Settings::sound_max_bytes() );
	}

	/**
	 * A largest file, or the site's upload limit when that is smaller.
	 *
	 * @param int $bytes Largest file in bytes.
	 */
	public static function within_site_limit( int $bytes ): int {
		$site = (int) wp_max_upload_size();

		return $site > 0 ? min( $bytes, $site ) : $bytes;
	}

	/**
	 * The longest sound in seconds (the administrator's limit).
	 */
	public static function max_seconds(): int {
		return Settings::sound_max_seconds();
	}

	/**
	 * Validates and stores an uploaded file as an attachment with a marker.
	 *
	 * @param array<mixed>     $file   Upload (one entry of $_FILES).
	 * @param string           $key    Marker meta key.
	 * @param int              $value  Marker value.
	 * @param SoundLimits|null $limits Limits; null for the site-wide ones.
	 * @return int Attachment id.
	 * @throws AlertSoundException When the file is refused (it is deleted again).
	 */
	public function store( array $file, string $key, int $value, ?SoundLimits $limits = null ): int {
		$limits ??= SoundLimits::site_wide();
		$error    = $file['error'] ?? null;

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

		if ( $size > $limits->max_bytes ) {
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
			$seconds = $this->audio_length( $path, $ext, $limits->max_seconds );

			return $this->insert_attachment( $path, $check['type'], $size, $seconds, $key, $value );
		} catch ( AlertSoundException $e ) {
			wp_delete_file( $path );

			throw $e;
		}
	}

	/**
	 * An audio attachment on this site, or null.
	 *
	 * @param int  $attachment Attachment id.
	 * @param bool $library    Whether it is a library sound.
	 */
	public function sound( int $attachment, bool $library = false ): ?AlertSound {
		if ( $attachment <= 0 || 'attachment' !== get_post_type( $attachment ) ) {
			return null;
		}

		$url  = wp_get_attachment_url( $attachment );
		$host = is_string( $url ) ? wp_parse_url( $url, PHP_URL_HOST ) : null;
		$type = get_post_mime_type( $attachment );

		if ( ! is_string( $url ) || ! is_string( $host ) || wp_parse_url( home_url(), PHP_URL_HOST ) !== $host || ! is_string( $type ) || ! str_starts_with( $type, 'audio/' ) ) {
			return null;
		}

		$meta  = wp_get_attachment_metadata( $attachment );
		$title = trim( (string) get_the_title( $attachment ) );

		// A library sound shows its name (the administrator's, else the file's); a creator's sound its file name.
		return new AlertSound( $attachment, $url, $library && '' !== $title ? $title : wp_basename( $url ), is_array( $meta ) && is_int( $meta['length'] ?? null ) ? $meta['length'] : 0, $library );
	}

	/**
	 * The length of a stored file in whole seconds.
	 *
	 * @param string $path        File.
	 * @param string $ext         Extension.
	 * @param int    $max_seconds Longest sound.
	 * @throws AlertSoundException When it is not audio of the extension's format or too long.
	 */
	private function audio_length( string $path, string $ext, int $max_seconds ): int {
		wp_raise_memory_limit( 'admin' );
		$meta = ( $this->analyze )( $path );

		if ( ! is_array( $meta ) || ! in_array( $meta['fileformat'] ?? null, self::FORMATS[ $ext ], true ) || ! is_int( $meta['length'] ?? null ) || $meta['length'] < 0 ) {
			throw new AlertSoundException( AlertSoundException::UNREADABLE );
		}

		if ( $meta['length'] > $max_seconds ) {
			throw new AlertSoundException( AlertSoundException::TOO_LONG );
		}

		return $meta['length'];
	}

	/**
	 * Adds a stored file to the Media Library with its marker. The metadata
	 * is written directly: generating it would read embedded album art with
	 * GD or Imagick, which can exhaust the memory (as MerchAlert found).
	 *
	 * @param string $path    File.
	 * @param string $type    MIME type.
	 * @param int    $size    Bytes.
	 * @param int    $seconds Length.
	 * @param string $key     Marker meta key.
	 * @param int    $value   Marker value.
	 * @return int Attachment id.
	 * @throws AlertSoundException When the attachment cannot be created.
	 */
	private function insert_attachment( string $path, string $type, int $size, int $seconds, string $key, int $value ): int {
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
		update_post_meta( $id, $key, $value );

		return $id;
	}
}
