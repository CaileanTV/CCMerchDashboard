<?php
/**
 * Images proposed for a creator's category.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Proposals;

use Closure;
use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * The images a creator proposes (category image, banner, mobile banner;
 * ADR-077 pkt. 5).
 *
 * - **Upload:** JPG, PNG or WebP. The extension and the file's real type are
 *   checked before the file is moved; the file must then read as an image of
 *   that type, at most MAX_BYTES and MAX_PIXELS, and at least the field's
 *   minimum size. The category image (logo) must be square; a larger one
 *   than LOGO_MAX_PIXELS is scaled down. A refused file is deleted at once.
 * - **Waiting:** in `uploads/merchboard-proposals/` under a random name (32
 *   hex characters), next to an `index.php` and a `.htaccess` that denies
 *   access; never in the Media Library and never linked publicly. It is shown
 *   only through endpoints for its creator and administrators.
 * - **Approved:** copied into the Media Library with its image sizes and
 *   marked with MEDIA_META (the creator); the waiting file is deleted.
 */
final class ProposalFiles {

	/**
	 * Folder under the uploads folder.
	 */
	public const DIRECTORY = 'merchboard-proposals';

	/**
	 * Marks a Media Library image the plugin created from an approved proposal
	 * (value: the creator id), so it may be deleted again once replaced.
	 */
	public const MEDIA_META = '_merchboard_category_media_creator';

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
		'webp'         => 'image/webp',
	);

	/**
	 * Smallest width and height per field (the banner plugin recommends
	 * banners of 1280 px or more).
	 */
	public const MINIMUM = array(
		'image'         => array( 300, 300 ),
		'banner'        => array( 1280, 1 ),
		'banner_mobile' => array( 600, 1 ),
	);

	/**
	 * The category image (the creator's logo) is square and at most this
	 * many pixels wide; a larger one is scaled down (owner's decision
	 * 2026-10-26).
	 */
	public const LOGO_MAX_PIXELS = 1000;

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
	 * The folder of waiting files.
	 */
	public static function directory(): string {
		return rtrim( (string) wp_upload_dir( null, false )['basedir'], '/\\' ) . '/' . self::DIRECTORY;
	}

	/**
	 * Validates an uploaded file and keeps it waiting.
	 *
	 * @param array<mixed>  $file  Upload (one entry of $_FILES).
	 * @param ProposalField $field Image field.
	 * @throws CategoryProposalException When the file is refused (it is deleted again).
	 */
	public function store( array $file, ProposalField $field ): ProposalFile {
		$error = $file['error'] ?? null;

		if ( ! $field->is_file() ) {
			throw new CategoryProposalException( CategoryProposalException::INVALID );
		}

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			throw new CategoryProposalException( CategoryProposalException::TOO_LARGE );
		}

		if ( UPLOAD_ERR_OK !== $error || ! is_string( $file['tmp_name'] ?? null ) || ! is_string( $file['name'] ?? null ) || '' === $file['tmp_name'] ) {
			throw new CategoryProposalException( CategoryProposalException::NO_FILE );
		}

		$size = (int) filesize( $file['tmp_name'] );

		if ( $size <= 0 ) {
			throw new CategoryProposalException( CategoryProposalException::NO_FILE );
		}

		if ( $size > self::max_bytes() ) {
			throw new CategoryProposalException( CategoryProposalException::TOO_LARGE );
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::TYPES );
		$type  = $check['type'];

		if ( ! is_string( $type ) || ! isset( self::IMAGE_TYPES[ $type ] ) ) {
			throw new CategoryProposalException( CategoryProposalException::INVALID_TYPE );
		}

		$name = bin2hex( random_bytes( 16 ) ) . '.' . self::IMAGE_TYPES[ $type ][1];
		$path = self::prepare_directory() . '/' . $name;

		if ( ! ( $this->move )( $file['tmp_name'], $path ) ) {
			throw new CategoryProposalException( CategoryProposalException::NO_FILE );
		}

		try {
			[ $width, $height ] = self::dimensions( $path, $type, $field );

			if ( ProposalField::Image === $field && $width > self::LOGO_MAX_PIXELS ) {
				[ $width, $height ] = self::scale_down( $path, $type );
			}
		} catch ( CategoryProposalException $e ) {
			wp_delete_file( $path );

			throw $e;
		}

		return new ProposalFile( $name, $type, $width, $height );
	}

	/**
	 * The path of a waiting file, or null when it is not there.
	 *
	 * @param ProposalFile $file File.
	 */
	public function path( ProposalFile $file ): ?string {
		$path = self::directory() . '/' . $file->file;

		return 1 === preg_match( ProposalFile::NAME_PATTERN, $file->file ) && is_file( $path ) ? $path : null;
	}

	/**
	 * Deletes a waiting file (nothing when it is gone).
	 *
	 * @param ProposalFile $file File.
	 */
	public function delete( ProposalFile $file ): void {
		$path = $this->path( $file );

		if ( null !== $path ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Copies an approved file into the Media Library with its image sizes and
	 * marks it as the creator's; the waiting file is deleted.
	 *
	 * @param ProposalFile $file       File.
	 * @param int          $creator_id Creator.
	 * @param string       $title      Title (and file name) in the Media Library.
	 * @return int Attachment id.
	 * @throws DatabaseException When the file is gone or cannot be added (nothing is kept).
	 */
	public function to_media( ProposalFile $file, int $creator_id, string $title ): int {
		$path     = $this->path( $file );
		$contents = null === $path ? false : file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of at most 5 MB.

		if ( false === $contents || ! isset( self::IMAGE_TYPES[ $file->mime ] ) ) {
			throw new DatabaseException( 'The proposed image is no longer there.' );
		}

		$extension = self::IMAGE_TYPES[ $file->mime ][1];
		$name      = sanitize_file_name( $title . '.' . $extension );
		$uploaded  = wp_upload_bits( '' !== $name && '.' . $extension !== $name ? $name : 'category.' . $extension, null, $contents );

		if ( false !== $uploaded['error'] ) {
			throw new DatabaseException( 'Could not add the approved image to the Media Library.' );
		}

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $file->mime,
				'post_title'     => sanitize_text_field( $title ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$uploaded['file'],
			0,
			true
		);

		if ( ! is_int( $id ) ) {
			wp_delete_file( $uploaded['file'] );

			throw new DatabaseException( 'Could not add the approved image to the Media Library.' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $uploaded['file'] ) );
		update_post_meta( $id, self::MEDIA_META, (string) $creator_id );
		$this->delete( $file );

		return $id;
	}

	/**
	 * Deletes the folder of waiting files (uninstall). Idempotent.
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
	 * The folder of waiting files, created with its guards when missing.
	 *
	 * @throws CategoryProposalException When it cannot be created.
	 */
	private static function prepare_directory(): string {
		$directory = self::directory();

		if ( ! wp_mkdir_p( $directory ) ) {
			throw new CategoryProposalException( CategoryProposalException::NO_FILE );
		}

		$guards = array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
		);

		foreach ( $guards as $name => $contents ) {
			if ( ! is_file( $directory . '/' . $name ) && false === file_put_contents( $directory . '/' . $name, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A fixed guard file in the plugin's own folder.
				throw new CategoryProposalException( CategoryProposalException::NO_FILE );
			}
		}

		return $directory;
	}

	/**
	 * The width and height of a stored file.
	 *
	 * @param string        $path  File.
	 * @param string        $type  MIME type.
	 * @param ProposalField $field Image field (its minimum).
	 * @return array{0: int, 1: int}
	 * @throws CategoryProposalException When it is not an image of the type, or too small or too big.
	 */
	private static function dimensions( string $path, string $type, ProposalField $field ): array {
		$size = wp_getimagesize( $path );

		if ( ! is_array( $size ) || self::IMAGE_TYPES[ $type ][0] !== $size[2] || $size[0] <= 0 || $size[1] <= 0 ) {
			throw new CategoryProposalException( CategoryProposalException::UNREADABLE );
		}

		if ( $size[0] > self::MAX_PIXELS || $size[1] > self::MAX_PIXELS ) {
			throw new CategoryProposalException( CategoryProposalException::TOO_BIG );
		}

		[ $min_width, $min_height ] = self::MINIMUM[ $field->value ] ?? array( 1, 1 );

		if ( $size[0] < $min_width || $size[1] < $min_height ) {
			throw new CategoryProposalException( CategoryProposalException::TOO_SMALL );
		}

		if ( ProposalField::Image === $field && $size[0] !== $size[1] ) {
			throw new CategoryProposalException( CategoryProposalException::NOT_SQUARE );
		}

		return array( $size[0], $size[1] );
	}

	/**
	 * Scales a stored logo down to LOGO_MAX_PIXELS (square) in place.
	 *
	 * @param string $path File.
	 * @param string $type MIME type.
	 * @return array{0: int, 1: int} The new width and height.
	 * @throws CategoryProposalException When it cannot be scaled (UNREADABLE).
	 */
	private static function scale_down( string $path, string $type ): array {
		$editor = wp_get_image_editor( $path );

		if ( is_wp_error( $editor ) || is_wp_error( $editor->resize( self::LOGO_MAX_PIXELS, self::LOGO_MAX_PIXELS ) ) || is_wp_error( $editor->save( $path, $type ) ) ) {
			throw new CategoryProposalException( CategoryProposalException::UNREADABLE );
		}

		$size = wp_getimagesize( $path );

		if ( ! is_array( $size ) || $size[0] > self::LOGO_MAX_PIXELS || $size[1] > self::LOGO_MAX_PIXELS ) {
			throw new CategoryProposalException( CategoryProposalException::UNREADABLE );
		}

		return array( $size[0], $size[1] );
	}
}
