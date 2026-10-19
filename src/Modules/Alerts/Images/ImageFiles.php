<?php
/**
 * Alert image files.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Images;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * Validates, stores and reads the images that fill alert text (phase 25,
 * step 6.10), for the creators' own images (AlertImages) and the
 * administrator's library (AlertImageLibrary).
 *
 * - **Upload:** PNG, JPG, GIF or WebP. The extension and the file's real
 *   type are checked before the file is moved, the file is then read as an
 *   image (its type must match), and its size and dimensions must be within
 *   MAX_BYTES and MAX_PIXELS. A refused file is deleted at once.
 * - **Storage:** an attachment in the Media Library, marked with one meta
 *   key (the owner or the library). No smaller sizes are generated: the
 *   overlay shows the original, and an animated GIF keeps its animation.
 * - **Reading:** only image attachments with a URL on this site.
 */
final class ImageFiles {

	/**
	 * Largest file.
	 */
	public const MAX_BYTES = 5242880;

	/**
	 * Largest width and height in pixels.
	 */
	public const MAX_PIXELS = 4096;

	/**
	 * Allowed extensions and types.
	 */
	public const TYPES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
	);

	/**
	 * The image type (getimagesize()) each MIME type must have.
	 */
	private const IMAGE_TYPES = array(
		'image/jpeg' => IMAGETYPE_JPEG,
		'image/png'  => IMAGETYPE_PNG,
		'image/gif'  => IMAGETYPE_GIF,
		'image/webp' => IMAGETYPE_WEBP,
	);

	/**
	 * Moves an uploaded file into the uploads folder; wp_handle_upload()'s result.
	 *
	 * @var Closure(array<string, mixed>, array<string, mixed>): array<string, mixed>
	 */
	private Closure $move;

	/**
	 * Constructor.
	 *
	 * @param (Closure(array<string, mixed>, array<string, mixed>): array<string, mixed>)|null $move File move; defaults to wp_handle_upload().
	 */
	public function __construct( ?Closure $move = null ) {
		$this->move = $move ?? static function ( array $file, array $overrides ): array {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			return wp_handle_upload( $file, $overrides );
		};
	}

	/**
	 * The largest file this site accepts.
	 */
	public static function max_bytes(): int {
		$site = (int) wp_max_upload_size();

		return $site > 0 ? min( self::MAX_BYTES, $site ) : self::MAX_BYTES;
	}

	/**
	 * Validates and stores an uploaded file as an attachment with a marker.
	 *
	 * @param array<mixed> $file  Upload (one entry of $_FILES).
	 * @param string       $key   Marker meta key.
	 * @param int          $value Marker value.
	 * @return int Attachment id.
	 * @throws AlertImageException When the file is refused (it is deleted again).
	 */
	public function store( array $file, string $key, int $value ): int {
		$error = $file['error'] ?? null;

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			throw new AlertImageException( AlertImageException::TOO_LARGE );
		}

		if ( UPLOAD_ERR_OK !== $error || ! is_string( $file['tmp_name'] ?? null ) || ! is_string( $file['name'] ?? null ) || '' === $file['tmp_name'] ) {
			throw new AlertImageException( AlertImageException::NO_FILE );
		}

		$size = (int) filesize( $file['tmp_name'] );

		if ( $size <= 0 ) {
			throw new AlertImageException( AlertImageException::NO_FILE );
		}

		if ( $size > self::max_bytes() ) {
			throw new AlertImageException( AlertImageException::TOO_LARGE );
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::TYPES );
		$type  = $check['type'];

		if ( ! is_string( $type ) || ! isset( self::IMAGE_TYPES[ $type ] ) || ! is_string( $check['ext'] ) ) {
			throw new AlertImageException( AlertImageException::INVALID_TYPE );
		}

		$moved = ( $this->move )(
			array(
				'name'     => $file['name'],
				'type'     => $type,
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
			throw new AlertImageException( AlertImageException::NO_FILE );
		}

		$path = $moved['file'];

		try {
			[ $width, $height ] = self::dimensions( $path, $type );

			return $this->insert_attachment( $path, $type, $size, $width, $height, $key, $value );
		} catch ( AlertImageException $e ) {
			wp_delete_file( $path );

			throw $e;
		}
	}

	/**
	 * An image attachment on this site, or null.
	 *
	 * @param int  $attachment Attachment id.
	 * @param bool $library    Whether it is a library image.
	 */
	public function image( int $attachment, bool $library = false ): ?AlertImage {
		if ( $attachment <= 0 || 'attachment' !== get_post_type( $attachment ) ) {
			return null;
		}

		$url  = wp_get_attachment_url( $attachment );
		$host = is_string( $url ) ? wp_parse_url( $url, PHP_URL_HOST ) : null;
		$type = get_post_mime_type( $attachment );
		$meta = wp_get_attachment_metadata( $attachment );

		if ( ! is_string( $url ) || ! is_string( $host ) || wp_parse_url( home_url(), PHP_URL_HOST ) !== $host || ! is_string( $type ) || ! isset( self::IMAGE_TYPES[ $type ] ) || ! is_array( $meta ) || ! is_int( $meta['width'] ?? null ) || ! is_int( $meta['height'] ?? null ) ) {
			return null;
		}

		$title = trim( (string) get_the_title( $attachment ) );

		// A library image shows its name (the administrator's, else the file's); a creator's image its file name.
		return new AlertImage( $attachment, $url, $library && '' !== $title ? $title : wp_basename( $url ), $meta['width'], $meta['height'], $library );
	}

	/**
	 * The width and height of a stored file.
	 *
	 * @param string $path File.
	 * @param string $type MIME type.
	 * @return array{0: int, 1: int}
	 * @throws AlertImageException When it is not an image of the type or too big.
	 */
	private static function dimensions( string $path, string $type ): array {
		$size = wp_getimagesize( $path );

		if ( ! is_array( $size ) || ( self::IMAGE_TYPES[ $type ] ?? null ) !== $size[2] || $size[0] <= 0 || $size[1] <= 0 ) {
			throw new AlertImageException( AlertImageException::UNREADABLE );
		}

		if ( $size[0] > self::MAX_PIXELS || $size[1] > self::MAX_PIXELS ) {
			throw new AlertImageException( AlertImageException::TOO_BIG );
		}

		return array( $size[0], $size[1] );
	}

	/**
	 * Adds a stored file to the Media Library with its marker.
	 *
	 * @param string $path   File.
	 * @param string $type   MIME type.
	 * @param int    $size   Bytes.
	 * @param int    $width  Width.
	 * @param int    $height Height.
	 * @param string $key    Marker meta key.
	 * @param int    $value  Marker value.
	 * @return int Attachment id.
	 * @throws AlertImageException When the attachment cannot be created.
	 */
	private function insert_attachment( string $path, string $type, int $size, int $width, int $height, string $key, int $value ): int {
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
			throw new AlertImageException( AlertImageException::NO_FILE );
		}

		wp_update_attachment_metadata(
			$id,
			array(
				'width'     => $width,
				'height'    => $height,
				'filesize'  => $size,
				'mime_type' => $type,
			)
		);
		update_post_meta( $id, $key, $value );

		return $id;
	}
}
