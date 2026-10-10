<?php
/**
 * Resolves the creator share of a product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;

defined( 'ABSPATH' ) || exit;

/**
 * The share that applies to a parent product now (ADR-049): its current
 * owner (ProductCreatorLink, the ownership source), the owner's default
 * markup and the product's own share. Read only and without authorization:
 * callers decide who may see it (the admin pricing section, and the
 * snapshot recorder as system work when an order line is created).
 */
final class MarkupResolver {

	/**
	 * Constructor.
	 *
	 * @param ProductCreatorLink      $links    Product ownership (read only).
	 * @param CreatorMarkupRepository $markups  Default markups.
	 * @param ProductMarkupRepository $products Product shares.
	 */
	public function __construct(
		private ProductCreatorLink $links,
		private CreatorMarkupRepository $markups,
		private ProductMarkupRepository $products
	) {
	}

	/**
	 * The share of a parent product, or null when the product has no owner
	 * (or the owner's creator record is gone).
	 *
	 * @param int $product_id Parent product.
	 * @throws DatabaseException When the pricing data cannot be read.
	 */
	public function resolve( int $product_id ): ?EffectiveMarkup {
		$owner  = $product_id > 0 ? $this->links->creator_id( $product_id ) : null;
		$markup = null === $owner ? null : $this->markups->find( $owner );

		if ( null === $owner || null === $markup ) {
			return null;
		}

		return EffectiveMarkup::resolve( $owner, $markup, $this->products->find( $product_id ) );
	}
}
