<?php
/**
 * What a giveaway costs its creator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Giveaways;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Community\Gifting\GiftPurchase;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorProfitDiscount;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-110 pkt. 2: the code covers the product's cheapest variant and up to
 * 50 kr. of the shipping, like a Twitch gift. The creator pays the base
 * price (the price less the creator's own profit, so the creator neither
 * earns on the giveaway nor pays its own share) plus the 50 kr.
 */
final class GiveawayCost {

	/**
	 * Constructor.
	 *
	 * @param CreatorProfitDiscount $profit The creator's profit of a unit.
	 */
	public function __construct( private CreatorProfitDiscount $profit ) {
	}

	/**
	 * The price of giving a product away now, or null when the product is not
	 * the creator's or cannot be bought.
	 *
	 * @param int $product_id Parent product.
	 * @param int $creator_id Creator.
	 * @return array{variation_id: int, unit_price: string, base_price: string, shipping: string, cost: string, currency: string}|null
	 *         Decimals: `unit_price` is what the code covers (incl. VAT), `base_price` the line's price in the shop
	 *         (as the shop enters prices), `cost` the base price incl. VAT plus the shipping.
	 * @throws DatabaseException When the pricing data cannot be read.
	 */
	public function quote( int $product_id, int $creator_id ): ?array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status() || ! $product->is_purchasable() ) {
			return null;
		}

		$variation_id = GiftPurchase::cheapest( $product )[0];
		$variant      = $variation_id > 0 ? wc_get_product( $variation_id ) : $product;

		if ( ! $variant instanceof WC_Product || ! $variant->is_purchasable() ) {
			return null;
		}

		$price  = (float) $variant->get_price( 'edit' );
		$profit = $price > 0 ? $this->profit->per_unit( $product_id, $creator_id, $price, $variation_id ) : null;

		if ( null === $profit ) {
			return null;
		}

		$gross    = (float) wc_get_price_including_tax( $variant, array( 'price' => $price ) );
		$base     = max( 0.0, $price - $profit );
		$base_inc = $price > 0 ? $gross * $base / $price : 0.0;

		return array(
			'variation_id' => $variation_id,
			'unit_price'   => self::decimal( $gross ),
			'base_price'   => self::decimal( $base ),
			'shipping'     => self::decimal( (float) GiftPurchase::SHIPPING ),
			'cost'         => self::decimal( round( $base_inc, 2 ) + (float) GiftPurchase::SHIPPING ),
			'currency'     => get_woocommerce_currency(),
		);
	}

	/**
	 * A decimal with 4 places.
	 *
	 * @param float $amount Amount.
	 */
	private static function decimal( float $amount ): string {
		return number_format( $amount, 4, '.', '' );
	}
}
