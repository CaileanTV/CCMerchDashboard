<?php
/**
 * A design's prices for free choice, read from WooCommerce.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

use Closure;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use WC_Product;
use WC_Product_Variable;
use WC_Tax;

defined( 'ABSPATH' ) || exit;

/**
 * The two prices FreeChoice compares, in the ledger's units and both with
 * VAT: what the customer pays and the base price, which is the rest after
 * the creator's profit.
 *
 * The VAT is the shop address's, never the visitor's as in WooCommerce's
 * wc_get_price_including_tax(): the lock is read again when the winner
 * shops, and a winner abroad would otherwise see every price changed.
 *
 * A variant sold out still counts: it costs what it costs. What a gift would
 * pay is the cheapest variant in stock, as GiftPurchase::cheapest().
 */
final class FreeChoicePrices {

	/**
	 * The creator of a parent product, or null.
	 *
	 * @var Closure(int): ?int
	 */
	private Closure $creator_of;

	/**
	 * The creator's profit on one unit: product, creator, price, variation.
	 *
	 * @var Closure(int, int, float, int): ?float
	 */
	private Closure $profit;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): ?int $creator_of
	 * @phpstan-param Closure(int, int, float, int): ?float $profit
	 *
	 * @param Closure $creator_of The creator of a parent product, or null.
	 * @param Closure $profit     The creator's profit on one unit (CreatorProfitDiscount::per_unit()).
	 * @param Logger  $logger     Technical log.
	 */
	public function __construct( Closure $creator_of, Closure $profit, private Logger $logger ) {
		$this->creator_of = $creator_of;
		$this->profit     = $profit;
	}

	/**
	 * A design with its prices now, or null when it is not sold or cannot be
	 * priced.
	 *
	 * @param int $product_id Parent product.
	 * @return array{design: array{creator: int, variants: list<array{attributes: array<string, string>, price: int, base: int}>}, pays: int|null}|null
	 */
	public function read( int $product_id ): ?array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof WC_Product || ! $product->is_purchasable() ) {
			return null;
		}

		$creator = ( $this->creator_of )( $product_id );

		if ( null === $creator ) {
			return null;
		}

		$items = array( $product );

		if ( $product instanceof WC_Product_Variable ) {
			$items = array();

			foreach ( $product->get_children() as $child ) {
				$variation = wc_get_product( (int) $child );

				if ( $variation instanceof WC_Product && $variation->is_purchasable() ) {
					$items[] = $variation;
				}
			}
		}

		$variants = array();
		$pays     = null;

		try {
			foreach ( $items as $item ) {
				$variation_id = $item->get_id() === $product_id ? 0 : (int) $item->get_id();
				$entered      = (float) $item->get_price( 'edit' );
				$profit       = $entered > 0 ? ( $this->profit )( $product_id, $creator, $entered, $variation_id ) : null;

				if ( null === $profit ) {
					return null;
				}

				$gross = self::gross( $item, $entered );
				$price = self::units( $gross );
				$base  = self::units( $gross * max( 0.0, $entered - $profit ) / $entered );

				$variants[] = array(
					'attributes' => 0 === $variation_id ? array() : self::attributes( $item ),
					'price'      => $price,
					'base'       => $base,
				);

				if ( $item->is_in_stock() && ( null === $pays || $price < $pays ) ) {
					$pays = $price;
				}
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A design could not be priced for free choice.', array( 'product_id' => $product_id ) + $e->context() );

			return null;
		}

		return array() === $variants ? null : array(
			'design' => array(
				'creator'  => $creator,
				'variants' => $variants,
			),
			'pays'   => $pays,
		);
	}

	/**
	 * A variation's attributes as text.
	 *
	 * @param WC_Product $variation The variation.
	 * @return array<string, string>
	 */
	private static function attributes( WC_Product $variation ): array {
		$attributes = array();

		foreach ( $variation->get_attributes() as $name => $value ) {
			$attributes[ (string) $name ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $attributes;
	}

	/**
	 * A price with VAT at the shop's address.
	 *
	 * @param WC_Product $item    Product or variation (which answers with its parent's tax class).
	 * @param float      $entered The price as the shop enters it.
	 */
	private static function gross( WC_Product $item, float $entered ): float {
		if ( ! wc_tax_enabled() || wc_prices_include_tax() || 'taxable' !== $item->get_tax_status() ) {
			return $entered;
		}

		$percent = 0.0;

		foreach ( WC_Tax::get_base_tax_rates( (string) $item->get_tax_class() ) as $rate ) {
			$percent += is_numeric( $rate['rate'] ?? null ) ? (float) $rate['rate'] : 0.0;
		}

		return $entered * ( 1 + $percent / 100 );
	}

	/**
	 * An amount in the ledger's units.
	 *
	 * @param float $amount Amount.
	 */
	private static function units( float $amount ): int {
		return SalesAmount::parse( number_format( $amount, 4, '.', '' ) );
	}
}
