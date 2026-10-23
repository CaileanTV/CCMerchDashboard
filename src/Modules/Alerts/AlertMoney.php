<?php
/**
 * Amounts in alert texts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use OverflowException;

defined( 'ABSPATH' ) || exit;

/**
 * An amount as plain text for the overlay and the chat (`$Price`,
 * `$LineTotal`, `$Total`; the project owner's choice 2026-10-23): the shop's
 * decimals, separators, currency symbol and its position, e.g.
 * "249,00 kr." in a Danish shop. No HTML. Integer arithmetic only (amounts
 * are four-decimal strings, SalesAmount), rounded half away from zero.
 * Without WooCommerce: "249.00 DKK". An unknown amount gives ''.
 */
final class AlertMoney {

	/**
	 * An amount as text.
	 *
	 * @param string|null $amount   Four-decimal amount, null when unknown.
	 * @param string      $currency Currency code ('' for the shop's).
	 */
	public static function format( ?string $amount, string $currency ): string {
		if ( null === $amount ) {
			return '';
		}

		try {
			$units = SalesAmount::parse( $amount );
		} catch ( InvalidArgumentException | OverflowException ) {
			return '';
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? max( 0, min( 4, (int) wc_get_price_decimals() ) ) : 2;
		$point    = function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : '.';
		$thousand = function_exists( 'wc_get_price_thousand_separator' ) ? (string) wc_get_price_thousand_separator() : ',';
		$divisor  = 10 ** ( SalesAmount::DECIMALS - $decimals );
		$rounded  = intdiv( abs( $units ) + intdiv( $divisor, 2 ), $divisor );
		$scale    = 10 ** $decimals;
		$whole    = number_format( intdiv( $rounded, $scale ), 0, '', $thousand );
		$number   = ( $units < 0 && 0 !== $rounded ? '-' : '' ) . $whole . ( $decimals > 0 ? $point . str_pad( (string) ( $rounded % $scale ), $decimals, '0', STR_PAD_LEFT ) : '' );

		return self::with_currency( $number, '' === $currency && function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : $currency );
	}

	/**
	 * An amount for machines (the alert API, ADR-073): the shop's decimals
	 * with a point and no separators or currency, e.g. "249.00"; null when
	 * unknown.
	 *
	 * @param string|null $amount Four-decimal amount, null when unknown.
	 */
	public static function plain( ?string $amount ): ?string {
		if ( null === $amount ) {
			return null;
		}

		try {
			$units = SalesAmount::parse( $amount );
		} catch ( InvalidArgumentException | OverflowException ) {
			return null;
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? max( 0, min( 4, (int) wc_get_price_decimals() ) ) : 2;
		$divisor  = 10 ** ( SalesAmount::DECIMALS - $decimals );
		$rounded  = intdiv( abs( $units ) + intdiv( $divisor, 2 ), $divisor );
		$scale    = 10 ** $decimals;

		return ( $units < 0 && 0 !== $rounded ? '-' : '' ) . intdiv( $rounded, $scale ) . ( $decimals > 0 ? '.' . str_pad( (string) ( $rounded % $scale ), $decimals, '0', STR_PAD_LEFT ) : '' );
	}

	/**
	 * A per-item amount: the line divided by the quantity, rounded to four
	 * decimals; null when unknown.
	 *
	 * @param string|null $line_total Four-decimal line amount.
	 * @param int         $quantity   Quantity.
	 */
	public static function per_item( ?string $line_total, int $quantity ): ?string {
		if ( null === $line_total || $quantity < 1 ) {
			return null;
		}

		try {
			$units = SalesAmount::parse( $line_total );
		} catch ( InvalidArgumentException | OverflowException ) {
			return null;
		}

		$per = intdiv( abs( $units ) * 2 + $quantity, 2 * $quantity );

		return SalesAmount::format( $units < 0 ? -$per : $per );
	}

	/**
	 * A product's price with VAT as a four-decimal amount (test alerts);
	 * null without WooCommerce or a price.
	 *
	 * @param int $product_id Product.
	 */
	public static function product_price( int $product_id ): ?string {
		$product = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof \WC_Product || ! function_exists( 'wc_get_price_including_tax' ) ) {
			return null;
		}

		try {
			return SalesAmount::format( SalesAmount::parse( number_format( (float) wc_get_price_including_tax( $product ), SalesAmount::DECIMALS, '.', '' ) ) );
		} catch ( InvalidArgumentException | OverflowException ) {
			return null;
		}
	}

	/**
	 * The number with the currency symbol in the shop's position.
	 *
	 * @param string $number   Formatted number.
	 * @param string $currency Currency code.
	 */
	private static function with_currency( string $number, string $currency ): string {
		if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
			return trim( $number . ' ' . $currency );
		}

		$symbol = html_entity_decode( (string) get_woocommerce_currency_symbol( $currency ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return match ( (string) get_option( 'woocommerce_currency_pos', 'left' ) ) {
			'right'       => $number . $symbol,
			'left_space'  => $symbol . ' ' . $number,
			'right_space' => $number . ' ' . $symbol,
			default       => $symbol . $number,
		};
	}
}
