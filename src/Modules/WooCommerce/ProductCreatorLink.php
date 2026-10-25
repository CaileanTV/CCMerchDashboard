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
use MerchBoard\Core\Security\ProtectedMetaWrite;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use InvalidArgumentException;
use MerchBoard\Modules\Creators\CreatorStatus;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * The only write path for `_merchboard_creator_id`, the product ownership
 * relation, and its origin `_merchboard_creator_link_source` (ADR-030,
 * ADR-032, ADR-033). Two callers: the manual product links on the creator's
 * edit page in Merch Dashboard (link()/unlink()/keep_as_manual(), origin
 * `admin`) and the system category reconciliation (apply_category_link(),
 * origin `category`).
 * The WooCommerce product editor is not a caller.
 *
 * - The link lives on the parent product only. Variations are rejected;
 *   they belong to their parent's creator.
 * - Manual authorization: `edit_product` for the concrete parent product,
 *   checked here independent of the caller, which additionally requires
 *   `merchboard_manage_creators`, so that capability alone never suffices.
 *   The category path is system work without a current user (ADR-033).
 * - A new link needs an existing, active creator. Removing a link does not.
 * - An existing link is kept unless the manual caller explicitly asks to
 *   replace it. The category path never touches a manual link, and the
 *   manual unlink() never removes a category-managed one (ADR-033 point 5).
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
 * - The category mapping is never read here: the reconciler decides and
 *   calls apply_category_link(); a mapping is not proof of ownership.
 */
final class ProductCreatorLink {

	/**
	 * Product meta key of the link (parent products only). Owned by core
	 * (ProtectedProductMeta, ADR-035).
	 */
	public const META_KEY = ProtectedProductMeta::LINK_KEY;

	/**
	 * Product meta key of the link's origin (ADR-033). Owned by core and
	 * protected like META_KEY; written together with it.
	 */
	public const SOURCE_META_KEY = ProtectedProductMeta::SOURCE_KEY;

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
	 * Whether a meta key is the link origin key (trimmed, case-insensitive).
	 *
	 * @param string $key Meta key.
	 */
	public static function is_source_key( string $key ): bool {
		return self::SOURCE_META_KEY === strtolower( trim( $key ) );
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
		self::assert_manual( $source );
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
			$source,
			ProtectedMetaWrite::for_manual_link( $product->id ),
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
	 * Removes a parent product's manual link, including invalid entries.
	 * Removing a link that does not exist changes nothing.
	 *
	 * A category-managed link is rejected with `category_managed` (ADR-033
	 * point 5): it is removed by taking the product out of the category, or
	 * after "Keep as manual". The category reconciliation removes such links
	 * through apply_category_link(), not here.
	 *
	 * @param int               $product_id Parent product id.
	 * @param ProductLinkSource $source     Write path.
	 * @return list<AuditAction> The changes made (empty when nothing changed).
	 * @throws ProductCreatorLinkException On authorization or validation failure.
	 * @throws DatabaseException When the change cannot be stored. The audit record is rolled back; the product meta only when `wp_postmeta` is transactional.
	 */
	public function unlink( int $product_id, ProductLinkSource $source ): array {
		self::assert_manual( $source );
		$product = $this->removable_product( $product_id );

		if ( ! $product->has_link() ) {
			return array();
		}

		$previous = $product->creator_id();

		return $this->write(
			$product,
			null,
			$source,
			ProtectedMetaWrite::for_manual_link( $product->id ),
			fn(): array => array( $this->record_unlinked( $product, $previous, $source ) )
		);
	}

	/**
	 * "Keep as manual" (ADR-033 point 4): turns a parent product's
	 * category-managed link to this creator into a manual one. The creator is
	 * kept; only the origin changes from `category` to `admin`, so the category
	 * reconciliation never touches the link again. A manual operation: the
	 * current user needs `merchboard_manage_creators` and `edit_product` for
	 * the product, and is the audit actor.
	 *
	 * A product that already holds a manual link to this creator is left
	 * unchanged (a repeat is a no-op). A product without a link to this
	 * creator, or with an invalid link, is rejected with
	 * `not_category_managed`: the link changed since the admin decided, and it
	 * is not converted. The creator may be inactive (the link exists already).
	 *
	 * @param int                  $product_id Parent product id.
	 * @param int                  $creator_id The creator the link must point at.
	 * @param array<string, mixed> $context    Extra audit context (e.g. `term_id`, `choice`).
	 * @return list<AuditAction> The changes made (empty when nothing changed).
	 * @throws ProductCreatorLinkException On authorization or validation failure.
	 * @throws DatabaseException When the change cannot be stored. The audit record is rolled back; the product meta only when `wp_postmeta` is transactional.
	 */
	public function keep_as_manual( int $product_id, int $creator_id, array $context = array() ): array {
		self::authorize_management();
		$product = $this->convertible_product( $product_id, $creator_id );

		if ( ! $product->is_category_managed() ) {
			return array();
		}

		return $this->write(
			$product,
			$creator_id,
			ProductLinkSource::Admin,
			ProtectedMetaWrite::for_manual_link( $product->id ),
			function () use ( $product, $creator_id, $context ): array {
				$this->audit->record(
					AuditAction::ProductCreatorLinked,
					$creator_id,
					array(
						'product_id'          => $product->id,
						'previous_creator_id' => $creator_id,
						'source'              => ProductLinkSource::Admin->value,
						'previous_source'     => ProductLinkSource::Category->value,
					) + $context
				);

				return array( AuditAction::ProductCreatorLinked );
			}
		);
	}

	/**
	 * ADR-033's internal write path for the category reconciliation
	 * (CreatorCategoryReconciler): sets a parent product's category-managed
	 * link to the creator, or removes it for null. System work: no current
	 * user or capability is checked (the mapping was created by an authorized
	 * administrator); the audit actor is NULL, and the user who triggered the
	 * run, if known, is recorded as `trigger_user_id`.
	 *
	 * It never touches a manual link: a product with a manual (`admin`, or no
	 * origin) or invalid link is rejected with `manual_link`. A new link needs
	 * an existing, active creator; removing one does not. An unchanged state
	 * changes nothing. Writes go through ProtectedMetaWrite::for_category_reconciliation()
	 * for exactly this product, with the audit records in one transaction.
	 *
	 * @param int                  $product_id Parent product id.
	 * @param int|null             $creator_id Creator the product follows, or null to remove the category link.
	 * @param string               $reason     Audit reason (ADR-033), e.g. `category_added` or `category_moved`.
	 * @param array<string, mixed> $context    Extra audit context (`category_ids`, `trigger_user_id`, `run_id`).
	 * @return list<AuditAction> The changes made (empty when nothing changed).
	 * @throws ProductCreatorLinkException When the product or creator does not qualify, or the link is manual.
	 * @throws DatabaseException When the change cannot be stored. The audit record is rolled back; the product meta only when `wp_postmeta` is transactional.
	 */
	public function apply_category_link( int $product_id, ?int $creator_id, string $reason, array $context = array() ): array {
		$product  = $this->reconcilable_product( $product_id );
		$previous = $product->creator_id();

		if ( $previous === $creator_id ) {
			return array();
		}

		$creator = null === $creator_id ? null : $this->active_creator( $creator_id );
		$extra   = array( 'reason' => $reason ) + $context;

		return $this->write(
			$product,
			$creator?->id,
			ProductLinkSource::Category,
			ProtectedMetaWrite::for_category_reconciliation( $product->id ),
			function () use ( $product, $previous, $creator, $extra ): array {
				$changes = array();

				if ( null !== $previous ) {
					$changes[] = $this->record_unlinked( $product, $previous, ProductLinkSource::Category, $extra );
				}

				if ( null !== $creator ) {
					$this->audit->record_system(
						AuditAction::ProductCreatorLinked,
						$creator->id,
						array(
							'product_id'          => $product->id,
							'previous_creator_id' => $previous,
							'source'              => ProductLinkSource::Category->value,
						) + $extra
					);
					$changes[] = AuditAction::ProductCreatorLinked;
				}

				return $changes;
			}
		);
	}

	/**
	 * Removes a product's link to a creator that is being deleted (phase 27,
	 * step 27.3, ADR-076), manual or category-managed. Only while the link
	 * points at that creator; anything else is left unchanged. Audited as
	 * `product_creator_unlinked` with `reason: creator_deleted`, in one
	 * transaction with the write (ProtectedMetaWrite::for_creator_deletion()).
	 * The caller (CreatorDeletion) authorizes the deletion.
	 *
	 * @param int $product_id Parent product id.
	 * @param int $creator_id The creator being deleted.
	 * @return bool Whether the link was removed.
	 * @throws DatabaseException When the change cannot be stored.
	 */
	public function release_for_deleted_creator( int $product_id, int $creator_id ): bool {
		$product = $product_id > 0 ? $this->products->find( $product_id ) : null;

		if ( null === $product || $creator_id !== $product->creator_id() ) {
			return false;
		}

		$source = $product->is_category_managed() ? ProductLinkSource::Category : ProductLinkSource::Admin;

		$this->write(
			$product,
			null,
			$source,
			ProtectedMetaWrite::for_creator_deletion( $product->id ),
			fn(): array => array( $this->record_unlinked( $product, $creator_id, ProductLinkSource::Admin, array( 'reason' => 'creator_deleted' ) ) )
		);

		return true;
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
	 * Loads a product whose link may be removed manually: a linkable product
	 * whose link is not category-managed (ADR-033 point 5).
	 *
	 * @param int $product_id Product id.
	 * @throws ProductCreatorLinkException When the product does not qualify or its link is category-managed.
	 */
	private function removable_product( int $product_id ): ProductRecord {
		$product = $this->linkable_product( $product_id );

		if ( $product->is_category_managed() ) {
			throw new ProductCreatorLinkException( ProductCreatorLinkException::CATEGORY_MANAGED );
		}

		return $product;
	}

	/**
	 * Loads a product whose link to the creator may be kept as manual: an
	 * editable, supported parent product with a valid link to exactly that
	 * creator (category-managed, or already manual).
	 *
	 * @param int $product_id Product id.
	 * @param int $creator_id Creator id.
	 * @throws ProductCreatorLinkException When the product does not qualify or holds no valid link to the creator.
	 */
	private function convertible_product( int $product_id, int $creator_id ): ProductRecord {
		$product = $this->linkable_product( $product_id );

		if ( $product->creator_id() !== $creator_id || $creator_id < 1 ) {
			throw new ProductCreatorLinkException( ProductCreatorLinkException::NOT_CATEGORY_MANAGED );
		}

		return $product;
	}

	/**
	 * Requires the creator-management capability (ADR-033 point 7, manual
	 * operations besides the per-product `edit_product`).
	 *
	 * @throws ProductCreatorLinkException When the current user lacks it.
	 */
	private static function authorize_management(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			throw new ProductCreatorLinkException( ProductCreatorLinkException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Loads a product the category reconciliation may write: an existing
	 * parent product in a supported status without a manual link. No
	 * capability check (system work, see apply_category_link()).
	 *
	 * @param int $product_id Product id.
	 * @throws ProductCreatorLinkException When the product is unknown, a variation, not supported or manually linked.
	 */
	private function reconcilable_product( int $product_id ): ProductRecord {
		$product = $product_id > 0 ? $this->products->find( $product_id ) : null;

		if ( null === $product ) {
			throw new ProductCreatorLinkException( ProductCreatorLinkException::PRODUCT_NOT_FOUND );
		}

		if ( $product->is_variation ) {
			throw new ProductCreatorLinkException( ProductCreatorLinkException::VARIATION );
		}

		if ( in_array( $product->status, self::UNSUPPORTED_STATUSES, true ) ) {
			throw new ProductCreatorLinkException( ProductCreatorLinkException::PRODUCT_NOT_SUPPORTED );
		}

		if ( $product->has_manual_link() ) {
			throw new ProductCreatorLinkException( ProductCreatorLinkException::MANUAL_LINK );
		}

		return $product;
	}

	/**
	 * The manual entry points write `admin` links only; category links are
	 * written by apply_category_link().
	 *
	 * @param ProductLinkSource $source Requested source.
	 * @throws InvalidArgumentException When the category source is requested.
	 */
	private static function assert_manual( ProductLinkSource $source ): void {
		if ( ProductLinkSource::Admin !== $source ) {
			throw new InvalidArgumentException( 'Category links are written by apply_category_link().' );
		}
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
	 * @param ProductLinkSource             $source     Origin stored with the link.
	 * @param ProtectedMetaWrite            $grant      The approved write for this product and path.
	 * @param callable(): list<AuditAction> $audit      Writes the audit records.
	 * @return list<AuditAction>
	 * @throws Throwable Whatever the audit or product write throws (after rollback), e.g. a DatabaseException.
	 */
	private function write( ProductRecord $product, ?int $creator_id, ProductLinkSource $source, ProtectedMetaWrite $grant, callable $audit ): array {
		try {
			return $this->transaction->run(
				function () use ( $product, $creator_id, $source, $grant, $audit ): array {
					$changes = $audit();

					// Everything above validated this product; the guard lets
					// exactly this write through (ProtectedProductMeta).
					$this->guard->run(
						$grant,
						fn() => $this->products->write_creator( $product->id, $creator_id, $source )
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
	 * Category removals are system records (actor NULL) with the extra context.
	 *
	 * @param ProductRecord        $product  Product.
	 * @param int|null             $previous Previously linked creator, or null when the link was invalid.
	 * @param ProductLinkSource    $source   Write path.
	 * @param array<string, mixed> $extra    Extra context (category path: reason etc.).
	 */
	private function record_unlinked( ProductRecord $product, ?int $previous, ProductLinkSource $source, array $extra = array() ): AuditAction {
		$context = array(
			'product_id' => $product->id,
			'source'     => $source->value,
		) + $extra;

		if ( null === $previous ) {
			$context['previous_link'] = 'invalid';
		}

		if ( ProductLinkSource::Category === $source ) {
			$this->audit->record_system( AuditAction::ProductCreatorUnlinked, $previous, $context );
		} else {
			$this->audit->record( AuditAction::ProductCreatorUnlinked, $previous, $context );
		}

		return AuditAction::ProductCreatorUnlinked;
	}
}
