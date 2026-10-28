<?php
/**
 * Admin labels of the pricing domain.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Modules\WooCommerce\Pricing\MarkupSource;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

defined( 'ABSPATH' ) || exit;

/**
 * Translated plain-text labels for creator pricing (share sources,
 * percentages) and amounts in the store's precision. Callers escape the
 * output.
 */
final class PricingLabels {

	/**
	 * Label of where a share comes from.
	 *
	 * @param MarkupSource $source Source.
	 */
	public static function source( MarkupSource $source ): string {
		return match ( $source ) {
			MarkupSource::Product  => __( 'Product markup', 'merchboard' ),
			MarkupSource::Creator  => __( 'Creator markup', 'merchboard' ),
			MarkupSource::Designer => __( 'Designer product', 'merchboard' ),
		};
	}

	/**
	 * An amount with currency, e.g. "142.80 DKK"; "—" for none. Amounts that
	 * do not fit the store precision are shown with four decimals.
	 *
	 * @param int|null $units    Amount in units.
	 * @param string   $currency Currency.
	 * @param int      $decimals Store price decimals.
	 */
	public static function money( ?int $units, string $currency, int $decimals ): string {
		if ( null === $units ) {
			return '—';
		}

		/* translators: 1: amount, 2: currency code. */
		return sprintf( __( '%1$s %2$s', 'merchboard' ), self::amount( $units, $decimals ), $currency );
	}

	/**
	 * An amount without currency in the store precision, e.g. "142.80";
	 * amounts that do not fit it are shown with four decimals.
	 *
	 * @param int $units    Amount in units.
	 * @param int $decimals Store price decimals.
	 */
	public static function amount( int $units, int $decimals ): string {
		return $units >= 0 && $decimals >= 0 && $decimals <= PricingMath::DECIMALS && PricingMath::fits_precision( $units, $decimals )
			? PricingMath::price_string( $units, $decimals )
			: SalesAmount::format( $units );
	}

	/**
	 * A markup in basis points as a percentage, e.g. "19.00 %".
	 *
	 * @param int|null $bps Basis points.
	 */
	public static function markup( ?int $bps ): string {
		if ( null === $bps ) {
			return '—';
		}

		/* translators: %s: percentage with two decimals. */
		return sprintf( __( '%s %%', 'merchboard' ), intdiv( $bps, 100 ) . '.' . str_pad( (string) ( $bps % 100 ), 2, '0', STR_PAD_LEFT ) );
	}
}
