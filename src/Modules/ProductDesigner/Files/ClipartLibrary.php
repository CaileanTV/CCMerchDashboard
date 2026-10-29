<?php
/**
 * The administrator's cliparts for the designer.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Files;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\ProductDesigner\DesignerException;

defined( 'ABSPATH' ) || exit;

/**
 * Cliparts (ADR-080, step 30.6): images the administrator offers every
 * creator in the designer, stored like the other designer files (PNG, JPG
 * or WebP, private folder). For `merchboard_manage_designer`; each change
 * audited. A clipart used in a design is kept by its design's files (step
 * 30.9 renders the print files at submission).
 */
class ClipartLibrary {

	/**
	 * Constructor.
	 *
	 * @param DesignerAssetRepository $assets      File records.
	 * @param DesignerFiles           $files       Files.
	 * @param AuditTrail              $audit       Audit trail.
	 * @param Transaction             $transaction Transaction.
	 */
	public function __construct( private DesignerAssetRepository $assets, private DesignerFiles $files, private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * Adds a clipart.
	 *
	 * @param string       $name   Name shown in the designer.
	 * @param array<mixed> $upload Upload (one entry of $_FILES).
	 * @return int The asset id.
	 * @throws DesignerException When the user may not, or the name or file is refused.
	 * @throws DatabaseException When it cannot be stored (the file is deleted again).
	 */
	public function add( string $name, array $upload ): int {
		self::authorize();

		$name = trim( sanitize_text_field( $name ) );

		if ( '' === $name || mb_strlen( $name ) > 100 ) {
			self::reject( DesignerException::CLIPART_NAME );
		}

		$stored = $this->files->store( $upload );
		$id     = null;

		try {
			$id = $this->transaction->run(
				function () use ( $name, $stored ): int {
					$id = $this->assets->insert( DesignerAssetRepository::CLIPART, $name, $stored );
					$this->audit->record(
						AuditAction::DesignerClipartAdded,
						null,
						array(
							'asset_id'   => $id,
							'asset_name' => $name,
						)
					);

					return $id;
				}
			);
		} finally {
			// A refused or failed change keeps no file.
			if ( null === $id ) {
				$this->files->delete( $stored['file'] );
			}
		}

		return $id;
	}

	/**
	 * Deletes a clipart and its file.
	 *
	 * @param int $id Asset id.
	 * @throws DesignerException When the user may not, or it is not a clipart.
	 * @throws DatabaseException When it cannot be deleted.
	 */
	public function delete( int $id ): void {
		self::authorize();

		$asset = $this->assets->find( $id );

		if ( null === $asset || DesignerAssetRepository::CLIPART !== $asset['kind'] || null !== $asset['creator_id'] ) {
			self::reject( DesignerException::CLIPART_UNKNOWN );
		}

		$this->transaction->run(
			function () use ( $id, $asset ): void {
				$this->assets->delete( $id );
				$this->audit->record(
					AuditAction::DesignerClipartDeleted,
					null,
					array(
						'asset_id'   => $id,
						'asset_name' => $asset['name'],
					)
				);
			}
		);

		$this->files->delete( $asset['file'] );
	}

	/**
	 * Refuses with a reason.
	 *
	 * @param string $reason DesignerException reason.
	 * @throws DesignerException Always.
	 */
	private static function reject( string $reason ): never {
		throw new DesignerException( $reason );
	}

	/**
	 * Requires `merchboard_manage_designer`.
	 *
	 * @throws DesignerException When the current user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			throw new DesignerException( DesignerException::NOT_AUTHORIZED );
		}
	}
}
