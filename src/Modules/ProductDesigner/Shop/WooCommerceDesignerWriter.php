<?php
/**
 * The only writer of designer products' WooCommerce data and prices.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Shop;

use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;
use WC_Product_Variable;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-080 pkt. 6–7, the exception to ADR-049 pkt. 1: Merch Dashboard writes
 * WooCommerce products and prices, but only here and only for designer
 * products. An architecture test fails when a price is written anywhere
 * else.
 *
 * - create_draft() creates the hidden draft of a new designer product with
 *   the row's token as pending meta, then DesignerProductGuard claims and
 *   marks it. Repeated after an interruption it finds the same draft, so no
 *   second product is created.
 * - Every write to an existing product takes a GuardedProduct, the guard's
 *   proof; a legacy product can never be passed.
 * - Prices are written only when the store enters prices including VAT
 *   (base price and profit are incl. VAT), as the regular price of every
 *   variation; a sale price, which only the company sets, is left alone.
 *
 * WooCommerce CRUD only, no direct SQL; no transaction around WooCommerce
 * saves (HPOS commits outer transactions); every step can be repeated.
 */
class WooCommerceDesignerWriter {

	/**
	 * Constructor.
	 *
	 * @param DesignerProductGuard $guard The legacy guard.
	 */
	public function __construct( private DesignerProductGuard $guard ) {
	}

	/**
	 * The designer product's WooCommerce draft: the one created earlier for
	 * the row, or a new hidden draft of a variable product.
	 *
	 * @param int    $design_product_id Row in `merchboard_designer_products`.
	 * @param string $name              Product name.
	 * @throws DesignerException When the row cannot get a product (unknown, or it names a product that is not its own).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create_draft( int $design_product_id, string $name ): GuardedProduct {
		$existing = $this->guard->for_row( $design_product_id );

		if ( null !== $existing ) {
			return $existing;
		}

		$token = $this->guard->token( $design_product_id );

		return $this->guard->claim( $design_product_id, $this->pending( $token ) ?? self::new_draft( $token, $name ) );
	}

	/**
	 * Sets the price of every variation of a designer product.
	 *
	 * @param GuardedProduct $product     The guard's proof.
	 * @param int            $price_units Price per unit incl. VAT in 1/10 000 units (base + profit).
	 * @param int            $decimals    Store price decimals.
	 * @throws DesignerException When the store enters prices excluding VAT, or the product is not a variable product.
	 * @throws InvalidArgumentException When the price cannot be written with the store precision.
	 */
	public function set_prices( GuardedProduct $product, int $price_units, int $decimals ): void {
		if ( wc_tax_enabled() && ! wc_prices_include_tax() ) {
			self::refuse( DesignerException::PRICES_EXCL_TAX );
		}

		$price  = PricingMath::price_string( $price_units, $decimals );
		$parent = wc_get_product( $product->wc_product_id );

		if ( ! $parent instanceof WC_Product_Variable ) {
			self::refuse( DesignerException::NOT_DESIGNER_PRODUCT );
		}

		foreach ( $parent->get_children() as $child_id ) {
			$variation = wc_get_product( (int) $child_id );

			if ( $variation instanceof WC_Product_Variation && $variation->get_parent_id( 'edit' ) === $product->wc_product_id && $variation->get_regular_price( 'edit' ) !== $price ) {
				$variation->set_regular_price( $price );
				$variation->save();
			}
		}

		WC_Product_Variable::sync( $product->wc_product_id );
	}

	/**
	 * A draft created earlier for a token and not claimed yet.
	 *
	 * @param string $token Creation token.
	 */
	private function pending( string $token ): ?int {
		$ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'numberposts'      => 2,
				'suppress_filters' => true,
				'meta_key'         => DesignerProductGuard::PENDING_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Once per new designer product.
				'meta_value'       => $token, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Once per new designer product.
			)
		);

		return 1 === count( $ids ) ? (int) $ids[0] : null;
	}

	/**
	 * A new hidden draft of a variable product carrying the pending token.
	 *
	 * @param string $token Creation token.
	 * @param string $name  Product name.
	 * @throws DatabaseException When WooCommerce cannot save it.
	 */
	private static function new_draft( string $token, string $name ): int {
		$product = new WC_Product_Variable();
		$product->set_name( $name );
		$product->set_status( 'draft' );
		$product->update_meta_data( DesignerProductGuard::PENDING_KEY, $token );
		$id = (int) $product->save();

		if ( $id < 1 ) {
			throw new DatabaseException( 'Could not create the designer product draft.' );
		}

		return $id;
	}

	/**
	 * Refuses a write.
	 *
	 * @param string $reason DesignerException reason.
	 * @throws DesignerException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new DesignerException( $reason );
	}
}
