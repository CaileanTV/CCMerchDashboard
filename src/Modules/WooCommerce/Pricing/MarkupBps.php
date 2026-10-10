<?php
/**
 * A creator share (markup) in basis points.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's share of the WooCommerce sale price: 0–10 000 basis
 * points (0–100 %, 1 bp = 0.01 %), never a float (ADR-049).
 * New and existing creators start at 0.
 */
final class MarkupBps {

	/**
	 * Constructor.
	 *
	 * @param int $bps Basis points.
	 * @throws InvalidArgumentException When outside 0–10 000.
	 */
	public function __construct( public readonly int $bps ) {
		if ( $bps < 0 || $bps > PricingMath::BPS_PER_WHOLE ) {
			throw new InvalidArgumentException( 'The markup must be between 0 and 10000 basis points.' );
		}
	}

	/**
	 * Parses a percentage with at most two decimals, e.g. "20", "17.5" or
	 * "0.01". No floats.
	 *
	 * @param string $percent Percentage.
	 * @throws InvalidArgumentException When it is not a plain percentage in range.
	 */
	public static function from_percent( string $percent ): self {
		if ( 1 !== preg_match( '/^(\d{1,3})(?:\.(\d{1,2}))?$/', trim( $percent ), $parts ) ) {
			throw new InvalidArgumentException( 'Invalid percentage.' );
		}

		return new self( (int) $parts[1] * 100 + (int) str_pad( $parts[2] ?? '', 2, '0' ) );
	}

	/**
	 * The percentage with two decimals, e.g. "20.00" (API format).
	 */
	public function percent(): string {
		return intdiv( $this->bps, 100 ) . '.' . str_pad( (string) ( $this->bps % 100 ), 2, '0', STR_PAD_LEFT );
	}
}
