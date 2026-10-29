<?php
/**
 * The current price of designer products.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner;

use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerPrice;
use MerchBoard\Modules\WooCommerce\Pricing\DesignerPriceSource;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;
use MerchBoard\Modules\WooCommerce\Pricing\PricingProductStore;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use OverflowException;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * DesignerPriceSource on the designer's own tables (ADR-080 pkt. 8): the base
 * price behind the product's price in the shop (`shop_base_price`, step
 * 30.11), so a sale is settled at the price the customer saw even before a
 * new base price reached the shop; the base product's price now while no
 * version was applied. The creator's profit is the one of the
 * live version, a percent of the base price or a fixed amount, both incl.
 * VAT. Each is rounded to the store precision on its own, the base price up
 * and the profit half away from zero, so the list price (base + profit) is
 * never below base + profit and the company never gets less than its base
 * price from a rounding.
 *
 * Null only when the WooCommerce product is not a designer product, so a
 * legacy product keeps the share model. A designer product that cannot be
 * priced (no live version, an invalid row) throws instead: it never falls
 * back to the share model, and its profit stays unknown.
 */
final class DesignerPrices implements DesignerPriceSource {

	/**
	 * Basis of a percent value in units: 100 % × 10 000.
	 */
	private const PERCENT_BASIS = 1000000;

	/**
	 * Constructor.
	 *
	 * @param wpdb                $wpdb     Database.
	 * @param PricingProductStore $products The store precision.
	 */
	public function __construct( private wpdb $wpdb, private PricingProductStore $products ) {
	}

	/**
	 * The designer price of a parent product.
	 *
	 * @param int $product_id Parent product.
	 * @throws DatabaseException When the designer's data cannot be read or the product cannot be priced.
	 * @phpstan-impure Reads the database.
	 */
	public function price( int $product_id ): ?DesignerPrice {
		if ( $product_id < 1 ) {
			return null;
		}

		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own tables; a price is read once per order line or cart item.
			$wpdb->prepare(
				'SELECT p.creator_id, p.live_version_id, p.shop_base_price, v.profit_kind, v.profit_value, b.base_price, b.currency
				FROM %i p
				LEFT JOIN %i v ON v.id = p.live_version_id AND v.design_product_id = p.id
				LEFT JOIN %i b ON b.id = p.base_id
				WHERE p.wc_product_id = %d',
				Tables::name( $wpdb, Tables::DESIGNER_PRODUCTS ),
				Tables::name( $wpdb, Tables::DESIGNER_VERSIONS ),
				Tables::name( $wpdb, Tables::DESIGNER_BASES ),
				$product_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			if ( '' !== $wpdb->last_error ) {
				throw new DatabaseException( 'Could not read the designer product.' );
			}

			return null;
		}

		if ( null === $row['live_version_id'] || null === $row['profit_kind'] || null === $row['base_price'] ) {
			throw new DatabaseException( 'The designer product has no live price.', array( 'product_id' => $product_id ) );
		}

		try {
			return self::compute( (int) $row['creator_id'], (string) ( $row['shop_base_price'] ?? $row['base_price'] ), (string) $row['profit_kind'], (string) $row['profit_value'], (string) $row['currency'], $this->products->decimals() );
		} catch ( InvalidArgumentException | OverflowException ) {
			throw new DatabaseException( 'The designer product cannot be priced.', array( 'product_id' => $product_id ) );
		}
	}

	/**
	 * The price from a base price and a profit setting.
	 *
	 * @param int    $creator_id   Owner.
	 * @param string $base_price   Base price incl. VAT (decimal).
	 * @param string $profit_kind  `percent` of the base price or `fixed` amount.
	 * @param string $profit_value The percent or the amount incl. VAT (decimal, ≥ 0).
	 * @param string $currency     Currency of the base price.
	 * @param int    $decimals     Store precision.
	 * @throws InvalidArgumentException When a value is invalid.
	 */
	public static function compute( int $creator_id, string $base_price, string $profit_kind, string $profit_value, string $currency, int $decimals ): DesignerPrice {
		$step  = PricingMath::step( $decimals );
		$base  = SalesAmount::parse( $base_price );
		$value = SalesAmount::parse( $profit_value );

		if ( $base < 0 || $value < 0 ) {
			throw new InvalidArgumentException( 'A designer price cannot be negative.' );
		}

		// The base price is rounded up, so the company never gets less than it set.
		$base   = intdiv( $base + $step - 1, $step ) * $step;
		$profit = match ( $profit_kind ) {
			'percent' => PricingMath::mul_div_round( $base, $value, self::PERCENT_BASIS * $step ) * $step,
			'fixed'   => PricingMath::mul_div_round( $value, 1, $step ) * $step,
			default   => throw new InvalidArgumentException( 'Unknown profit kind.' ),
		};

		return new DesignerPrice( $creator_id, $base, $profit, $currency );
	}
}
