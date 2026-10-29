<?php
/**
 * The mockups of an approved version in the media library.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Shop;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\ProductDesigner\Designs\SubmissionFiles;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-080 pkt. 5: the mockups an administrator approved become the product
 * images. Each is copied from the private designer folder into the media
 * library once: the attachment carries `_merchboard_designer_mockup` =
 * `<version id>:<slot>`, so a repeated apply finds it instead of a second
 * copy. Print files never leave the private folder.
 */
class DesignerMedia {

	public const META = '_merchboard_designer_mockup';

	/**
	 * Constructor.
	 *
	 * @param DesignerFiles $files The private files.
	 */
	public function __construct( private DesignerFiles $files ) {
	}

	/**
	 * The attachment of every mockup of a version, by slot
	 * (`mockup:<side>:<colour>`), importing those not imported yet.
	 *
	 * @param int                  $version_id Version.
	 * @param array<string, mixed> $files      SubmissionFiles::decode() of the version.
	 * @param int                  $owner      Product the attachments belong to.
	 * @param string               $title      Product title (the images' title and alt text).
	 * @return array<string, int>
	 * @throws DatabaseException When a mockup is missing or cannot be imported.
	 */
	public function import( int $version_id, array $files, int $owner, string $title ): array {
		$attachments = array();
		$mockups     = is_array( $files['mockups'] ?? null ) ? $files['mockups'] : array();

		foreach ( $mockups as $side => $colors ) {
			foreach ( is_array( $colors ) ? $colors : array() as $color => $file ) {
				$slot                 = SubmissionFiles::MOCKUP . ':' . $side . ':' . $color;
				$attachments[ $slot ] = $this->existing( $version_id, $slot ) ?? $this->copy( $version_id, $slot, is_array( $file ) ? $file : array(), $owner, $title );
			}
		}

		return $attachments;
	}

	/**
	 * The attachment imported earlier for a slot, or null.
	 *
	 * @param int    $version_id Version.
	 * @param string $slot       Slot.
	 */
	private function existing( int $version_id, string $slot ): ?int {
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'numberposts'      => 1,
				'suppress_filters' => true,
				'meta_key'         => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Once per mockup and apply.
				'meta_value'       => $version_id . ':' . $slot, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Once per mockup and apply.
			)
		);

		return array() === $ids ? null : (int) $ids[0];
	}

	/**
	 * Copies a mockup into the media library.
	 *
	 * @param int                  $version_id Version.
	 * @param string               $slot       Slot.
	 * @param array<string, mixed> $file       Stored file.
	 * @param int                  $owner      Product.
	 * @param string               $title      Title.
	 * @throws DatabaseException When it cannot be copied.
	 */
	private function copy( int $version_id, string $slot, array $file, int $owner, string $title ): int {
		$path = is_string( $file['file'] ?? null ) ? $this->files->path( $file['file'] ) : null;
		$body = null === $path ? false : file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of at most 25 MB.

		if ( false === $body ) {
			throw new DatabaseException( 'A mockup of the design is missing.', array( 'version_id' => $version_id ) );
		}

		$extension = 'image/png' === ( $file['mime'] ?? '' ) ? 'png' : ( 'image/webp' === ( $file['mime'] ?? '' ) ? 'webp' : 'jpg' );
		$name      = sanitize_file_name( sanitize_title( $title ) . '-' . str_replace( ':', '-', substr( $slot, strlen( SubmissionFiles::MOCKUP ) + 1 ) ) . '.' . $extension );
		$upload    = wp_upload_bits( '' === $name ? 'mockup.jpg' : $name, null, $body );

		if ( false !== $upload['error'] ) {
			throw new DatabaseException( 'Could not store a mockup in the media library.', array( 'version_id' => $version_id ) );
		}

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => (string) ( $file['mime'] ?? 'image/jpeg' ),
				'post_title'     => $title,
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$owner,
			true
		);

		if ( ! is_int( $id ) ) {
			wp_delete_file( $upload['file'] );
			throw new DatabaseException( 'Could not add a mockup to the media library.', array( 'version_id' => $version_id ) );
		}

		update_post_meta( $id, self::META, $version_id . ':' . $slot );
		update_post_meta( $id, '_wp_attachment_image_alt', $title );

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );

		return $id;
	}
}
