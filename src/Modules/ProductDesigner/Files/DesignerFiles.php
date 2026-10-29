<?php
/**
 * The product designer's private files.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Files;

use Closure;
use MerchBoard\Modules\ProductDesigner\DesignerException;

defined( 'ABSPATH' ) || exit;

/**
 * Images of the product designer (ADR-080 pkt. 5): the layers of the base
 * products' mockup templates and the administrator's cliparts (step 30.6);
 * creators' uploads, print files and mockups follow.
 *
 * - **Upload:** PNG, JPG or WebP (never SVG); layers PNG only, so masks and
 *   shading keep their transparency. The extension and the file's real type
 *   are checked before the file is moved; the file must then read as an
 *   image of that type of at most MAX_BYTES and MAX_PIXELS. A refused file
 *   is deleted at once.
 * - **Storage:** in `uploads/merchboard-designer/` under a random name (32
 *   hex characters), next to an `index.php` and a `.htaccess` that denies
 *   access (and the nginx rule in the docs); never in the Media Library and
 *   never linked publicly. Served only through endpoints that check access.
 */
class DesignerFiles {

	/**
	 * Folder under the uploads folder.
	 */
	public const DIRECTORY = 'merchboard-designer';

	/**
	 * Largest file (25 MB).
	 */
	public const MAX_BYTES = 26214400;

	/**
	 * Largest width and height in pixels.
	 */
	public const MAX_PIXELS = 8000;

	/**
	 * A stored file's name.
	 */
	public const NAME_PATTERN = '/^[a-f0-9]{32}\.(png|jpg|webp)$/';

	/**
	 * Allowed extensions and types.
	 */
	public const TYPES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	);

	/**
	 * The image type (getimagesize()) and extension of each MIME type.
	 */
	private const IMAGE_TYPES = array(
		'image/jpeg' => array( IMAGETYPE_JPEG, 'jpg' ),
		'image/png'  => array( IMAGETYPE_PNG, 'png' ),
		'image/webp' => array( IMAGETYPE_WEBP, 'webp' ),
	);

	/**
	 * Moves an uploaded file to its place; true on success.
	 *
	 * @var Closure(string, string): bool
	 */
	private Closure $move;

	/**
	 * Constructor.
	 *
	 * @param (Closure(string, string): bool)|null $move File move; defaults to move_uploaded_file().
	 */
	public function __construct( ?Closure $move = null ) {
		$this->move = $move ?? static fn( string $from, string $to ): bool => move_uploaded_file( $from, $to );
	}

	/**
	 * The largest file this site accepts.
	 */
	public static function max_bytes(): int {
		$site = (int) wp_max_upload_size();

		return $site > 0 ? min( self::MAX_BYTES, $site ) : self::MAX_BYTES;
	}

	/**
	 * The folder.
	 */
	public static function directory(): string {
		return rtrim( (string) wp_upload_dir( null, false )['basedir'], '/\\' ) . '/' . self::DIRECTORY;
	}

	/**
	 * Validates an uploaded image and stores it.
	 *
	 * @param array<mixed> $file     Upload (one entry of $_FILES).
	 * @param bool         $png_only Whether only PNG is accepted (template layers).
	 * @return array{file: string, mime: string, width: int, height: int, bytes: int}
	 * @throws DesignerException When the file is refused (it is deleted again).
	 */
	public function store( array $file, bool $png_only = false ): array {
		$error = $file['error'] ?? null;

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			self::refuse( DesignerException::FILE_TOO_LARGE );
		}

		if ( UPLOAD_ERR_OK !== $error || ! is_string( $file['tmp_name'] ?? null ) || ! is_string( $file['name'] ?? null ) || '' === $file['tmp_name'] ) {
			self::refuse( DesignerException::FILE_MISSING );
		}

		$size = (int) filesize( $file['tmp_name'] );

		if ( $size <= 0 ) {
			self::refuse( DesignerException::FILE_MISSING );
		}

		if ( $size > self::max_bytes() ) {
			self::refuse( DesignerException::FILE_TOO_LARGE );
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::TYPES );
		$type  = $check['type'];

		if ( ! is_string( $type ) || ! isset( self::IMAGE_TYPES[ $type ] ) || ( $png_only && 'image/png' !== $type ) ) {
			self::refuse( DesignerException::FILE_TYPE );
		}

		$name = bin2hex( random_bytes( 16 ) ) . '.' . self::IMAGE_TYPES[ $type ][1];
		$path = self::prepare_directory() . '/' . $name;

		if ( ! ( $this->move )( $file['tmp_name'], $path ) ) {
			self::refuse( DesignerException::FILE_MISSING );
		}

		$image = wp_getimagesize( $path );

		if ( ! is_array( $image ) || self::IMAGE_TYPES[ $type ][0] !== $image[2] || $image[0] <= 0 || $image[1] <= 0 || $image[0] > self::MAX_PIXELS || $image[1] > self::MAX_PIXELS ) {
			wp_delete_file( $path );
			self::refuse( is_array( $image ) && self::IMAGE_TYPES[ $type ][0] === $image[2] ? DesignerException::FILE_TOO_LARGE : DesignerException::FILE_TYPE );
		}

		return array(
			'file'   => $name,
			'mime'   => $type,
			'width'  => (int) $image[0],
			'height' => (int) $image[1],
			'bytes'  => $size,
		);
	}

	/**
	 * The path of a stored file, or null when it is not there.
	 *
	 * @param string $name Stored name.
	 */
	public function path( string $name ): ?string {
		$path = self::directory() . '/' . $name;

		return 1 === preg_match( self::NAME_PATTERN, $name ) && is_file( $path ) ? $path : null;
	}

	/**
	 * Deletes a stored file (nothing when it is gone).
	 *
	 * @param string $name Stored name.
	 */
	public function delete( string $name ): void {
		$path = $this->path( $name );

		if ( null !== $path ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Deletes the folder (uninstall). Idempotent.
	 */
	public static function remove_all(): void {
		$directory = self::directory();

		if ( ! is_dir( $directory ) ) {
			return;
		}

		foreach ( (array) glob( $directory . '/{,.}*', GLOB_BRACE ) as $path ) {
			if ( is_string( $path ) && is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}

		rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The plugin's own, now empty folder.
	}

	/**
	 * The folder, created with its guards when missing.
	 *
	 * @throws DesignerException When it cannot be created.
	 */
	private static function prepare_directory(): string {
		$directory = self::directory();

		if ( ! wp_mkdir_p( $directory ) ) {
			self::refuse( DesignerException::FILE_MISSING );
		}

		$guards = array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
		);

		foreach ( $guards as $name => $contents ) {
			if ( ! is_file( $directory . '/' . $name ) && false === file_put_contents( $directory . '/' . $name, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A fixed guard file in the plugin's own folder.
				self::refuse( DesignerException::FILE_MISSING );
			}
		}

		return $directory;
	}

	/**
	 * Refuses a file.
	 *
	 * @param string $reason DesignerException reason.
	 * @throws DesignerException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new DesignerException( $reason );
	}
}
