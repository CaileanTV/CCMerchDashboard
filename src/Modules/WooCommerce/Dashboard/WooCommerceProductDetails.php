<?php
/**
 * WooCommerce implementation of the dashboard's product data.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Dashboard;

use WC_Product;
use WC_Product_Variable;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a product through WooCommerce CRUD (`wc_get_product()`), never with
 * SQL, and keeps only what ADR-039 allows:
 * - name: plain text (tags removed, entities decoded); the client renders it
 *   as text.
 * - price (P7 clarification): the active price as WooCommerce returns it,
 *   `get_price()` for a simple product (min = max) and the active variation
 *   prices of a variable product (`get_variation_prices()` without
 *   `$for_display`, so no tax adjustment). No tax is calculated: the basis is
 *   the store's `woocommerce_prices_include_tax`, reported as
 *   `tax: "store_setting"`. Amounts are decimal strings, never floats, and
 *   never HTML (`get_price_html()` is not used). No active price: null.
 * - stock: only the stock status, and only the three WooCommerce statuses.
 * - image (P8): the thumbnail URL only when it has the origin of home_url()
 *   (scheme, host and port), so the dashboard's CSP (`img-src 'self'`)
 *   allows it; otherwise null.
 */
final class WooCommerceProductDetails implements ProductDetails {

	/**
	 * Stock statuses the dashboard shows.
	 */
	private const STOCK_STATUSES = array( 'instock', 'outofstock', 'onbackorder' );

	/**
	 * The product's summary.
	 *
	 * @param int $product_id Product id.
	 */
	public function find( int $product_id ): ?ProductSummary {
		$product = $product_id > 0 ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof WC_Product || $product->is_type( 'variation' ) ) {
			return null;
		}

		$status = $product->get_status( 'edit' );

		if ( ! in_array( $status, array( 'publish', 'private' ), true ) ) {
			return null;
		}

		$stock = $product->get_stock_status();

		return new ProductSummary(
			$product->get_id(),
			self::plain_text( $product->get_name() ),
			$status,
			self::price( $product ),
			in_array( $stock, self::STOCK_STATUSES, true ) ? $stock : null,
			self::image_url( $product )
		);
	}

	/**
	 * The active price range, or null without an active price.
	 *
	 * @param WC_Product $product Product.
	 * @return array{min: string, max: string, currency: string, tax: string}|null
	 */
	private static function price( WC_Product $product ): ?array {
		$candidates = $product instanceof WC_Product_Variable
			? ( $product->get_variation_prices()['price'] ?? array() )
			: array( $product->get_price() );
		$amounts    = array();

		foreach ( $candidates as $candidate ) {
			if ( is_numeric( $candidate ) ) {
				$amounts[] = wc_format_decimal( $candidate );
			}
		}

		if ( array() === $amounts ) {
			return null;
		}

		usort( $amounts, static fn( string $a, string $b ): int => (float) $a <=> (float) $b );

		return array(
			'min'      => $amounts[0],
			'max'      => $amounts[ count( $amounts ) - 1 ],
			'currency' => get_woocommerce_currency(),
			'tax'      => 'store_setting',
		);
	}

	/**
	 * The same-origin thumbnail URL, or null.
	 *
	 * @param WC_Product $product Product.
	 */
	private static function image_url( WC_Product $product ): ?string {
		$image_id = $product->get_image_id();

		if ( ! is_numeric( $image_id ) || (int) $image_id < 1 ) {
			return null;
		}

		$url = wp_get_attachment_image_url( (int) $image_id, 'thumbnail' );

		return is_string( $url ) && self::same_origin( $url, home_url() ) ? $url : null;
	}

	/**
	 * Whether an absolute http(s) URL without credentials has the origin of
	 * $home (scheme, host and port, with the scheme's default port).
	 *
	 * @param string $url  Candidate URL.
	 * @param string $home Site URL.
	 */
	private static function same_origin( string $url, string $home ): bool {
		$candidate = wp_parse_url( $url );
		$site      = wp_parse_url( $home );

		if ( ! is_array( $candidate ) || ! is_array( $site ) || isset( $candidate['user'] ) || isset( $candidate['pass'] ) ) {
			return false;
		}

		$origin = self::origin( $candidate );

		return null !== $origin && self::origin( $site ) === $origin;
	}

	/**
	 * `scheme://host:port` of parsed http(s) URL parts, or null.
	 *
	 * @param array<string, int|string> $parts Parts from wp_parse_url().
	 */
	private static function origin( array $parts ): ?string {
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return null;
		}

		$port = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );

		return $scheme . '://' . $host . ':' . $port;
	}

	/**
	 * Plain text: no tags, entities decoded, trimmed.
	 *
	 * @param string $value Value.
	 */
	private static function plain_text( string $value ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
