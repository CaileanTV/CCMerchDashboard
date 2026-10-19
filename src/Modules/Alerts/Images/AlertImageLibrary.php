<?php
/**
 * The administrator's alert image library.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Images;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Images an administrator offers to every creator to fill alert text
 * (phase 25, step 6.10), like the sound library (AlertSoundLibrary).
 *
 * - **Files:** attachments marked with LIBRARY_META, validated like the
 *   creators' uploads (ImageFiles). At most MAX_IMAGES.
 * - **Names:** each image has a name creators see: the administrator's, at
 *   most NAME_MAX characters, or the file name.
 * - **Changes:** only `merchboard_manage_alerts`; each is audited (the file
 *   name, never the file). A removed image is no longer shown anywhere:
 *   texts it filled show their colour again.
 */
final class AlertImageLibrary {

	/**
	 * Attachment meta: marks a library image (value 1).
	 */
	public const LIBRARY_META = '_merchboard_alert_image_library';

	/**
	 * Most images in the library.
	 */
	public const MAX_IMAGES = 50;

	/**
	 * Longest name in characters.
	 */
	public const NAME_MAX = 60;

	/**
	 * Constructor.
	 *
	 * @param ImageFiles  $files       Image files.
	 * @param AuditTrail  $audit       Audit trail.
	 * @param Transaction $transaction Database transaction.
	 */
	public function __construct( private ImageFiles $files, private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * The library, by name.
	 *
	 * @return list<AlertImage>
	 */
	public function all(): array {
		$images = array();

		foreach ( $this->ids() as $id ) {
			$image = $this->files->image( $id, true );

			if ( null !== $image ) {
				$images[] = $image;
			}
		}

		usort( $images, static fn( AlertImage $a, AlertImage $b ): int => strnatcasecmp( $a->name, $b->name ) );

		return $images;
	}

	/**
	 * A library image, or null when the attachment is not one.
	 *
	 * @param int $attachment Attachment id.
	 */
	public function find( int $attachment ): ?AlertImage {
		return $attachment > 0 && '1' === (string) get_post_meta( $attachment, self::LIBRARY_META, true ) ? $this->files->image( $attachment, true ) : null;
	}

	/**
	 * Adds an uploaded file to the library.
	 *
	 * @param array<mixed> $file Upload (one entry of $_FILES).
	 * @param string       $name Name creators see; '' for the file name.
	 * @throws AlertImageException When not authorized, the library is full or the file is refused (nothing is kept).
	 * @throws Throwable When the audit record cannot be written (DatabaseException; the file is deleted).
	 */
	public function add( array $file, string $name = '' ): AlertImage {
		$this->authorize();

		if ( count( $this->ids() ) >= self::MAX_IMAGES ) {
			throw new AlertImageException( AlertImageException::LIBRARY_FULL );
		}

		$id    = $this->files->store( $file, self::LIBRARY_META, 1 );
		$clean = self::clean_name( $name );

		if ( '' !== $clean ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $clean,
				)
			);
		}

		try {
			$this->transaction->run(
				function () use ( $id ): void {
					$this->audit->record( AuditAction::AlertImageLibraryAdded, null, array( 'name' => wp_basename( (string) get_attached_file( $id ) ) ) );
				}
			);
		} catch ( Throwable $e ) {
			wp_delete_attachment( $id, true );

			throw $e;
		}

		return $this->files->image( $id, true ) ?? new AlertImage( $id, '', '', 1, 1, true );
	}

	/**
	 * Gives a library image a new name ('' gives it its file name again).
	 *
	 * @param int    $attachment Attachment id.
	 * @param string $name       New name.
	 * @return bool Whether the name changed.
	 * @throws AlertImageException When not authorized or it is not a library image.
	 * @throws DatabaseException When it cannot be stored (nothing is changed).
	 */
	public function rename( int $attachment, string $name ): bool {
		$this->authorize();
		$image = $this->library_image( $attachment );
		$clean = self::clean_name( $name );
		$clean = '' === $clean ? sanitize_text_field( pathinfo( (string) get_attached_file( $attachment ), PATHINFO_FILENAME ) ) : $clean;

		if ( $clean === $image->name ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $attachment, $clean, $image ): void {
				$this->audit->record(
					AuditAction::AlertImageLibraryRenamed,
					null,
					array(
						'name'          => $clean,
						'previous_name' => $image->name,
					)
				);

				$updated = wp_update_post(
					array(
						'ID'         => $attachment,
						'post_title' => $clean,
					),
					true
				);

				if ( ! is_int( $updated ) ) {
					throw new DatabaseException( 'Could not rename the library image.' );
				}
			}
		);

		return true;
	}

	/**
	 * Removes an image from the library and deletes its file.
	 *
	 * @param int $attachment Attachment id.
	 * @return bool Whether it was a library image.
	 * @throws AlertImageException When not authorized.
	 * @throws DatabaseException When the audit record cannot be written (the file is kept).
	 */
	public function remove( int $attachment ): bool {
		$this->authorize();

		if ( '1' !== (string) get_post_meta( $attachment, self::LIBRARY_META, true ) ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $attachment ): void {
				$this->audit->record( AuditAction::AlertImageLibraryRemoved, null, array( 'name' => wp_basename( (string) get_attached_file( $attachment ) ) ) );
			}
		);
		wp_delete_attachment( $attachment, true );

		return true;
	}

	/**
	 * An image of the library.
	 *
	 * @param int $attachment Attachment id.
	 * @throws AlertImageException When it is not a library image.
	 */
	private function library_image( int $attachment ): AlertImage {
		$image = $this->find( $attachment );

		if ( null === $image ) {
			throw new AlertImageException( AlertImageException::UNKNOWN_IMAGE );
		}

		return $image;
	}

	/**
	 * A name as plain text of at most NAME_MAX characters.
	 *
	 * @param string $name Submitted name.
	 */
	private static function clean_name( string $name ): string {
		return trim( mb_substr( sanitize_text_field( $name ), 0, self::NAME_MAX ) );
	}

	/**
	 * The library's attachment ids.
	 *
	 * @return list<int>
	 */
	private function ids(): array {
		$ids = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => self::MAX_IMAGES,
				'fields'      => 'ids',
				'meta_key'    => self::LIBRARY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- At most MAX_IMAGES rows, admin and dashboard only.
				'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
			)
		);

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Requires the alerts capability (independent of the UI).
	 *
	 * @throws AlertImageException When the current user lacks it.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			throw new AlertImageException( AlertImageException::NOT_AUTHORIZED );
		}
	}
}
