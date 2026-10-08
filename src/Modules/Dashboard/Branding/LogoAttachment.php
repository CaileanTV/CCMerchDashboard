<?php
/**
 * Validation of an admin-chosen logo attachment.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard\Branding;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a Media Library attachment may be the Merch Dashboard logo
 * (ADR-042). Used when the setting is saved and again whenever the logo is
 * shown (the attachment can be deleted or changed later). Never trusts the
 * client:
 *
 * - an existing, public attachment: unattached or attached to a published
 *   post (trashed attachments and media of private or draft posts are
 *   refused);
 * - a raster image only: the MIME type is PNG, JPEG or WebP and the file
 *   extension agrees with it. SVG is never accepted (SECURITY.md);
 * - real image dimensions within the bounds: at least 64 px high, at most
 *   2000 px on the longest side, between 1:1 and 6:1 (width:height);
 * - a URL on the site's own origin (the dashboard CSP allows images only
 *   from 'self'; offloaded media fall back to the default logo).
 */
final class LogoAttachment {

	/**
	 * Accepted MIME types.
	 */
	public const MIME_TYPES = array( 'image/png', 'image/jpeg', 'image/webp' );

	/**
	 * Smallest height in pixels (sharp in the header on HiDPI screens).
	 */
	public const MIN_HEIGHT = 64;

	/**
	 * Largest side in pixels.
	 */
	public const MAX_SIDE = 2000;

	/**
	 * Widest ratio (width / height); narrower than 1:1 is refused too.
	 */
	public const MAX_RATIO = 6;

	/**
	 * Problem codes.
	 */
	public const NOT_FOUND = 'not_found';

	public const WRONG_TYPE = 'wrong_type';

	public const WRONG_SIZE = 'wrong_size';

	public const FOREIGN_URL = 'foreign_url';

	/**
	 * The problem with an attachment as logo, or null when it is usable.
	 *
	 * @param int $id Attachment id.
	 */
	public function problem( int $id ): ?string {
		// get_post_status() resolves an attachment's `inherit` to its parent's
		// status, or `publish` when it is unattached.
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return self::NOT_FOUND;
		}

		$mime = (string) get_post_mime_type( $id );

		if ( ! in_array( $mime, self::MIME_TYPES, true ) || ! self::extension_matches( $id, $mime ) ) {
			return self::WRONG_TYPE;
		}

		if ( ! self::size_fits( wp_get_attachment_metadata( $id ) ) ) {
			return self::WRONG_SIZE;
		}

		return null === $this->image( $id ) ? self::FOREIGN_URL : null;
	}

	/**
	 * The full-size image of a usable logo attachment, or null.
	 *
	 * @param int $id Attachment id.
	 * @return array{url: string, width: int, height: int}|null
	 */
	public function usable( int $id ): ?array {
		return null === $this->problem( $id ) ? $this->image( $id ) : null;
	}

	/**
	 * The full-size image URL and size, when it is on the site's origin.
	 *
	 * @param int $id Attachment id.
	 * @return array{url: string, width: int, height: int}|null
	 */
	private function image( int $id ): ?array {
		$source = wp_get_attachment_image_src( $id, 'full' );

		if ( ! is_array( $source ) || ! BrandLogo::is_same_origin( $source[0] ) ) {
			return null;
		}

		$width  = (int) $source[1];
		$height = (int) $source[2];

		return $width > 0 && $height > 0 ? array(
			'url'    => $source[0],
			'width'  => $width,
			'height' => $height,
		) : null;
	}

	/**
	 * Whether the file's extension gives the same MIME type (no renamed files).
	 *
	 * @param int    $id   Attachment id.
	 * @param string $mime Stored MIME type.
	 */
	private static function extension_matches( int $id, string $mime ): bool {
		$file = get_attached_file( $id );

		if ( ! is_string( $file ) || '' === $file ) {
			return false;
		}

		$type = wp_check_filetype( wp_basename( $file ) );

		return $mime === $type['type'];
	}

	/**
	 * Whether the stored dimensions are within the bounds.
	 *
	 * @param mixed $metadata Attachment metadata.
	 */
	private static function size_fits( mixed $metadata ): bool {
		if ( ! is_array( $metadata ) || ! is_int( $metadata['width'] ?? null ) || ! is_int( $metadata['height'] ?? null ) ) {
			return false;
		}

		$width  = $metadata['width'];
		$height = $metadata['height'];

		return $height >= self::MIN_HEIGHT
			&& max( $width, $height ) <= self::MAX_SIDE
			&& $width >= $height
			&& $width <= self::MAX_RATIO * $height;
	}
}
