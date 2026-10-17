<?php
/**
 * VAT on the commission of a settlement document (ADR-062 K05, K12).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Settlements;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use OverflowException;
use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/**
 * The project owner's business model (ADR-062 pkt. 2): the agreed commission
 * includes VAT where VAT is calculated, and no VAT is calculated on the
 * commission of a creator who is not VAT registered. Whether a settled row's
 * commission carries VAT follows the creator's VAT status at the time of the
 * sale stored on the row (ADR-062 pkt. 3), never the status at issue or now.
 *
 * The VAT is rate / (100 % + rate) of the VAT-carrying commission, rounded
 * half away from zero to the store precision of those rows, once per
 * document; the commission excl. VAT is the rest, so excl. + VAT = incl.
 * exactly. At 25 %, 1,000.00 incl. VAT is 800.00 excl. VAT and 200.00 VAT.
 * Adjustments (ADR-063) carry no VAT: they regulate rows of creators who were
 * not VAT registered. The commission and the balance are unchanged; this only
 * splits the commission on the document. VAT on the products sold (the rows'
 * tax) is separate.
 *
 * Issued from template version 3 with the split in the snapshot
 * (`commission_vat`), so a later change of the creator's VAT status or of the
 * rate never changes an issued document. Not validated by an auditor or the
 * Danish Tax Agency (ADR-062, reservations).
 */
final class CommissionVat {

	/**
	 * The Danish standard VAT rate in basis points, stored with every document.
	 */
	public const RATE_BPS = 2500;

	/**
	 * Version of this calculation in the snapshot.
	 */
	public const VERSION = 1;

	/**
	 * Snapshot key of the split.
	 */
	public const SNAPSHOT_KEY = 'commission_vat';

	/**
	 * The first template version whose documents carry the split.
	 */
	public const TEMPLATE = 3;

	/**
	 * The split of the commission of settled rows.
	 *
	 * @phpstan-param list<array{payout_units: int, vat_registered: bool, price_decimals: int}> $rows
	 *
	 * @param array[] $rows     Settled rows: payout (commission), VAT status at the sale, store precision.
	 * @param int     $rate_bps VAT rate in basis points, 1–10 000.
	 * @return array{rate_bps: int, incl_units: int, vat_units: int, excl_units: int, untaxed_units: int}
	 * @throws InvalidArgumentException When the rate or a row's precision is out of range.
	 * @throws OverflowException When an amount does not fit into an integer.
	 */
	public static function split( array $rows, int $rate_bps = self::RATE_BPS ): array {
		if ( $rate_bps < 1 || $rate_bps > PricingMath::BPS_PER_WHOLE ) {
			throw new InvalidArgumentException( 'The VAT rate must be between 1 and 10000 basis points.' );
		}

		$incl     = 0;
		$untaxed  = 0;
		$decimals = null;

		foreach ( $rows as $row ) {
			PricingMath::step( $row['price_decimals'] );

			if ( $row['vat_registered'] ) {
				$incl     = SalesAmount::add( $incl, $row['payout_units'] );
				$decimals = max( $decimals ?? 0, $row['price_decimals'] );
			} else {
				$untaxed = SalesAmount::add( $untaxed, $row['payout_units'] );
			}
		}

		$vat = 0;

		if ( null !== $decimals && 0 !== $incl ) {
			$step  = PricingMath::step( $decimals );
			$steps = PricingMath::mul_div_round( $incl, $rate_bps, ( PricingMath::BPS_PER_WHOLE + $rate_bps ) * $step );

			if ( abs( $steps ) > intdiv( PHP_INT_MAX, $step ) ) {
				throw new OverflowException( 'Amount out of range.' );
			}

			$vat = $steps * $step;
		}

		return array(
			'rate_bps'      => $rate_bps,
			'incl_units'    => $incl,
			'vat_units'     => $vat,
			'excl_units'    => SalesAmount::add( $incl, SalesAmount::negate( $vat ) ),
			'untaxed_units' => $untaxed,
		);
	}

	/**
	 * The split of settled rows as stored (`merchboard_settlement_document_rows`).
	 *
	 * @param list<array<string, mixed>> $rows     Stored rows.
	 * @param int                        $rate_bps VAT rate in basis points.
	 * @return array{rate_bps: int, incl_units: int, vat_units: int, excl_units: int, untaxed_units: int}
	 * @throws UnexpectedValueException When a row or the rate is invalid.
	 */
	public static function of_stored_rows( array $rows, int $rate_bps ): array {
		$parsed = array();

		try {
			foreach ( $rows as $row ) {
				$vat      = $row['vat_registered'] ?? null;
				$decimals = $row['price_decimals'] ?? null;
				$payout   = $row['payout_amount'] ?? null;

				if ( ! in_array( $vat, array( true, false, 1, 0, '1', '0' ), true ) || ! ( is_int( $decimals ) || ( is_string( $decimals ) && 1 === preg_match( '/^\d$/', $decimals ) ) ) || ! is_string( $payout ) ) {
					throw new UnexpectedValueException( 'A settled row has no valid VAT status, precision or payout.' );
				}

				$parsed[] = array(
					'payout_units'   => SalesAmount::parse( $payout ),
					'vat_registered' => in_array( $vat, array( true, 1, '1' ), true ),
					'price_decimals' => (int) $decimals,
				);
			}

			return self::split( $parsed, $rate_bps );
		} catch ( InvalidArgumentException | OverflowException ) {
			throw new UnexpectedValueException( 'The VAT on the commission cannot be calculated from the settled rows.' );
		}
	}

	/**
	 * The split as stored in a document's snapshot.
	 *
	 * @param array{rate_bps: int, incl_units: int, vat_units: int, excl_units: int, untaxed_units: int} $split Split.
	 * @return array{version: int, rate_bps: int, incl: string, vat: string, excl: string, untaxed: string}
	 */
	public static function snapshot( array $split ): array {
		return array(
			'version'  => self::VERSION,
			'rate_bps' => $split['rate_bps'],
			'incl'     => SalesAmount::format( $split['incl_units'] ),
			'vat'      => SalesAmount::format( $split['vat_units'] ),
			'excl'     => SalesAmount::format( $split['excl_units'] ),
			'untaxed'  => SalesAmount::format( $split['untaxed_units'] ),
		);
	}

	/**
	 * The split stored with a document, or null when it has none (template
	 * version below 3, or not a settlement document).
	 *
	 * @param SettlementDocument $document Document.
	 * @return array{rate_bps: int, incl_units: int, vat_units: int, excl_units: int, untaxed_units: int}|null
	 * @throws UnexpectedValueException When a document of template version 3 or later has no valid split.
	 */
	public static function of_document( SettlementDocument $document ): ?array {
		if ( SettlementDocument::TYPE_SETTLEMENT !== $document->type || $document->template_version < self::TEMPLATE ) {
			return null;
		}

		$stored = $document->snapshot[ self::SNAPSHOT_KEY ] ?? null;

		if ( ! is_array( $stored ) || self::VERSION !== ( $stored['version'] ?? null ) || ! is_int( $stored['rate_bps'] ?? null ) ) {
			throw new UnexpectedValueException( 'The settlement document has no valid VAT on the commission.' );
		}

		try {
			$split = array(
				'rate_bps'      => $stored['rate_bps'],
				'incl_units'    => SalesAmount::parse( self::decimal( $stored['incl'] ?? null ) ),
				'vat_units'     => SalesAmount::parse( self::decimal( $stored['vat'] ?? null ) ),
				'excl_units'    => SalesAmount::parse( self::decimal( $stored['excl'] ?? null ) ),
				'untaxed_units' => SalesAmount::parse( self::decimal( $stored['untaxed'] ?? null ) ),
			);
		} catch ( InvalidArgumentException ) {
			throw new UnexpectedValueException( 'The settlement document has no valid VAT on the commission.' );
		}

		if ( $split['rate_bps'] < 1 || $split['rate_bps'] > PricingMath::BPS_PER_WHOLE
			|| SalesAmount::add( $split['excl_units'], $split['vat_units'] ) !== $split['incl_units']
			|| SalesAmount::add( $split['incl_units'], $split['untaxed_units'] ) !== $document->commission_units ) {
			throw new UnexpectedValueException( 'The VAT on the commission of the settlement document does not add up.' );
		}

		return $split;
	}

	/**
	 * The split to show on a document: only when VAT was calculated on part of
	 * the commission (K12: nothing that could be read as VAT on the
	 * commission of a creator who is not VAT registered). Null otherwise, also
	 * for a document whose stored split is invalid (the PDF refuses those).
	 *
	 * @param SettlementDocument $document Document.
	 * @return array{rate_bps: int, incl_units: int, vat_units: int, excl_units: int, untaxed_units: int}|null
	 */
	public static function shown( SettlementDocument $document ): ?array {
		try {
			$split = self::of_document( $document );
		} catch ( UnexpectedValueException ) {
			return null;
		}

		return null === $split || 0 === $split['incl_units'] ? null : $split;
	}

	/**
	 * A rate in basis points as a percentage without trailing zeros, e.g.
	 * 2500 → "25", 2450 → "24,5" with a decimal comma.
	 *
	 * @param int    $rate_bps      Rate in basis points.
	 * @param string $decimal_point Decimal point of the locale.
	 */
	public static function percent( int $rate_bps, string $decimal_point ): string {
		$fraction = rtrim( str_pad( (string) ( $rate_bps % 100 ), 2, '0', STR_PAD_LEFT ), '0' );

		return (string) intdiv( $rate_bps, 100 ) . ( '' === $fraction ? '' : $decimal_point . $fraction );
	}

	/**
	 * A rate as a percentage with the decimal point of the site's locale.
	 *
	 * @param int $rate_bps Rate in basis points.
	 */
	public static function percent_for_locale( int $rate_bps ): string {
		global $wp_locale;

		$format = is_object( $wp_locale ) && is_array( $wp_locale->number_format ?? null ) ? $wp_locale->number_format : array();
		$point  = is_string( $format['decimal_point'] ?? null ) ? html_entity_decode( $format['decimal_point'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '.';

		return self::percent( $rate_bps, $point );
	}

	/**
	 * A stored decimal amount.
	 *
	 * @param mixed $value Value.
	 * @throws InvalidArgumentException When it is not a string.
	 */
	private static function decimal( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Invalid amount.' );
		}

		return $value;
	}
}
