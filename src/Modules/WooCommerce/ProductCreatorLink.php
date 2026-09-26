<?php
/**
 * The authoritative product → creator link.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * The only write path for `_merchboard_creator_id`, the product ownership
 * relation (ADR-030, ADR-031, ADR-032). Both supported callers, the product
 * links on the creator's edit page in Merch Dashboard (wp-admin) and the
 * one-time backfill, go through this service. The WooCommerce product editor
 * is not a caller.
 *
 * - The link lives on the parent product only. Variations are rejected;
 *   they belong to their parent's creator.
 * - Authorization: `edit_product` for the concrete parent product, checked
 *   here independent of the caller. Both callers additionally require
 *   `merchboard_manage_creators`, so that capability alone never suffices.
 * - A new link needs an existing, active creator. Removing a link does not.
 * - An existing link is kept unless the caller explicitly asks to replace it;
 *   the backfill never does.
 * - Consistency: the audit record is written first and the product meta
 *   second, in one database transaction. On failure the audit record is
 *   always rolled back: the plugin's tables are required to be transactional
 *   (ADR-024). The product meta lives in WordPress' `wp_postmeta`, which the
 *   plugin does not check; it is only rolled back with the transaction when
 *   that table is transactional too (InnoDB, the normal WordPress/MariaDB
 *   setup this project assumes). On a non-transactional `wp_postmeta`, a
 *   failure after the meta write can therefore leave the meta changed while
 *   the audit record is rolled back. The product's cached data is dropped on
 *   every failure.
 * - Generic writes of the link meta are denied by ProtectedProductMeta; this
 *   service writes only inside an explicit ProtectedMetaWrite for the
 *   product, after the checks above.
 * - The category mapping (`product_category_id`) is never read here: it is
 *   input for the backfill, not proof of ownership.
 */
final class ProductCreatorLink {

	/**
	 * Product meta key of the link (parent products only).
	 */
	public const META_KEY = '_merchboard_creator_id';

	/**
	 * Product meta key of the link's origin (ADR-033). Protected like
	 * META_KEY; written from a later step on.
	 */
	public const SOURCE_META_KEY = '_merchboard_creator_link_source';

	/**
	 * Post statuses of products that cannot be linked.
	 */
	private const UNSUPPORTED_STATUSES = array( 'trash', 'auto-draft' );

	/**
	 * Constructor.
	 *
	 * @param ProductStore         $products    Product storage.
	 * @param CreatorRepository    $creators    Creator storage.
	 * @param AuditTrail           $audit       Audit trail.
	 * @param Transaction          $transaction Database transaction.
	 * @param ProtectedProductMeta $guard       Guard of the protected product meta.
	 */
	public function __construct(
		private ProductStore $products,
		private CreatorRepository $creators,
		private AuditTrail $audit,
		private Transaction $transaction,
		private ProtectedProductMeta $guard
	) {
	}

	/**
	 * Whether a meta key is the link key. Case-insensitive and trimmed, like
	 * MySQL compares meta keys, so no spelling can pass as another key.
	 *
	 * @param string $key Meta key.
	 */
	public static function is_link_key( string $key ): bool {
		return self::META_KEY === strtolower( trim( $key ) );
	}

	/**
	 * The creator a product belongs to: its own link for a parent product,
	 * the parent's link for a variation. Null when there is no valid link.
	 * Read-only; no authorization.
	 *
	 * @param int $product_id Product or variation id.
	 */
	public function creator_id( int $product_id ): ?int {
		$product = $this->products->find( $product_id );

		if ( null !== $product && $product->is_variation ) {
			$product = $product->parent_id > 0 ? $this->products->find( $product->parent_id ) : null;
		}

		return null === $product || $product->is_variation ? null : $product->creator_id();
	}

	/**
	 * Links a parent product to an active creator.
	 *
	 * A product that already holds a valid link to exactly this creator is
	 * left unchanged, even when the creator has become inactive since, so an
	 * unchanged request never fails. Otherwise the creator must be active, and
	 * without $replace_existing a product that already holds a link (to
	 * another creator, or an invalid one) is rejected; with it, the old link
	 * is replaced.
	 *
	 * @param int               $product_id       Parent product id.
	 * @param int               $creator_id       Creator id.
	 * @param ProductLinkSource $source           Write path.
	 * @param bool              $replace_existing Replace an existing link.
	 * @return list<AuditAction> The changes made (empty when nothing changed).
	 * @throws ProductCreatorLinkException On authorization or validation failure.
	 * @throws DatabaseException When the change cannot be stored. The audit record is rolled back; the product meta only when `wp_postmeta` is transactional.
	 */
	public function link( int $product_id, int $creator_id, ProductLinkSource $source, bool $replace_existing = false ): array {
		$product  = $this->linkable_product( $product_id );
		$previous = $product->creator_id();

		if ( null !== $previous && $previous === $creator_id ) {
			return array();
		}

		$creator = $this->active_creator( $creator_id );
		$this->assert_replaceable( $product, $replace_existing );

		return $this->write(
			$product,
			$creator->id,
			function () use ( $product, $previous, $creator, $source ): array {
				$changes = array();

				if ( $product->has_link() ) {
					$changes[] = $this->record_unlinked( $product, $previous, $source );
				}

				$context = array(
					'product_id'          => $product->id,
					'previous_creator_id' => $previous,
					'source'              => $source->value,
				);

				if ( $product->has_invalid_link() ) {
					$context['previous_link'] = 'invalid';
				}

				$this->audit->record( AuditAction::ProductCreatorLinked, $creator->id, $context );
				$changes[] = AuditAction::ProductCreatorLinked;

				return $changes;
			}
		);
	}

	/**
	 * Removes a parent product's link, including invalid entries. Removing a
	 * link that does not exist changes nothing.
	 *
	 * @param int               $product_id Parent product id.
	 * @param ProductLinkSource $source     Write path.
	 * @return list<AuditAction> The changes made (empty when nothing changed).
	 * @throws ProductCreatorLinkException On authorization or validation failure.
	 * @throws DatabaseException When the change cannot be stored. The audit record is rolled back; the product meta only when `wp_postmeta` is transactional.
	 */
	public function unlink( int $product_id, ProductLinkSource $source ): array {
		$product = $this->linkable_product( $product_id );

		if ( ! $product->has_link() ) {
			return array();
		}

		$previous = $product->creator_id();

		return $this->write(
			$product,
			null,
			fn(): array => array( $this->record_unlinked( $product, $previous, $source ) )
		);
	}

	/**
	 * Loads a product that may carry a link and authorizes the current user
	 * for it.
	 *
	 * @param int $product_id Product id.
	 * @throws ProductCreatorLinkException When the product is unknown, not editable, a variation or not supported.
	 */
	private function linkable_product( int $product_id ): ProductRecord {
		$product = $product_id > 0 ? $this->products->find( $product_id ) : null;

		if ( null === $product ) {
			throw new ProductCreatorLinkException( ProductCreatorLinkException::PRODUCT_NOT_FOUND );
		}

		if ( ! current_user_can( Capabilities::EDIT_PRODUCT, $product->id ) ) {
			throw new ProductCreatorLinkException( ProductCreatorLinkException::NOT_AUTHORIZED );
		}

		if ( $product->is_variation ) {
			throw new ProductCreatorLinkException( ProductCreatorLinkException::VARIATION );
		}

		if ( in_array( $product->status, self::UNSUPPORTED_STATUSES, true ) ) {
			throw new ProductCreatorLinkException( ProductCreatorLinkException::PRODUCT_NOT_SUPPORTED );
		}

		return $product;
	}

	/**
	 * Loads the creator a product may be linked to.
	 *
	 * @param int $creator_id Creator id.
	 * @throws ProductCreatorLinkException When the creator is unknown or inactive.
	 */
	private function active_creator( int $creator_id ): Creator {
		$creator = $creator_id > 0 ? $this->creators->find( $creator_id ) : null;

		if ( null === $creator ) {
			throw new ProductCreatorLinkException( ProductCreatorLinkException::CREATOR_NOT_FOUND );
		}

		if ( CreatorStatus::Active !== $creator->status ) {
			throw new ProductCreatorLinkException( ProductCreatorLinkException::CREATOR_INACTIVE );
		}

		return $creator;
	}

	/**
	 * An existing link (valid or not) is only replaced on explicit request.
	 *
	 * @param ProductRecord $product          Product.
	 * @param bool          $replace_existing Whether replacing was requested.
	 * @throws ProductCreatorLinkException When the product is already linked.
	 */
	private function assert_replaceable( ProductRecord $product, bool $replace_existing ): void {
		if ( $product->has_link() && ! $replace_existing ) {
			throw new ProductCreatorLinkException( ProductCreatorLinkException::ALREADY_LINKED );
		}
	}

	/**
	 * Runs the audit work and then the product write in one transaction.
	 * On any failure the product's cached data is dropped, so a rolled-back
	 * write can never be served from a cache.
	 *
	 * @param ProductRecord                 $product    Product.
	 * @param int|null                      $creator_id Creator to store, or null to remove.
	 * @param callable(): list<AuditAction> $audit      Writes the audit records.
	 * @return list<AuditAction>
	 * @throws Throwable Whatever the audit or product write throws (after rollback), e.g. a DatabaseException.
	 */
	private function write( ProductRecord $product, ?int $creator_id, callable $audit ): array {
		try {
			return $this->transaction->run(
				function () use ( $product, $creator_id, $audit ): array {
					$changes = $audit();

					// Everything above authorized this product; the guard lets
					// exactly this write through (ProtectedProductMeta).
					$this->guard->run(
						ProtectedMetaWrite::for_manual_link( $product->id ),
						fn() => $this->products->write_creator( $product->id, $creator_id )
					);

					return $changes;
				}
			);
		} catch ( Throwable $e ) {
			$this->products->forget( $product->id );
			throw $e;
		}
	}

	/**
	 * Records the removal of a product's link.
	 *
	 * @param ProductRecord     $product  Product.
	 * @param int|null          $previous Previously linked creator, or null when the link was invalid.
	 * @param ProductLinkSource $source   Write path.
	 */
	private function record_unlinked( ProductRecord $product, ?int $previous, ProductLinkSource $source ): AuditAction {
		$context = array(
			'product_id' => $product->id,
			'source'     => $source->value,
		);

		if ( null === $previous ) {
			$context['previous_link'] = 'invalid';
		}

		$this->audit->record( AuditAction::ProductCreatorUnlinked, $previous, $context );

		return AuditAction::ProductCreatorUnlinked;
	}
}
