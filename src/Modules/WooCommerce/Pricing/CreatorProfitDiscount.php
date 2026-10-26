<?php
/**
 * The creator's profit as a discount.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

defined( 'ABSPATH' ) || exit;

/**
 * The discount of a creator's own code (ADR-078): on each of the creator's
 * products exactly the creator's profit (ADR-049), whatever share applies to
 * that product (the creator's default or the product's own). The one place
 * the profit of a unit is computed for a discount; a later profit as an
 * amount (creators' own products on a base price) plugs in here.
 *
 * The price is the unit price WooCommerce discounts (including VAT when the
 * shop enters prices including VAT, otherwise excluding it); WooCommerce
 * lowers the VAT in proportion, so the customer pays the price including VAT
 * less the share.
 */
final class CreatorProfitDiscount {

	/**
	 * Precision of the discount (4 decimals, as the ledger's units);
	 * WooCommerce rounds the line's discount to its own precision.
	 */
	private const DECIMALS = 4;

	/**
	 * Constructor.
	 *
	 * @param MarkupResolver $markups The share of a product now.
	 */
	public function __construct( private MarkupResolver $markups ) {
	}

	/**
	 * Whether a parent product belongs to the creator.
	 *
	 * @param int $product_id Parent product.
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the pricing data cannot be read.
	 */
	public function owns( int $product_id, int $creator_id ): bool {
		return $creator_id === $this->markups->resolve( $product_id )?->creator_id;
	}

	/**
	 * The creator's profit on one unit of a parent product, or null when the
	 * product is not the creator's.
	 *
	 * @param int   $product_id Parent product.
	 * @param int   $creator_id Creator.
	 * @param float $unit_price The unit price WooCommerce discounts.
	 * @throws DatabaseException When the pricing data cannot be read.
	 */
	public function per_unit( int $product_id, int $creator_id, float $unit_price ): ?float {
		$markup = $this->markups->resolve( $product_id );

		if ( null === $markup || $creator_id !== $markup->creator_id ) {
			return null;
		}

		if ( $unit_price <= 0.0 ) {
			return 0.0;
		}

		$profit = CreatorShare::profit( SalesAmount::parse( number_format( $unit_price, self::DECIMALS, '.', '' ) ), $markup->markup->bps, self::DECIMALS );

		return min( $unit_price, (float) SalesAmount::format( $profit ) );
	}
}
