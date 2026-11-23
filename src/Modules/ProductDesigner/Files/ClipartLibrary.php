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
 * audited.
 *
 * A clipart is removed, never deleted (ADR-121 pkt. 7): its row and files
 * stay for the designs that use it, and new designs cannot choose it.
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
	 * Adds a clipart baked in the administrator's browser from a PSD
	 * (ADR-121): its preview, the atlas of its parts and its definition. The
	 * definition is checked whole; the atlas' file is the one stored here.
	 *
	 * @param string       $name       Name shown in the designer.
	 * @param array<mixed> $preview    Upload of the preview (PNG).
	 * @param array<mixed> $atlas      Upload of the atlas (PNG); empty for a clipart without parts.
	 * @param string       $definition The definition as JSON.
	 * @return int The asset id.
	 * @throws DesignerException When the user may not, or the name, a file or the definition is refused.
	 * @throws DatabaseException When it cannot be stored (the files are deleted again).
	 */
	public function add_artwork( string $name, array $preview, array $atlas, string $definition ): int {
		self::authorize();

		$name = trim( sanitize_text_field( $name ) );

		if ( '' === $name || mb_strlen( $name ) > 100 ) {
			self::reject( DesignerException::CLIPART_NAME );
		}

		$data = json_decode( $definition, true );

		if ( ! is_array( $data ) ) {
			self::reject( DesignerException::CLIPART_DEFINITION );
		}

		$parts  = is_array( $data['parts'] ?? null ) && array() !== $data['parts'];
		$stored = array();
		$id     = null;

		try {
			$shown    = $this->files->store( $preview );
			$stored[] = $shown['file'];
			$packed   = $parts ? $this->files->store( $atlas ) : null;

			if ( null !== $packed ) {
				$stored[] = $packed['file'];
			}

			$wanted = is_array( $data['atlas'] ?? null ) ? $data['atlas'] : array();

			// Transparent PNGs only; the atlas as large as the definition says.
			if ( 'image/png' !== $shown['mime'] || ( null !== $packed && ( 'image/png' !== $packed['mime'] || ( $wanted['width'] ?? null ) !== $packed['width'] || ( $wanted['height'] ?? null ) !== $packed['height'] ) ) ) {
				self::reject( DesignerException::CLIPART_DEFINITION );
			}

			$data['atlas'] = null === $packed ? null : array(
				'file'   => $packed['file'],
				'width'  => $packed['width'],
				'height' => $packed['height'],
			);
			$read          = ClipartDefinition::from_data( $data, $shown['width'], $shown['height'] ) ?? self::reject( DesignerException::CLIPART_DEFINITION );

			$id = $this->transaction->run(
				function () use ( $name, $shown, $read ): int {
					$id = $this->assets->insert_artwork( $name, $shown, $read );
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
				foreach ( $stored as $file ) {
					$this->files->delete( $file );
				}
			}
		}

		return $id;
	}

	/**
	 * Changes the base products a clipart with a definition is offered on;
	 * nothing else of it can change (ADR-121 pkt. 2).
	 *
	 * @param int        $id    Asset id.
	 * @param int[]|null $bases Base product ids; null for all.
	 * @throws DesignerException When the user may not, or it is no clipart of the library with a definition.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set_bases( int $id, ?array $bases ): void {
		self::authorize();

		$clipart = $this->assets->find_clipart( $id );

		if ( null === $clipart || null !== $clipart->retired_at || null === $clipart->definition ) {
			self::reject( DesignerException::CLIPART_UNKNOWN );
		}

		$ids = null === $bases ? null : array_values( array_unique( array_filter( array_map( 'intval', $bases ), static fn( int $base ): bool => $base > 0 ) ) );

		if ( array() === $ids ) {
			self::reject( DesignerException::CLIPART_BASES );
		}

		$this->transaction->run(
			function () use ( $clipart, $ids ): void {
				$this->assets->set_bases( $clipart, $ids );
				$this->audit->record(
					AuditAction::DesignerClipartChanged,
					null,
					array(
						'asset_id'   => $clipart->id,
						'asset_name' => $clipart->name,
						'bases'      => $ids,
					)
				);
			}
		);
	}

	/**
	 * Removes a clipart from new designs. Its row and files stay.
	 *
	 * @param int $id Asset id.
	 * @throws DesignerException When the user may not, or it is not a clipart in the library.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function retire( int $id ): void {
		self::authorize();

		$clipart = $this->assets->find_clipart( $id );

		if ( null === $clipart || null !== $clipart->retired_at ) {
			self::reject( DesignerException::CLIPART_UNKNOWN );
		}

		$this->transaction->run(
			function () use ( $clipart ): void {
				// Two clicks at once: only the one that removed it is audited.
				if ( ! $this->assets->retire( $clipart->id ) ) {
					throw new DesignerException( DesignerException::CLIPART_UNKNOWN );
				}

				$this->audit->record(
					AuditAction::DesignerClipartRetired,
					null,
					array(
						'asset_id'   => $clipart->id,
						'asset_name' => $clipart->name,
					)
				);
			}
		);
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
