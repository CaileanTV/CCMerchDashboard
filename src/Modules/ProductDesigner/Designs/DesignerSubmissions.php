<?php
/**
 * Submitting a design for approval.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\DesignerNotifications;
use MerchBoard\Modules\ProductDesigner\DesignerPrices;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use OverflowException;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's submission (step 30.9, ADR-080 pkt. 3 and 5), for the
 * creator CreatorContext resolved (ADR-007); during "view as creator" the
 * routes are refused before they run (ImpersonationGuard).
 *
 * 1. attach(): the creator's browser renders the files of the saved draft and
 *    sends them one by one: a transparent PNG per used side in the side's
 *    exact print size and a mockup (PNG, JPG or WebP) per used side and
 *    chosen colour in the template's exact size (SubmissionFiles). A file of
 *    an earlier state of the draft is replaced.
 * 2. submit(): the draft waits for approval (`pending`) when its base product
 *    is active and unchanged, its design shows something and its files are
 *    complete. The price is computed again from the base price now. At most
 *    one version per product waits (`pending_key`). The administrator gets
 *    "new product" or "change" (when a version is in the shop) and the
 *    creator a receipt, after the commit.
 * 3. withdraw(): the waiting version is withdrawn and continued as a new
 *    draft with the same content and files.
 */
class DesignerSubmissions {

	/**
	 * Constructor.
	 *
	 * @param DesignerProductRepository $products    Designer products.
	 * @param BaseProductRepository     $bases       Base products.
	 * @param DesignerFiles             $files       Files.
	 * @param AuditTrail                $audit       Audit trail.
	 * @param Transaction               $transaction Transaction.
	 * @param int                       $decimals    Store price decimals.
	 */
	public function __construct(
		private DesignerProductRepository $products,
		private BaseProductRepository $bases,
		private DesignerFiles $files,
		private AuditTrail $audit,
		private Transaction $transaction,
		private int $decimals
	) {
	}

	/**
	 * Stores one file of the draft's submission.
	 *
	 * @param Creator              $creator The creator.
	 * @param int                  $product Designer product.
	 * @param string               $slot    `print:<side>` or `mockup:<side>:<colour>`.
	 * @param array<string, mixed> $upload  The upload (`$_FILES` entry).
	 * @throws DesignerException When the product, slot or file is refused.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function attach( Creator $creator, int $product, string $slot, array $upload ): void {
		$row   = $this->products->find( $creator->id, $product ) ?? self::refuse( DesignerException::DESIGN_UNKNOWN );
		$parts = SubmissionFiles::slot( $slot ) ?? self::refuse( DesignerException::SUBMISSION_FILE );
		$draft = $this->products->draft( $product ) ?? self::refuse( DesignerException::DESIGN_NOT_DRAFT );
		$base  = $this->current_base( (int) $row['base_id'], $draft );
		$view  = $this->used_view( $base, $draft, $parts );
		$size  = SubmissionFiles::PRINT === $parts[0] ? $view->print_pixels() : array( $view->width, $view->height );
		$file  = $this->files->store( $upload, SubmissionFiles::PRINT === $parts[0] );
		$stamp = SubmissionFiles::stamp( $draft );

		if ( $size[0] !== $file['width'] || $size[1] !== $file['height'] ) {
			$this->files->delete( $file['file'] );
			self::refuse( DesignerException::SUBMISSION_FILE );
		}

		$kept = false;

		try {
			$replaced = $this->transaction->run(
				function () use ( $product, $parts, $file, $stamp ): array {
					$locked = $this->products->draft( $product, true );

					if ( null === $locked || SubmissionFiles::stamp( $locked ) !== $stamp ) {
						throw new DesignerException( DesignerException::DESIGN_STALE );
					}

					$files    = SubmissionFiles::decode( is_string( $locked['files'] ) ? $locked['files'] : null );
					$replaced = array();

					if ( $files['stamp'] !== $stamp ) {
						$replaced = SubmissionFiles::names( is_string( $locked['files'] ) ? $locked['files'] : null );
						$files    = array(
							'stamp'   => $stamp,
							'print'   => array(),
							'mockups' => array(),
						);
					}

					if ( SubmissionFiles::PRINT === $parts[0] ) {
						$replaced[]                  = $files['print'][ $parts[1] ]['file'] ?? null;
						$files['print'][ $parts[1] ] = $file;
					} else {
						$replaced[] = $files['mockups'][ $parts[1] ][ (string) $parts[2] ]['file'] ?? null;
						$files['mockups'][ $parts[1] ][ (string) $parts[2] ] = $file;
					}

					$this->products->set_files( (int) $locked['id'], self::json( $files ) );

					return $replaced;
				}
			);
			$kept     = true;
		} finally {
			// A refused or failed attach leaves no file behind.
			if ( ! $kept ) {
				$this->files->delete( $file['file'] );
			}
		}

		foreach ( array_filter( $replaced, 'is_string' ) as $name ) {
			$this->files->delete( $name );
		}
	}

	/**
	 * Submits the draft for approval.
	 *
	 * @param Creator $creator The creator.
	 * @param int     $product Designer product.
	 * @throws DesignerException When it cannot be submitted.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function submit( Creator $creator, int $product ): void {
		$row = $this->products->find( $creator->id, $product ) ?? self::refuse( DesignerException::DESIGN_UNKNOWN );

		$submitted = $this->transaction->run(
			function () use ( $creator, $product, $row ): array {
				$draft = $this->products->draft( $product, true ) ?? self::refuse( DesignerException::DESIGN_NOT_DRAFT );

				if ( null !== $this->products->pending( $product, true ) ) {
					self::refuse( DesignerException::SUBMISSION_PENDING );
				}

				$base   = $this->current_base( (int) $row['base_id'], $draft );
				$design = json_decode( (string) $draft['design'], true );

				if ( array() === SubmissionFiles::used_sides( is_array( $design ) ? $design : array() ) ) {
					self::refuse( DesignerException::DESIGN_EMPTY );
				}

				if ( ! SubmissionFiles::complete( $draft ) ) {
					self::refuse( DesignerException::SUBMISSION_FILES );
				}

				$price = $this->price( $base, $draft );
				$this->products->submit( (int) $draft['id'], $product, $price );
				$this->audit->record(
					AuditAction::DesignerVersionSubmitted,
					$creator->id,
					array(
						'design_product_id' => $product,
						'version_id'        => (int) $draft['id'],
						'number'            => (int) $draft['number'],
						'price'             => $price,
					)
				);

				return array(
					'version' => (int) $draft['id'],
					'title'   => (string) $draft['title'],
					'number'  => (int) $draft['number'],
					'price'   => $price,
				);
			}
		);

		$data  = array(
			'title'    => $submitted['title'],
			'number'   => (string) $submitted['number'],
			'price'    => $submitted['price'],
			'currency' => $this->currency( (int) $row['base_id'] ),
		);
		$dedup = 'designer_version:' . $submitted['version'];

		do_action( 'merchboard_notify', null === $row['live_version_id'] ? DesignerNotifications::SUBMITTED : DesignerNotifications::CHANGE_SUBMITTED, $creator->id, $data, $dedup );
		do_action( 'merchboard_notify', DesignerNotifications::RECEIPT, $creator->id, $data, $dedup );
	}

	/**
	 * Withdraws the waiting version; a new draft continues it.
	 *
	 * @param Creator $creator The creator.
	 * @param int     $product Designer product.
	 * @throws DesignerException When nothing waits.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function withdraw( Creator $creator, int $product ): void {
		if ( null === $this->products->find( $creator->id, $product ) ) {
			self::refuse( DesignerException::DESIGN_UNKNOWN );
		}

		$this->transaction->run(
			function () use ( $creator, $product ): void {
				$pending = $this->products->pending( $product, true ) ?? self::refuse( DesignerException::NOT_PENDING );

				$this->products->withdraw( $pending );
				$this->audit->record(
					AuditAction::DesignerVersionWithdrawn,
					$creator->id,
					array(
						'design_product_id' => $product,
						'version_id'        => (int) $pending['id'],
						'number'            => (int) $pending['number'],
					)
				);
			}
		);
	}

	/**
	 * The draft's base product: active and in the version the draft was made on.
	 *
	 * @param int                  $base_id Base product.
	 * @param array<string, mixed> $draft   Draft version.
	 * @throws DesignerException When it is gone, inactive or changed since the draft was saved.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function current_base( int $base_id, array $draft ): BaseProduct {
		$base = $this->bases->find( $base_id );

		if ( null === $base || BaseProduct::ACTIVE !== $base->status || $base->version !== (int) $draft['base_version'] ) {
			self::refuse( DesignerException::DESIGN_STALE );
		}

		return $base;
	}

	/**
	 * The side of a slot: a used side of the draft (and a chosen colour).
	 *
	 * @param BaseProduct                                 $base  Base product.
	 * @param array<string, mixed>                        $draft Draft version.
	 * @param array{0: string, 1: string, 2: string|null} $parts Slot.
	 * @throws DesignerException When the slot is not part of the submission.
	 */
	private function used_view( BaseProduct $base, array $draft, array $parts ): BaseView {
		$design = json_decode( (string) $draft['design'], true );
		$colors = json_decode( (string) $draft['colors'], true );

		if ( ! in_array( $parts[1], SubmissionFiles::used_sides( is_array( $design ) ? $design : array() ), true )
			|| ( null !== $parts[2] && ! in_array( $parts[2], is_array( $colors ) ? $colors : array(), true ) ) ) {
			self::refuse( DesignerException::SUBMISSION_FILE );
		}

		foreach ( $base->views as $view ) {
			if ( $view->key === $parts[1] ) {
				return $view;
			}
		}

		self::refuse( DesignerException::SUBMISSION_FILE );
	}

	/**
	 * The customer's price of a draft from the base price now.
	 *
	 * @param BaseProduct          $base  Base product.
	 * @param array<string, mixed> $draft Draft version.
	 * @throws DesignerException When it cannot be priced.
	 */
	private function price( BaseProduct $base, array $draft ): string {
		try {
			$price = DesignerPrices::compute( 1, SalesAmount::format( $base->base_units ), (string) $draft['profit_kind'], SalesAmount::format( SalesAmount::parse( (string) $draft['profit_value'] ) ), $base->currency, $this->decimals );
		} catch ( InvalidArgumentException | OverflowException ) {
			self::refuse( DesignerException::DESIGN_PROFIT );
		}

		return SalesAmount::format( $price->price_units() );
	}

	/**
	 * The currency of a base product ('' when it is gone).
	 *
	 * @param int $base_id Base product.
	 */
	private function currency( int $base_id ): string {
		try {
			return $this->bases->find( $base_id )->currency ?? '';
		} catch ( DatabaseException ) {
			return '';
		}
	}

	/**
	 * Files as stored JSON.
	 *
	 * @param array<string, mixed> $files Files.
	 * @throws DatabaseException When they cannot be encoded.
	 */
	private static function json( array $files ): string {
		$json = wp_json_encode( $files );

		if ( false === $json ) {
			throw new DatabaseException( 'Could not encode the files of the design.' );
		}

		return $json;
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
