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
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Designs\SubmissionFiles;
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
 *
 * A base product can have a price per size (the owner's choice 2026-10-31):
 * `shop_base_prices` then holds the base price behind each size in the shop,
 * and a sale is settled at its variation's size. A line whose size cannot be
 * told gets the highest, so the company never gets less than it set.
 */
final class DesignerPrices implements DesignerPriceSource {

	/**
	 * The size of a variation (post meta): its base price is the one of that
	 * size. Written with the variation's price.
	 */
	public const SIZE_META = '_merchboard_designer_size';

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
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Variation; 0 when there is none.
	 * @throws DatabaseException When the designer's data cannot be read or the product cannot be priced.
	 * @phpstan-impure Reads the database.
	 */
	public function price( int $product_id, int $variation_id = 0 ): ?DesignerPrice {
		if ( $product_id < 1 ) {
			return null;
		}

		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own tables; a price is read once per order line or cart item.
			$wpdb->prepare(
				'SELECT p.creator_id, p.live_version_id, p.shop_base_price, p.shop_base_prices, v.profit_kind, v.profit_value, b.base_price, b.currency
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
			return self::compute( (int) $row['creator_id'], self::size_base( $row['shop_base_prices'] ?? null, $variation_id ) ?? (string) ( $row['shop_base_price'] ?? $row['base_price'] ), (string) $row['profit_kind'], (string) $row['profit_value'], (string) $row['currency'], $this->products->decimals() );
		} catch ( InvalidArgumentException | OverflowException ) {
			throw new DatabaseException( 'The designer product cannot be priced.', array( 'product_id' => $product_id ) );
		}
	}

	/**
	 * The base price of a variation's size, or null with one price for all.
	 *
	 * @param mixed $stored       `shop_base_prices`.
	 * @param int   $variation_id Variation.
	 * @throws InvalidArgumentException When a stored price is invalid.
	 */
	private static function size_base( mixed $stored, int $variation_id ): ?string {
		$bases = is_string( $stored ) ? json_decode( $stored, true ) : null;

		if ( ! is_array( $bases ) || array() === $bases ) {
			return null;
		}

		$size = $variation_id > 0 ? get_post_meta( $variation_id, self::SIZE_META, true ) : '';

		if ( is_string( $size ) && isset( $bases[ $size ] ) ) {
			return (string) $bases[ $size ];
		}

		$prices = array_map( 'strval', $bases );

		usort( $prices, static fn( string $a, string $b ): int => SalesAmount::parse( $b ) <=> SalesAmount::parse( $a ) );

		return $prices[0];
	}

	/**
	 * What the company keeps of a design: the base price, which includes the
	 * most expensive used design area, and the price of every further used
	 * area, a small one's or a large one's (the owner's choices 2026-10-30 and
	 * 2026-11-03). A profit in percent is taken of this amount. A design
	 * with one size has that size's base price. With sides per size, only the
	 * areas of one size count: the other sizes' sides are other products.
	 *
	 * @param BaseProduct              $base   Base product.
	 * @param array<mixed>|string      $design The design, or its JSON.
	 * @param array<mixed>|string|null $sizes  The chosen sizes, or their JSON.
	 */
	public static function secured_base( BaseProduct $base, array|string $design, array|string|null $sizes = null ): int {
		$decoded = is_string( $design ) ? json_decode( $design, true ) : $design;
		$chosen  = is_string( $sizes ) ? json_decode( $sizes, true ) : $sizes;
		$size    = is_array( $chosen ) && 1 === count( $chosen ) ? reset( $chosen ) : null;
		$extra   = is_array( $decoded ) ? $base->extra_amount( SubmissionFiles::designed_sides( $decoded ), is_array( $chosen ) ? array_values( array_filter( $chosen, 'is_string' ) ) : null ) : 0;

		return $base->price_of( is_string( $size ) ? $size : null ) + $extra;
	}

	/**
	 * The price of each chosen size; empty with one price for all sizes.
	 *
	 * @param BaseProduct $base         Base product.
	 * @param int         $creator_id   Owner.
	 * @param string      $design       The design's JSON.
	 * @param string      $sizes        The chosen sizes' JSON.
	 * @param string      $profit_kind  `percent` or `fixed`.
	 * @param string      $profit_value The percent or the amount (decimal).
	 * @param int         $decimals     Store precision.
	 * @return array<string, DesignerPrice>
	 * @throws InvalidArgumentException When a value is invalid.
	 */
	public static function size_prices( BaseProduct $base, int $creator_id, string $design, string $sizes, string $profit_kind, string $profit_value, int $decimals ): array {
		$chosen = json_decode( $sizes, true );
		$prices = array();

		if ( array() === $base->size_prices || ! is_array( $chosen ) ) {
			return $prices;
		}

		foreach ( array_filter( $chosen, 'is_string' ) as $size ) {
			$prices[ $size ] = self::compute( $creator_id, SalesAmount::format( self::secured_base( $base, $design, array( $size ) ) ), $profit_kind, $profit_value, $base->currency, $decimals );
		}

		return $prices;
	}

	/**
	 * What the shop stores of the prices per size: the base price by size.
	 *
	 * @param array<string, DesignerPrice> $prices Prices by size.
	 */
	public static function size_bases( array $prices ): ?string {
		return array() === $prices ? null : (string) wp_json_encode( array_map( static fn( DesignerPrice $price ): string => SalesAmount::format( $price->base_units ), $prices ) );
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
