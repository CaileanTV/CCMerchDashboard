<?php
/**
 * The price of a designer product now.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

defined( 'ABSPATH' ) || exit;

/**
 * A designer product's owner, base price and the creator's profit per unit
 * (ADR-080), in 1/10 000 units incl. VAT at the store precision, in the
 * currency of the base price. The list price is base + profit; the profit is
 * never negative, so the list price is never below the base price (only the
 * company's own discounts can take it below).
 */
final class DesignerPrice {

	/**
	 * Constructor.
	 *
	 * @param int    $creator_id   Owner.
	 * @param int    $base_units   Base price per unit incl. VAT.
	 * @param int    $profit_units The creator's profit per unit incl. VAT.
	 * @param string $currency     ISO 4217 code of the amounts.
	 * @throws InvalidArgumentException When a value is out of range.
	 */
	public function __construct(
		public readonly int $creator_id,
		public readonly int $base_units,
		public readonly int $profit_units,
		public readonly string $currency
	) {
		if ( $creator_id < 1 || $base_units < 0 || $profit_units < 0 || SalesAmount::add( $base_units, $profit_units ) < 1 || 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			throw new InvalidArgumentException( 'Invalid designer price.' );
		}
	}

	/**
	 * The list price per unit (base + profit).
	 */
	public function price_units(): int {
		return SalesAmount::add( $this->base_units, $this->profit_units );
	}
}
