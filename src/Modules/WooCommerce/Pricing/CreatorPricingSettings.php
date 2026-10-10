<?php
/**
 * Pricing configuration: creator markup, product markup and VAT status.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;

defined( 'ABSPATH' ) || exit;

/**
 * The only write path of the pricing configuration (ADR-048, ADR-049): a
 * creator's default markup (share of the WooCommerce sale price) and VAT
 * status, and a parent product's own share. Nothing here reads or writes a
 * WooCommerce price, and sales snapshots are never touched: a change applies
 * to order lines created afterwards only.
 *
 * - Authorization: `merchboard_manage_creator_pricing`; a product share also
 *   needs `edit_product` for the parent product (product configuration, as
 *   for the product link, ADR-030).
 * - Ownership: a product share is always bound to the parent product's
 *   current owner (ProductCreatorLink). The caller's creator id is only the
 *   expectation of the page: a product of another creator is rejected
 *   (`not_owned`), never re-bound.
 * - Values: a percentage with at most two decimals (MarkupBps, 0–100 %;
 *   "." or ","). No floats.
 * - Each change is stored and audited in one transaction; an unchanged
 *   value writes nothing and records nothing.
 */
final class CreatorPricingSettings {

	/**
	 * Constructor.
	 *
	 * @param CreatorRepository         $creators    Creators.
	 * @param CreatorMarkupRepository   $markups     Default markups.
	 * @param ProductMarkupRepository   $products    Product shares.
	 * @param ProductCreatorLink        $links       Product ownership (read only).
	 * @param PricingProductStore       $store       WooCommerce access (read only).
	 * @param AuditTrail                $audit       Audit trail.
	 * @param Transaction               $transaction Database transaction.
	 * @param CreatorVatRepository|null $vat         Creators' VAT status.
	 */
	public function __construct(
		private CreatorRepository $creators,
		private CreatorMarkupRepository $markups,
		private ProductMarkupRepository $products,
		private ProductCreatorLink $links,
		private PricingProductStore $store,
		private AuditTrail $audit,
		private Transaction $transaction,
		private ?CreatorVatRepository $vat = null
	) {
	}

	/**
	 * Sets whether a creator is VAT registered (ADR-048, ADR-049). It changes
	 * no WooCommerce price, no snapshot and no past payout. A storage failure
	 * throws a DatabaseException; nothing is stored then.
	 *
	 * @param int  $creator_id     Creator.
	 * @param bool $vat_registered VAT registered.
	 * @return bool Whether the status changed.
	 * @throws PricingException When not authorized or the creator is unknown.
	 */
	public function set_vat_registered( int $creator_id, bool $vat_registered ): bool {
		self::authorize();

		$vat      = $this->vat;
		$previous = $creator_id > 0 && null !== $vat ? $vat->find( $creator_id ) : null;

		if ( null === $vat || null === $previous ) {
			throw new PricingException( PricingException::CREATOR_NOT_FOUND );
		}

		if ( $previous === $vat_registered ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $vat, $creator_id, $vat_registered, $previous ): void {
				$vat->save( $creator_id, $vat_registered );
				$this->audit->record(
					AuditAction::CreatorVatStatusChanged,
					$creator_id,
					array(
						'previous_vat_registered' => $previous,
						'vat_registered'          => $vat_registered,
					)
				);
			}
		);

		return true;
	}

	/**
	 * Sets a creator's default markup (share of the sale price). A storage
	 * failure throws a DatabaseException; nothing is stored then.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $percent    Percentage, e.g. "19" or "17.5".
	 * @return bool Whether the markup changed.
	 * @throws PricingException When not authorized, the creator is unknown or the percentage is invalid.
	 */
	public function set_markup( int $creator_id, string $percent ): bool {
		self::authorize();

		if ( $creator_id < 1 || null === $this->creators->find( $creator_id ) ) {
			throw new PricingException( PricingException::CREATOR_NOT_FOUND );
		}

		$markup   = self::markup( $percent );
		$previous = $this->markups->find( $creator_id );

		if ( null !== $previous && $previous->bps === $markup->bps ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $creator_id, $markup, $previous ): void {
				$this->markups->save( $creator_id, $markup );
				$this->audit->record(
					AuditAction::CreatorMarkupChanged,
					$creator_id,
					array(
						'previous_markup_bps' => $previous?->bps,
						'markup_bps'          => $markup->bps,
					)
				);
			}
		);

		return true;
	}

	/**
	 * Sets (or replaces) a parent product's own share for its owner. A
	 * storage failure throws a DatabaseException; nothing is stored then.
	 *
	 * @param int    $product_id Parent product.
	 * @param int    $creator_id The creator the admin expects to own it.
	 * @param string $percent    Percentage, e.g. "25" or "12,5".
	 * @return bool Whether the share changed.
	 * @throws PricingException When not authorized or a value or the product is invalid.
	 */
	public function set_product_markup( int $product_id, int $creator_id, string $percent ): bool {
		$owner    = $this->owned_parent( $product_id, $creator_id );
		$markup   = self::markup( $percent );
		$previous = $this->products->find( $product_id );

		if ( null !== $previous && $previous->creator_id === $owner && $previous->markup->bps === $markup->bps ) {
			return false;
		}

		$product = new ProductMarkup( $product_id, $owner, $markup );

		$this->transaction->run(
			function () use ( $product, $previous, $owner, $product_id ): void {
				$this->products->save( $product );
				$this->audit->record(
					AuditAction::ProductMarkupSet,
					$owner,
					array(
						'product_id'          => $product_id,
						'markup_bps'          => $product->markup->bps,
						'previous_markup_bps' => null === $previous || ! $previous->belongs_to( $owner ) ? null : $previous->markup->bps,
					)
				);
			}
		);

		return true;
	}

	/**
	 * Removes a parent product's own share, so the owner's default markup
	 * applies again ("Use creator markup").
	 *
	 * @param int $product_id Parent product.
	 * @param int $creator_id The creator the admin expects to own it.
	 * @return bool Whether a share was removed.
	 * @throws PricingException When not authorized or the product is invalid.
	 * @throws DatabaseException When it cannot be removed (nothing is removed then).
	 */
	public function remove_product_markup( int $product_id, int $creator_id ): bool {
		$owner    = $this->owned_parent( $product_id, $creator_id );
		$previous = $this->products->find( $product_id );

		if ( null === $previous ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $previous, $owner, $product_id ): void {
				$this->products->delete( $product_id );
				$this->audit->record(
					AuditAction::ProductMarkupRemoved,
					$owner,
					array(
						'product_id'          => $product_id,
						'previous_markup_bps' => $previous->markup->bps,
					)
				);
			}
		);

		return true;
	}

	/**
	 * Checks a parent product the current user may configure and returns its
	 * owner, which must be the expected creator.
	 *
	 * @param int $product_id Parent product.
	 * @param int $creator_id Expected owner.
	 * @throws PricingException When not authorized, the product is missing, a variation or unsupported, or owned by nobody or another creator.
	 */
	private function owned_parent( int $product_id, int $creator_id ): int {
		self::authorize();
		$unit = $product_id > 0 ? $this->store->find( $product_id ) : null;

		if ( null === $unit ) {
			throw new PricingException( PricingException::PRODUCT_NOT_FOUND );
		}

		// The share belongs to the parent product (ADR-049): no variation shares.
		if ( ! in_array( $unit->type, array( 'simple', 'variable' ), true ) ) {
			throw new PricingException( PricingException::UNSUPPORTED_PRODUCT );
		}

		if ( ! current_user_can( Capabilities::EDIT_PRODUCT, $product_id ) ) {
			throw new PricingException( PricingException::NOT_AUTHORIZED );
		}

		$owner = $this->links->creator_id( $product_id );

		if ( null === $owner ) {
			throw new PricingException( PricingException::NO_OWNER );
		}

		if ( $owner !== $creator_id ) {
			throw new PricingException( PricingException::NOT_OWNED );
		}

		return $owner;
	}

	/**
	 * A percentage as basis points.
	 *
	 * @param string $percent Percentage with at most two decimals ("." or ",").
	 * @throws PricingException When it is not a percentage between 0 and 100.
	 */
	private static function markup( string $percent ): MarkupBps {
		try {
			return MarkupBps::from_percent( str_replace( ',', '.', $percent ) );
		} catch ( InvalidArgumentException ) {
			throw new PricingException( PricingException::INVALID_MARKUP );
		}
	}

	/**
	 * Requires the creator pricing capability.
	 *
	 * @throws PricingException When the current user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATOR_PRICING ) ) {
			throw new PricingException( PricingException::NOT_AUTHORIZED );
		}
	}
}
