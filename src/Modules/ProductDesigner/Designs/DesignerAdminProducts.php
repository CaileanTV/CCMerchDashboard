<?php
/**
 * A creator's designer products as the administrator sees and corrects them.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerProductGuard;
use MerchBoard\Modules\ProductDesigner\Shop\WooCommerceDesignerWriter;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-124 (the owner's urgent wish 2026-11-27): on the creator's edit page
 * an administrator with `merchboard_manage_designer` sees the creator's
 * designer products and
 * - renames one: only the creator's addition after "Creator Base product - "
 *   (ADR-102; another product's name only when confirmed), in its draft,
 *   waiting and approved versions and, for a
 *   product in the shop, in the shop at once (the administrator is the one
 *   who approves). Under the product's lock, as the apply, the repricing and
 *   the creator's image choice; the shop first, so a failure there leaves
 *   the versions as they were;
 * - deletes a draft that never reached the shop, for good with its files
 *   (DesignerDrafts::delete()), or discards the change (draft) of a product
 *   in the shop, which keeps its live version.
 * A waiting design is decided in the approval queue, not here. Every change
 * is audited with the administrator as the actor; nothing is sent to the
 * creator.
 */
class DesignerAdminProducts {

	/**
	 * The longest name (DesignerDrafts::save()).
	 */
	private const MAX_TITLE = 120;

	/**
	 * Constructor.
	 *
	 * @param DesignerProductRepository $products    Designer products.
	 * @param BaseProductRepository     $bases       Base products.
	 * @param DesignerDrafts            $drafts      Drafts (deleting one for good).
	 * @param DesignerProductGuard      $guard       The legacy guard.
	 * @param WooCommerceDesignerWriter $writer      The only writer of designer products.
	 * @param AuditTrail                $audit       Audit trail.
	 * @param Transaction               $transaction Transaction.
	 * @param DesignerFiles             $files       Files (a discarded draft's submission files).
	 */
	public function __construct(
		private DesignerProductRepository $products,
		private BaseProductRepository $bases,
		private DesignerDrafts $drafts,
		private DesignerProductGuard $guard,
		private WooCommerceDesignerWriter $writer,
		private AuditTrail $audit,
		private Transaction $transaction,
		private DesignerFiles $files
	) {
	}

	/**
	 * The creator's designer products, newest first, without the deleted ones.
	 *
	 * @param Creator $creator The creator.
	 * @return list<array{id: int, title: string, prefix: string, postfix: string, base: string, state: string, wc_product_id: int|null, deletable: bool, discardable: bool}>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function for_creator( Creator $creator ): array {
		$bases = array();
		$list  = array();

		foreach ( $this->products->for_creator( $creator->id ) as $row ) {
			$base_id = (int) $row['base_id'];

			if ( ! array_key_exists( $base_id, $bases ) ) {
				$bases[ $base_id ] = $this->bases->find( $base_id );
			}

			$base    = $bases[ $base_id ];
			$name    = $base instanceof BaseProduct ? $base->name : '';
			$prefix  = DesignerNames::prefix( $creator->display_name, $name );
			$title   = (string) $row['title'];
			$status  = (string) $row['status'];
			$version = (string) $row['version_status'];
			$shop    = null === $row['wc_product_id'] ? null : (int) $row['wc_product_id'];

			$list[] = array(
				'id'            => (int) $row['id'],
				'title'         => $title,
				'prefix'        => $prefix,
				'postfix'       => DesignerNames::postfix( $title, $prefix, $name ),
				'base'          => $name,
				'state'         => self::state( $status, $version ),
				'wc_product_id' => $shop,
				'deletable'     => null === $shop && DesignerProductRepository::DRAFT === $version,
				'discardable'   => null !== $shop && DesignerProductRepository::DRAFT === $version,
			);
		}

		return $list;
	}

	/**
	 * Gives a product a new addition after the fixed part of its name.
	 *
	 * @param Creator $creator  The creator.
	 * @param int     $product  Designer product.
	 * @param string  $addition The new addition ('' for none).
	 * @param bool    $same     Whether a name another product has is accepted (confirmed).
	 * @return bool Whether a name changed.
	 * @throws DesignerException When it is unknown, busy, has no current version, or the name is too long or taken.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function rename( Creator $creator, int $product, string $addition, bool $same = false ): bool {
		$row   = $this->products->find( $creator->id, $product ) ?? self::refuse( DesignerException::DESIGN_UNKNOWN );
		$base  = $this->bases->find( (int) $row['base_id'] ) ?? self::refuse( DesignerException::BASE_UNKNOWN );
		$title = DesignerNames::full( DesignerNames::prefix( $creator->display_name, $base->name ), sanitize_text_field( $addition ) );

		if ( mb_strlen( $title ) > self::MAX_TITLE ) {
			self::refuse( DesignerException::DESIGN_TITLE );
		}

		// The apply, the repricing and the retirement write the same product.
		if ( ! $this->products->lock_product( $product ) ) {
			self::refuse( DesignerException::DESIGN_BUSY );
		}

		try {
			$versions = $this->products->named_versions( $product );

			if ( array() === $versions ) {
				self::refuse( DesignerException::DESIGN_NOT_DRAFT );
			}

			$renames = array_values( array_filter( $versions, static fn( array $version ): bool => $version['title'] !== $title ) );

			if ( array() === $renames ) {
				return false;
			}

			if ( ! $same && $this->products->name_taken( $title, $product ) ) {
				self::refuse( DesignerException::DESIGN_NAME_TAKEN );
			}

			// The shop first: if it fails, the versions keep their names. Read again under the lock: a first apply may just have finished.
			$row = $this->products->find( $creator->id, $product ) ?? self::refuse( DesignerException::DESIGN_UNKNOWN );

			if ( DesignerProductRepository::LIVE === $row['status'] && null !== $this->products->live( $product ) ) {
				$proof = $this->guard->for_row( $product ) ?? self::refuse( DesignerException::NOT_LIVE );

				$this->writer->set_name( $proof, $title );
			}

			$this->transaction->run(
				function () use ( $creator, $product, $renames, $title ): void {
					foreach ( $renames as $version ) {
						$this->products->retitle( $version['id'], $version['title'], $title );
					}

					$this->audit->record(
						AuditAction::DesignerProductRenamed,
						$creator->id,
						array(
							'design_product_id' => $product,
							'title'             => $title,
						)
					);
				}
			);

			return true;
		} finally {
			$this->products->unlock_product( $product );
		}
	}

	/**
	 * Deletes a draft that never reached the shop for good, or discards the
	 * change (draft) of a product in the shop.
	 *
	 * @param Creator $creator The creator.
	 * @param int     $product Designer product.
	 * @return string `deleted` or `discarded`.
	 * @throws DesignerException When it is unknown, or has no draft to delete.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function delete_draft( Creator $creator, int $product ): string {
		$row = $this->products->find( $creator->id, $product ) ?? self::refuse( DesignerException::DESIGN_UNKNOWN );

		if ( null === $row['wc_product_id'] ) {
			$this->drafts->delete( $creator, $product, AuditAction::DesignerDraftDeletedByAdmin );

			return 'deleted';
		}

		$names = $this->transaction->run(
			function () use ( $creator, $product ): array {
				$this->products->live( $product ) ?? self::refuse( DesignerException::NOT_LIVE );

				$draft = $this->products->draft( $product, true ) ?? self::refuse( DesignerException::DESIGN_NOT_DRAFT );
				$names = SubmissionFiles::names( is_string( $draft['files'] ?? null ) ? $draft['files'] : null );

				$this->products->delete_draft_version( (int) $draft['id'] );
				$this->audit->record( AuditAction::DesignerChangeDiscardedByAdmin, $creator->id, array( 'design_product_id' => $product ) );

				// Only the files no other version uses.
				return array_values( array_diff( $names, $this->products->files_of( $product ) ) );
			}
		);

		foreach ( $names as $name ) {
			$this->files->delete( $name );
		}

		return 'discarded';
	}

	/**
	 * Where a product is, from its status and its newest version's.
	 *
	 * @param string $status  The product's status.
	 * @param string $version The newest version's status.
	 * @return string `in_shop`, `in_shop_change`, `in_shop_waiting`, `waiting`, `approved`, `retired` or `draft`.
	 */
	private static function state( string $status, string $version ): string {
		if ( DesignerProductRepository::RETIRED === $status ) {
			return 'retired';
		}

		if ( DesignerProductRepository::LIVE === $status ) {
			return match ( $version ) {
				DesignerProductRepository::DRAFT   => 'in_shop_change',
				DesignerProductRepository::PENDING => 'in_shop_waiting',
				default                            => 'in_shop',
			};
		}

		return match ( $version ) {
			DesignerProductRepository::PENDING  => 'waiting',
			DesignerProductRepository::APPROVED => 'approved',
			default                             => 'draft',
		};
	}

	/**
	 * Refuses a request.
	 *
	 * @param string $reason Reason.
	 * @throws DesignerException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new DesignerException( $reason );
	}
}
