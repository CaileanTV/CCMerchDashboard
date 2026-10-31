<?php
/**
 * What a creator decides about a designer product and the shop.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\ImpersonationSession;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\Shop\DesignerProductGuard;
use MerchBoard\Modules\ProductDesigner\Shop\WooCommerceDesignerWriter;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's own decisions (the owner's choices, 2026-10-31):
 *
 * - **Discard a change:** the draft of a product in the shop goes; the shop
 *   keeps the live version.
 * - **Take out of or put back in the shop:** the live version is approved
 *   already, so it needs no new approval. A retired product stays out.
 * - **Delete:** the product leaves the shop and the creator's lists for their
 *   deleted designs, where they can see it again. It is never trashed in
 *   WooCommerce, whose trash empties itself.
 * - **Delete for good:** a design that never reached the shop is gone
 *   completely. One that did leaves the creator, but its row, versions and
 *   files stay: orders point at them, and a sale that is not settled yet
 *   needs its creator.
 *
 * An administrator viewing as a creator cannot do these.
 */
class DesignerShop {

	/**
	 * Constructor.
	 *
	 * @param DesignerProductRepository $products    Designer products.
	 * @param DesignerDrafts            $drafts      Drafts.
	 * @param DesignerProductGuard      $guard       The legacy guard.
	 * @param WooCommerceDesignerWriter $writer      The shop.
	 * @param AuditTrail                $audit       Audit trail.
	 * @param Transaction               $transaction Transaction.
	 * @param DesignerFiles             $files       Files.
	 * @param BaseProductRepository     $bases       Base products.
	 */
	public function __construct(
		private DesignerProductRepository $products,
		private DesignerDrafts $drafts,
		private DesignerProductGuard $guard,
		private WooCommerceDesignerWriter $writer,
		private AuditTrail $audit,
		private Transaction $transaction,
		private DesignerFiles $files,
		private BaseProductRepository $bases
	) {
	}

	/**
	 * Discards the change of a product in the shop.
	 *
	 * @param Creator $creator The creator.
	 * @param int     $product Designer product.
	 * @throws DesignerException When it is unknown, or there is no change to discard.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function discard( Creator $creator, int $product ): void {
		$this->row( $creator, $product );

		$files = $this->transaction->run(
			function () use ( $creator, $product ): array {
				$this->products->live( $product ) ?? self::refuse( DesignerException::NOT_LIVE );

				$names = $this->drop_draft( $product );

				$this->audit->record( AuditAction::DesignerChangeDiscarded, $creator->id, array( 'design_product_id' => $product ) );

				return $names;
			}
		);

		$this->delete_files( $files );
	}

	/**
	 * Takes a product in the shop out of it, or puts it back.
	 *
	 * @param Creator $creator   The creator.
	 * @param int     $product   Designer product.
	 * @param bool    $published Whether it is to be in the shop.
	 * @throws DesignerException When it is unknown or not live.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set_published( Creator $creator, int $product, bool $published ): void {
		$row = $this->row( $creator, $product );

		if ( DesignerProductRepository::LIVE !== $row['status'] ) {
			self::refuse( DesignerException::NOT_LIVE );
		}

		$proof = $this->guard->for_row( $product ) ?? self::refuse( DesignerException::NOT_LIVE );

		if ( $published ) {
			$this->writer->publish( $proof );
		} else {
			$this->writer->unpublish( $proof );
		}

		$this->audit->record( $published ? AuditAction::DesignerProductPublished : AuditAction::DesignerProductHidden, $creator->id, array( 'design_product_id' => $product ) );
	}

	/**
	 * Moves a designer product to the creator's deleted designs.
	 *
	 * @param Creator $creator The creator.
	 * @param int     $product Designer product.
	 * @throws DesignerException When it is unknown, or on its way to the shop.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function remove( Creator $creator, int $product ): void {
		$row = $this->row( $creator, $product );

		if ( $this->products->applying_version( $product ) ) {
			self::refuse( DesignerException::DESIGN_APPLYING );
		}

		// Out of the shop first: if the rest fails, the creator still sees the product and can try again.
		$proof = null === $row['wc_product_id'] ? null : $this->guard->for_row( $product );

		if ( null !== $proof ) {
			$this->writer->unpublish( $proof );
		}

		$files = $this->transaction->run(
			function () use ( $creator, $product ): array {
				$this->products->lock_versions( $product );

				if ( $this->products->applying_version( $product ) ) {
					self::refuse( DesignerException::DESIGN_APPLYING );
				}

				$this->withdraw_pending( $product );

				// A change is dropped; the design of a product that was never in the shop stays to be seen.
				$live  = $this->products->live( $product );
				$names = null === $live || null === $this->products->draft( $product, true ) ? array() : $this->drop_draft( $product );

				$this->products->mark_removed( $product, DesignerProductRepository::DELETED );
				$this->audit->record( AuditAction::DesignerProductRemoved, $creator->id, array( 'design_product_id' => $product ) );

				return $names;
			}
		);

		$this->delete_files( $files );
	}

	/**
	 * Deletes a design of the creator's deleted designs for good. One that
	 * never reached the shop is gone with its files. One that did leaves the
	 * creator, but its versions and files stay: orders point at them.
	 *
	 * @param Creator $creator The creator.
	 * @param int     $product Designer product.
	 * @throws DesignerException When it is not among the deleted designs.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function purge( Creator $creator, int $product ): void {
		if ( null !== ImpersonationSession::creator_id() ) {
			self::refuse( DesignerException::NOT_AUTHORIZED );
		}

		$row = $this->products->product( $product );

		if ( null === $row || $creator->id !== (int) $row['creator_id'] || DesignerProductRepository::DELETED !== $row['status'] ) {
			self::refuse( DesignerException::DESIGN_UNKNOWN );
		}

		if ( null === $row['wc_product_id'] ) {
			$this->drafts->delete( $creator, $product );

			return;
		}

		$this->transaction->run(
			function () use ( $creator, $product ): void {
				$this->products->mark_removed( $product, DesignerProductRepository::PURGED );
				$this->audit->record( AuditAction::DesignerProductPurged, $creator->id, array( 'design_product_id' => $product ) );
			}
		);
	}

	/**
	 * Carries a creator's new name to their products, in the shop too.
	 *
	 * @param int    $creator_id The creator.
	 * @param string $before        Their old name.
	 * @param string $after        Their new name.
	 * @throws DesignerException When a product cannot be written.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function creator_renamed( int $creator_id, string $before, string $after ): void {
		foreach ( $this->products->for_creator( $creator_id ) as $row ) {
			$id   = (int) $row['id'];
			$base = $this->bases->find( (int) $row['base_id'] );

			if ( null === $base ) {
				continue;
			}

			$this->products->rename( $id, DesignerNames::prefix( $before, $base->name ), DesignerNames::prefix( $after, $base->name ) );

			$live  = DesignerProductRepository::LIVE === $row['status'] ? $this->products->live( $id ) : null;
			$proof = null === $live ? null : $this->guard->for_row( $id );

			if ( null !== $proof ) {
				$this->writer->set_name( $proof, (string) ( $live['title'] ?? '' ) );
			}
		}
	}

	/**
	 * The creator's product. Viewing as a creator cannot decide for them.
	 *
	 * @param Creator $creator The creator.
	 * @param int     $product Designer product.
	 * @return array<string, mixed>
	 * @throws DesignerException When it is unknown, or an administrator views as the creator.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function row( Creator $creator, int $product ): array {
		if ( null !== ImpersonationSession::creator_id() ) {
			self::refuse( DesignerException::NOT_AUTHORIZED );
		}

		return $this->products->find( $creator->id, $product ) ?? self::refuse( DesignerException::DESIGN_UNKNOWN );
	}

	/**
	 * Withdraws a version waiting for approval, if any.
	 *
	 * @param int $product Designer product.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function withdraw_pending( int $product ): void {
		$pending = $this->products->pending( $product );

		if ( null !== $pending ) {
			$this->products->withdraw( $pending );
		}
	}

	/**
	 * Deletes the draft version; inside a transaction.
	 *
	 * @param int $product Designer product.
	 * @return list<string> The draft's files no other version uses.
	 * @throws DesignerException When there is no draft.
	 * @throws DatabaseException When it cannot be deleted.
	 */
	private function drop_draft( int $product ): array {
		$draft = $this->products->draft( $product, true ) ?? self::refuse( DesignerException::DESIGN_NOT_DRAFT );
		$names = SubmissionFiles::names( is_string( $draft['files'] ?? null ) ? $draft['files'] : null );

		$this->products->delete_draft_version( (int) $draft['id'] );

		return array_values( array_diff( $names, $this->products->files_of( $product ) ) );
	}

	/**
	 * Deletes files, once the rows are gone.
	 *
	 * @param array<int, string> $names File names.
	 */
	private function delete_files( array $names ): void {
		foreach ( $names as $name ) {
			$this->files->delete( $name );
		}
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
