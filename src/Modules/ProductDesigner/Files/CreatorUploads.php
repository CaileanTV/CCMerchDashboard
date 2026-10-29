<?php
/**
 * The creator's own images for the designer.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Files;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\ProductDesigner\DesignerException;

defined( 'ABSPATH' ) || exit;

/**
 * A creator's uploads (step 30.8, ADR-080 pkt. 4–5): PNG, JPG or WebP,
 * never SVG, stored privately like every designer file, at most MAX_FILES
 * files and MAX_TOTAL_BYTES per creator. Only the creator (and an
 * administrator viewing as the creator) sees and uses them; a design may
 * use the creator's own uploads and the cliparts.
 */
class CreatorUploads {

	public const UPLOAD = 'upload';

	/**
	 * Most files per creator.
	 */
	public const MAX_FILES = 200;

	/**
	 * Most bytes per creator (1 GB).
	 */
	public const MAX_TOTAL_BYTES = 1073741824;

	/**
	 * Constructor.
	 *
	 * @param DesignerAssetRepository $assets File records.
	 * @param DesignerFiles           $files  Files.
	 */
	public function __construct( private DesignerAssetRepository $assets, private DesignerFiles $files ) {
	}

	/**
	 * Stores an upload of a creator.
	 *
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $upload     Upload (one entry of $_FILES).
	 * @return array{id: int, creator_id: int|null, kind: string, name: string, file: string, mime: string, width: int, height: int, bytes: int, created_at: string}
	 * @throws DesignerException When the quota is used up or the file is refused.
	 * @throws DatabaseException When it cannot be stored (the file is deleted again).
	 */
	public function add( int $creator_id, array $upload ): array {
		// One upload of a creator at a time, so parallel uploads cannot pass the quota together (review 30.15).
		$this->assets->lock_owner( $creator_id );

		try {
			$own   = $this->assets->owned( $creator_id, self::UPLOAD );
			$bytes = array_sum( array_column( $own, 'bytes' ) );
			$size  = is_string( $upload['tmp_name'] ?? null ) && is_file( $upload['tmp_name'] ) ? (int) filesize( $upload['tmp_name'] ) : 0;

			if ( count( $own ) >= self::MAX_FILES || $bytes + $size > self::MAX_TOTAL_BYTES ) {
				self::refuse( DesignerException::UPLOAD_QUOTA );
			}

			$stored = $this->files->store( $upload );
			$name   = is_string( $upload['name'] ?? null ) ? sanitize_file_name( $upload['name'] ) : '';
			$id     = null;

			try {
				$id = $this->assets->insert( self::UPLOAD, '' === $name ? 'image' : $name, $stored, $creator_id );
			} finally {
				if ( null === $id ) {
					$this->files->delete( $stored['file'] );
				}
			}
		} finally {
			$this->assets->unlock_owner( $creator_id );
		}

		return $this->stored( $id );
	}

	/**
	 * The record just stored.
	 *
	 * @param int $id Asset id.
	 * @return array{id: int, creator_id: int|null, kind: string, name: string, file: string, mime: string, width: int, height: int, bytes: int, created_at: string}
	 * @throws DatabaseException When it is not there.
	 */
	private function stored( int $id ): array {
		$asset = $this->assets->find( $id );

		if ( null === $asset ) {
			throw new DatabaseException( 'The upload was not stored.' );
		}

		return $asset;
	}

	/**
	 * Deletes one of the creator's uploads and its file.
	 *
	 * @param int $creator_id Creator.
	 * @param int $asset_id   Asset.
	 * @throws DesignerException When it is not the creator's upload.
	 * @throws DatabaseException When it cannot be deleted.
	 */
	public function delete( int $creator_id, int $asset_id ): void {
		$asset = $this->assets->find( $asset_id );

		if ( null === $asset || $creator_id !== $asset['creator_id'] || self::UPLOAD !== $asset['kind'] ) {
			self::refuse( DesignerException::UPLOAD_UNKNOWN );
		}

		$this->assets->delete( $asset_id );
		$this->files->delete( $asset['file'] );
	}

	/**
	 * Whether a creator may see an asset: their own uploads, cliparts and
	 * template layers.
	 *
	 * @param int                                       $creator_id Creator.
	 * @param array{creator_id: int|null, kind: string} $asset      Asset record.
	 */
	public static function may_see( int $creator_id, array $asset ): bool {
		return null === $asset['creator_id']
			? in_array( $asset['kind'], array( DesignerAssetRepository::CLIPART, DesignerAssetRepository::LAYER ), true )
			: $creator_id === $asset['creator_id'];
	}

	/**
	 * Whether a creator may put an asset in a design: their own uploads and
	 * cliparts.
	 *
	 * @param int                                       $creator_id Creator.
	 * @param array{creator_id: int|null, kind: string} $asset      Asset record.
	 */
	public static function may_use( int $creator_id, array $asset ): bool {
		return null === $asset['creator_id']
			? DesignerAssetRepository::CLIPART === $asset['kind']
			: $creator_id === $asset['creator_id'] && self::UPLOAD === $asset['kind'];
	}

	/**
	 * Refuses with a reason.
	 *
	 * @param string $reason DesignerException reason.
	 * @throws DesignerException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new DesignerException( $reason );
	}
}
