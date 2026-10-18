<?php
/**
 * The amounts of a correction document (rettelsesbilag).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Settlements;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Pricing\PricingMath;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use OverflowException;
use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/**
 * A correction document for a material error in an issued settlement
 * document (ADR-062 K10 and K15). It refers to the original settlement
 * document and to the document it corrects (the latest of the chain), and
 * states the amounts before and after the correction and the difference:
 * the commission in the period with its split into commission of sales with
 * VAT (incl. VAT, with excl. VAT and VAT) and commission of sales without VAT
 * (CommissionVat, ADR-062 K05). The amounts before are the ones in force: the
 * corrected amounts of the latest correction document with amounts, else
 * those of the original (for an original issued before template version 3,
 * the split its settled rows give). The VAT after the correction is
 * calculated from the corrected commission with VAT at the rate in force,
 * rounded once to the store precision of the original's rows. The corrected
 * amounts must stay within the VAT categories of the original's settled rows
 * (their VAT status at the sale, categories()): no VAT on the commission of
 * sales while the creator was not VAT registered, and none removed from
 * sales while registered. In a document with both, how the amounts are
 * divided between them is the administrator's responsibility (the input is
 * not per sale). A wrong historical VAT status is never corrected here.
 *
 * The correction only documents: the original, the completed payout, the
 * ledger, the balance and later documents never change, and the difference
 * is not settled automatically. Whether an error is material is the
 * administrator's assessment; nothing here decides it. Stored in the
 * snapshot of the correction document (`correction`, version 1) with
 * template version 5 and checked again before it is shown (fail closed).
 * Not validated by an auditor, the Danish Tax Agency or a lawyer (ADR-062,
 * reservations).
 */
final class SettlementCorrection {

	/**
	 * Version of the stored structure.
	 */
	public const VERSION = 1;

	/**
	 * Snapshot key.
	 */
	public const SNAPSHOT_KEY = 'correction';

	/**
	 * Template version of correction documents with amounts. Earlier
	 * correction documents (D11 a) carry no amounts.
	 */
	public const TEMPLATE = 5;

	/**
	 * Most correction documents of one chain (`-K1` … `-K100`): issued,
	 * followed and read up to this limit, never beyond.
	 */
	public const MAX_CHAIN = 100;

	/**
	 * The amounts of a correction, in display order.
	 */
	public const AMOUNTS = array( 'commission', 'excl', 'vat', 'incl', 'untaxed' );

	/**
	 * The amounts in force for a chain: those of its latest document with
	 * amounts (a correction document of template version 5, else the
	 * original settlement document).
	 *
	 * @phpstan-param list<SettlementDocument> $chain
	 *
	 * @param SettlementDocument[]       $chain The original first, then its corrections, oldest first.
	 * @param list<array<string, mixed>> $rows  The original's settled rows as stored.
	 * @return array{rate_bps: int, commission: int, excl: int, vat: int, incl: int, untaxed: int}
	 * @throws UnexpectedValueException When the chain or its amounts are invalid.
	 */
	public static function in_force( array $chain, array $rows ): array {
		$original = $chain[0] ?? null;

		if ( null === $original || SettlementDocument::TYPE_SETTLEMENT !== $original->type ) {
			throw new UnexpectedValueException( 'The chain does not start with a settlement document.' );
		}

		for ( $i = count( $chain ) - 1; $i > 0; $i-- ) {
			$stored = self::of_document( $chain[ $i ] );

			if ( null !== $stored ) {
				return array( 'rate_bps' => $stored['rate_bps'] ) + $stored['after'];
			}
		}

		$split = CommissionVat::of_document( $original ) ?? CommissionVat::of_stored_rows( $rows, CommissionVat::RATE_BPS );

		if ( SalesAmount::add( $split['incl_units'], $split['untaxed_units'] ) !== $original->commission_units ) {
			throw new UnexpectedValueException( 'The commission of the settlement document does not add up.' );
		}

		return array(
			'rate_bps'   => $split['rate_bps'],
			'commission' => $original->commission_units,
			'excl'       => $split['excl_units'],
			'vat'        => $split['vat_units'],
			'incl'       => $split['incl_units'],
			'untaxed'    => $split['untaxed_units'],
		);
	}

	/**
	 * The VAT categories of the original's settled rows: whether it settled
	 * sales while the creator was VAT registered (commission with VAT) and
	 * sales while not (commission without VAT), by the status stored on each
	 * row at the sale. A correction may only state amounts in these
	 * categories; the creator's current VAT status never decides them.
	 *
	 * @param list<array<string, mixed>> $rows The original's settled rows as stored.
	 * @return array{vat: bool, untaxed: bool}
	 * @throws UnexpectedValueException When a row has no valid VAT status.
	 */
	public static function categories( array $rows ): array {
		$categories = array(
			'vat'     => false,
			'untaxed' => false,
		);

		foreach ( $rows as $row ) {
			$status = $row['vat_registered'] ?? null;

			if ( in_array( $status, array( true, 1, '1' ), true ) ) {
				$categories['vat'] = true;
			} elseif ( in_array( $status, array( false, 0, '0' ), true ) ) {
				$categories['untaxed'] = true;
			} else {
				throw new UnexpectedValueException( 'A settled row has no valid VAT status.' );
			}
		}

		return $categories;
	}

	/**
	 * Whether amounts stay within the VAT categories of the original: no
	 * commission with VAT without sales while VAT registered, and no
	 * commission without VAT without sales while not.
	 *
	 * @param array{vat: bool, untaxed: bool} $categories Categories of the original (categories()).
	 * @param array<string, int>              $amounts    Amounts (`incl`, `untaxed`).
	 */
	public static function within( array $categories, array $amounts ): bool {
		return ( $categories['vat'] || 0 === $amounts['incl'] ) && ( $categories['untaxed'] || 0 === $amounts['untaxed'] );
	}

	/**
	 * The corrected amounts.
	 *
	 * @param int $rate_bps VAT rate in basis points (the rate in force).
	 * @param int $incl     Corrected commission of sales with VAT, incl. VAT.
	 * @param int $untaxed  Corrected commission of sales without VAT.
	 * @param int $decimals Store precision.
	 * @return array{commission: int, excl: int, vat: int, incl: int, untaxed: int}
	 * @throws UnexpectedValueException When the amounts are out of range.
	 */
	public static function corrected( int $rate_bps, int $incl, int $untaxed, int $decimals ): array {
		try {
			$split = CommissionVat::split(
				array(
					array(
						'payout_units'   => $incl,
						'vat_registered' => true,
						'price_decimals' => $decimals,
					),
					array(
						'payout_units'   => $untaxed,
						'vat_registered' => false,
						'price_decimals' => $decimals,
					),
				),
				$rate_bps
			);

			return array(
				'commission' => SalesAmount::add( $split['incl_units'], $split['untaxed_units'] ),
				'excl'       => $split['excl_units'],
				'vat'        => $split['vat_units'],
				'incl'       => $split['incl_units'],
				'untaxed'    => $split['untaxed_units'],
			);
		} catch ( InvalidArgumentException | OverflowException ) {
			throw new UnexpectedValueException( 'The corrected amounts are out of range.' );
		}
	}

	/**
	 * A corrected amount as entered: digits with an optional minus and up to
	 * four decimals after a point or comma, and no more decimals than the
	 * store precision.
	 *
	 * @param string $input    Amount as entered.
	 * @param int    $decimals Store precision.
	 * @return int Units.
	 * @throws PayoutException `invalid_correction_amount` otherwise.
	 */
	public static function amount( string $input, int $decimals ): int {
		$input = trim( $input );

		if ( 1 !== preg_match( '/^-?\d{1,12}(?:[.,]\d{1,4})?$/', $input ) ) {
			throw new PayoutException( PayoutException::INVALID_CORRECTION_AMOUNT );
		}

		try {
			$units = SalesAmount::parse( str_replace( ',', '.', $input ) );

			if ( 0 !== $units % PricingMath::step( $decimals ) ) {
				throw new PayoutException( PayoutException::INVALID_CORRECTION_AMOUNT );
			}
		} catch ( InvalidArgumentException | OverflowException ) {
			throw new PayoutException( PayoutException::INVALID_CORRECTION_AMOUNT );
		}

		return $units;
	}

	/**
	 * The store precision of a correction: the highest of the original's
	 * settled rows, else the store's (2 without WooCommerce).
	 *
	 * @param list<array<string, mixed>> $rows The original's settled rows as stored.
	 */
	public static function decimals( array $rows ): int {
		$decimals = null;

		foreach ( $rows as $row ) {
			$value = $row['price_decimals'] ?? null;

			if ( is_numeric( $value ) ) {
				$decimals = max( $decimals ?? 0, (int) $value );
			}
		}

		$decimals ??= function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		return max( 0, min( SalesAmount::DECIMALS, $decimals ) );
	}

	/**
	 * The structure stored in the snapshot.
	 *
	 * @param string                                                                              $original Number of the original settlement document.
	 * @param string                                                                              $corrects Number of the corrected document.
	 * @param int                                                                                 $sequence Number of the correction in the chain (1 = first).
	 * @param int                                                                                 $decimals Store precision.
	 * @param array{rate_bps: int, commission: int, excl: int, vat: int, incl: int, untaxed: int} $before   Amounts in force.
	 * @param array{commission: int, excl: int, vat: int, incl: int, untaxed: int}                $after    Corrected amounts.
	 * @param int                                                                                 $payout   Paid out with the original (unchanged).
	 * @return array<string, mixed>
	 */
	public static function snapshot( string $original, string $corrects, int $sequence, int $decimals, array $before, array $after, int $payout ): array {
		$format = static function ( array $amounts ): array {
			$result = array();

			foreach ( self::AMOUNTS as $key ) {
				$result[ $key ] = SalesAmount::format( $amounts[ $key ] );
			}

			return $result;
		};

		return array(
			'version'    => self::VERSION,
			'original'   => $original,
			'corrects'   => $corrects,
			'sequence'   => $sequence,
			'rate_bps'   => $before['rate_bps'],
			'decimals'   => $decimals,
			'before'     => $format( $before ),
			'after'      => $format( $after ),
			'difference' => $format( self::difference( $before, $after ) ),
			'payout'     => SalesAmount::format( $payout ),
		);
	}

	/**
	 * Whether the amounts before and after are the same.
	 *
	 * @param array<string, int> $before Amounts in force.
	 * @param array<string, int> $after  Corrected amounts.
	 */
	public static function unchanged( array $before, array $after ): bool {
		foreach ( self::AMOUNTS as $key ) {
			if ( $before[ $key ] !== $after[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether two snapshots identify the parties alike: the creator's legal
	 * name, address and CVR/SE number and the issuer's name, address and CVR
	 * number (ADR-062 K09). The creator's display name and current VAT status
	 * are left out on purpose: a change of them since the issue is no error
	 * of the document, so it never makes a correction without a difference
	 * of amounts valid on its own. Missing keys (documents before template
	 * version 4) count as not registered.
	 *
	 * @param array<string, mixed> $corrected Snapshot of the corrected document.
	 * @param array<string, mixed> $current   Snapshot of the parties now.
	 */
	public static function same_parties( array $corrected, array $current ): bool {
		return self::parties( $corrected ) === self::parties( $current );
	}

	/**
	 * The stored correction of a document, checked, or null when it has none
	 * (a settlement document, or a correction document without amounts).
	 *
	 * @param SettlementDocument $document Document.
	 * @return array{original: string, corrects: string, sequence: int, rate_bps: int, decimals: int, before: array{commission: int, excl: int, vat: int, incl: int, untaxed: int}, after: array{commission: int, excl: int, vat: int, incl: int, untaxed: int}, difference: array{commission: int, excl: int, vat: int, incl: int, untaxed: int}, payout: int}|null
	 * @throws UnexpectedValueException When a correction document of template version 5 has no valid correction.
	 */
	public static function of_document( SettlementDocument $document ): ?array {
		if ( SettlementDocument::TYPE_CORRECTION !== $document->type || $document->template_version < self::TEMPLATE ) {
			return null;
		}

		$stored = $document->snapshot[ self::SNAPSHOT_KEY ] ?? null;

		if ( ! is_array( $stored ) || self::VERSION !== ( $stored['version'] ?? null )
			|| ! is_string( $stored['original'] ?? null ) || '' === $stored['original']
			|| ! is_string( $stored['corrects'] ?? null ) || '' === $stored['corrects']
			|| ! is_int( $stored['sequence'] ?? null ) || $stored['sequence'] < 1 || $stored['sequence'] > self::MAX_CHAIN
			|| ! is_int( $stored['rate_bps'] ?? null ) || $stored['rate_bps'] < 1 || $stored['rate_bps'] > PricingMath::BPS_PER_WHOLE
			|| ! is_int( $stored['decimals'] ?? null ) || $stored['decimals'] < 0 || $stored['decimals'] > SalesAmount::DECIMALS
			|| $stored['original'] . '-K' . $stored['sequence'] !== $document->number ) {
			throw new UnexpectedValueException( 'The correction document has no valid correction.' );
		}

		try {
			$before     = self::units( $stored['before'] ?? null );
			$after      = self::units( $stored['after'] ?? null );
			$difference = self::units( $stored['difference'] ?? null );
			$payout     = SalesAmount::parse( is_string( $stored['payout'] ?? null ) ? $stored['payout'] : '' );
		} catch ( InvalidArgumentException | OverflowException ) {
			throw new UnexpectedValueException( 'The correction document has no valid correction.' );
		}

		foreach ( array( $before, $after ) as $amounts ) {
			if ( SalesAmount::add( $amounts['incl'], $amounts['untaxed'] ) !== $amounts['commission'] || SalesAmount::add( $amounts['excl'], $amounts['vat'] ) !== $amounts['incl'] ) {
				throw new UnexpectedValueException( 'The amounts of the correction document do not add up.' );
			}
		}

		if ( self::corrected( $stored['rate_bps'], $after['incl'], $after['untaxed'], $stored['decimals'] ) !== $after || self::difference( $before, $after ) !== $difference ) {
			throw new UnexpectedValueException( 'The amounts of the correction document do not add up.' );
		}

		return array(
			'original'   => $stored['original'],
			'corrects'   => $stored['corrects'],
			'sequence'   => $stored['sequence'],
			'rate_bps'   => $stored['rate_bps'],
			'decimals'   => $stored['decimals'],
			'before'     => $before,
			'after'      => $after,
			'difference' => $difference,
			'payout'     => $payout,
		);
	}

	/**
	 * The fields of a snapshot that identify the parties (same_parties()).
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @return array<string, mixed>
	 */
	private static function parties( array $snapshot ): array {
		$creator = is_array( $snapshot['creator'] ?? null ) ? $snapshot['creator'] : array();
		$company = is_array( $snapshot['company'] ?? null ) ? $snapshot['company'] : array();
		$text    = static fn( mixed $value ): string => is_scalar( $value ) ? (string) $value : '';
		$lines   = static fn( mixed $value ): array => is_array( $value ) ? array_values( array_map( $text, $value ) ) : array();

		return array(
			'creator_legal_name' => $text( $creator['legal_name'] ?? '' ),
			'creator_address'    => $lines( $creator['address'] ?? null ),
			'creator_cvr'        => $text( $creator['cvr'] ?? '' ),
			'company_name'       => $text( $company['name'] ?? '' ),
			'company_address'    => $lines( $company['address'] ?? null ),
			'company_cvr'        => $text( $company['cvr'] ?? '' ),
		);
	}

	/**
	 * After − before for every amount.
	 *
	 * @param array<string, int> $before Amounts in force.
	 * @param array<string, int> $after  Corrected amounts.
	 * @return array{commission: int, excl: int, vat: int, incl: int, untaxed: int}
	 */
	private static function difference( array $before, array $after ): array {
		return array(
			'commission' => SalesAmount::add( $after['commission'], SalesAmount::negate( $before['commission'] ) ),
			'excl'       => SalesAmount::add( $after['excl'], SalesAmount::negate( $before['excl'] ) ),
			'vat'        => SalesAmount::add( $after['vat'], SalesAmount::negate( $before['vat'] ) ),
			'incl'       => SalesAmount::add( $after['incl'], SalesAmount::negate( $before['incl'] ) ),
			'untaxed'    => SalesAmount::add( $after['untaxed'], SalesAmount::negate( $before['untaxed'] ) ),
		);
	}

	/**
	 * Stored amounts as units.
	 *
	 * @param mixed $stored Stored amounts.
	 * @return array{commission: int, excl: int, vat: int, incl: int, untaxed: int}
	 * @throws InvalidArgumentException When an amount is missing or invalid.
	 */
	private static function units( mixed $stored ): array {
		if ( ! is_array( $stored ) ) {
			throw new InvalidArgumentException( 'Invalid amounts.' );
		}

		$value = static function ( string $key ) use ( $stored ): int {
			$amount = $stored[ $key ] ?? null;

			if ( ! is_string( $amount ) ) {
				throw new InvalidArgumentException( 'Invalid amount.' );
			}

			return SalesAmount::parse( $amount );
		};

		return array(
			'commission' => $value( 'commission' ),
			'excl'       => $value( 'excl' ),
			'vat'        => $value( 'vat' ),
			'incl'       => $value( 'incl' ),
			'untaxed'    => $value( 'untaxed' ),
		);
	}
}
