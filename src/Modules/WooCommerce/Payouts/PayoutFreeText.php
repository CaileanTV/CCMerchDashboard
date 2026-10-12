<?php
/**
 * Payment details in the free text of a payout decision.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

defined( 'ABSPATH' ) || exit;

/**
 * Recognises payment details that must not be stored in the free text an
 * admin writes on a payout (the completion reference and the rejection
 * reason): the kept payout history must never hold a full account number or
 * e-mail address (ADR-059). Targeted, so ordinary references and reasons
 * pass:
 *
 * - any e-mail address (PayPal accounts are e-mail addresses);
 * - an IBAN with its country's length and a valid check sum (ISO 13616,
 *   mod 97), as one token or in groups of four separated by spaces, dots
 *   or hyphens, also with text right after it, so other letter-digit
 *   references are not mistaken for one (a reference that happens to start
 *   with a country code and have that length and a valid check sum is
 *   refused: about 1 in 97 of such candidates);
 * - the payout's own bank account number when its details can be read: the
 *   number as whole digit runs in the text, also with spaces, dots or
 *   hyphens between them, with or without its leading zeros, alone or after
 *   the payout's own registration number (no other digits glued in front),
 *   also next to a date or another number.
 *   Account numbers with fewer than six significant digits are not checked:
 *   they cannot be told apart from amounts, dates or order numbers.
 *
 * A heuristic against details entered by mistake, not a guarantee: other
 * people's account numbers, an IBAN in other groupings or with letters
 * glued to it, and details deliberately rewritten (words between the
 * digits, other separators) are not found.
 *
 * It answers only yes or no and never returns or logs the text.
 */
final class PayoutFreeText {

	/**
	 * Fewest significant digits of an account number that is checked.
	 */
	public const MIN_ACCOUNT_DIGITS = 6;

	/**
	 * Most digits of an account number (PayoutDestination::bank()): with the
	 * registration number, the longest stretch that can be the account.
	 */
	private const MAX_ACCOUNT_DIGITS = 10;

	/**
	 * Separator between digit runs or IBAN groups: up to three spaces, dots
	 * or hyphens (e.g. "12.345.678", "DK50 0040", "1234 - 5678").
	 */
	private const SEPARATOR = '[ .\-]{1,3}';

	/**
	 * IBAN length per country (SWIFT IBAN registry, ISO 13616). Only one
	 * length per country is a candidate, so fewer references are mistaken for
	 * an IBAN; a country missing here is not recognised.
	 */
	private const IBAN_LENGTHS = array(
		'AD' => 24,
		'AE' => 23,
		'AL' => 28,
		'AT' => 20,
		'AZ' => 28,
		'BA' => 20,
		'BE' => 16,
		'BG' => 22,
		'BH' => 22,
		'BI' => 27,
		'BR' => 29,
		'BY' => 28,
		'CH' => 21,
		'CR' => 22,
		'CY' => 28,
		'CZ' => 24,
		'DE' => 22,
		'DJ' => 27,
		'DK' => 18,
		'DO' => 28,
		'EE' => 20,
		'EG' => 29,
		'ES' => 24,
		'FI' => 18,
		'FK' => 18,
		'FO' => 18,
		'FR' => 27,
		'GB' => 22,
		'GE' => 22,
		'GI' => 23,
		'GL' => 18,
		'GR' => 27,
		'GT' => 28,
		'HN' => 28,
		'HR' => 21,
		'HU' => 28,
		'IE' => 22,
		'IL' => 23,
		'IQ' => 23,
		'IS' => 26,
		'IT' => 27,
		'JO' => 30,
		'KW' => 30,
		'KZ' => 20,
		'LB' => 28,
		'LC' => 32,
		'LI' => 21,
		'LT' => 20,
		'LU' => 20,
		'LV' => 21,
		'LY' => 25,
		'MC' => 27,
		'MD' => 24,
		'ME' => 22,
		'MK' => 19,
		'MN' => 20,
		'MR' => 27,
		'MT' => 31,
		'MU' => 30,
		'NI' => 28,
		'NL' => 18,
		'NO' => 15,
		'OM' => 23,
		'PK' => 24,
		'PL' => 28,
		'PS' => 29,
		'PT' => 25,
		'QA' => 29,
		'RO' => 24,
		'RS' => 22,
		'RU' => 33,
		'SA' => 24,
		'SC' => 31,
		'SD' => 18,
		'SE' => 24,
		'SI' => 19,
		'SK' => 24,
		'SM' => 27,
		'SO' => 23,
		'ST' => 25,
		'SV' => 28,
		'TL' => 23,
		'TN' => 24,
		'TR' => 26,
		'UA' => 29,
		'VA' => 22,
		'VG' => 24,
		'XK' => 20,
		'YE' => 30,
	);

	/**
	 * Whether the text contains payment details.
	 *
	 * @param string                 $text        Reference or reason.
	 * @param PayoutDestination|null $destination The payout's own details, when readable.
	 */
	public static function contains_payment_details( string $text, ?PayoutDestination $destination ): bool {
		if ( 1 === preg_match( '/[^\s@<>()"\',;]+@[^\s@<>()"\',;]+\.[a-z]{2,}/i', $text ) ) {
			return true;
		}

		if ( self::contains_iban( $text ) ) {
			return true;
		}

		return null !== $destination && PayoutMethod::Bank === $destination->method && self::contains_account( $text, $destination->registration, $destination->account );
	}

	/**
	 * Whether the text contains an IBAN with a valid check sum.
	 *
	 * @param string $text Text.
	 */
	private static function contains_iban( string $text ): bool {
		// One token.
		if ( false !== preg_match_all( '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/i', $text, $tokens ) ) {
			foreach ( $tokens[0] as $token ) {
				if ( self::valid_iban( strtoupper( $token ) ) ) {
					return true;
				}
			}
		}

		// The printed form: groups of up to four after separators, from every word that can start an
		// IBAN (overlapping). Each group boundary is a candidate while the groups are full, so text
		// after the IBAN cannot hide it.
		if ( false === preg_match_all( '/(?=\b([A-Z]{2}\d{2}(?:' . self::SEPARATOR . '[A-Z0-9]{1,4}(?![A-Z0-9])){1,8}))/i', $text, $starts ) ) {
			return false;
		}

		foreach ( $starts[1] as $sequence ) {
			$groups = preg_split( '/' . self::SEPARATOR . '/', strtoupper( $sequence ) );

			if ( false === $groups ) {
				continue;
			}

			$iban = (string) array_shift( $groups );

			foreach ( $groups as $group ) {
				$iban .= $group;

				if ( strlen( $iban ) > 34 ) {
					break;
				}

				if ( strlen( $iban ) >= 15 && self::valid_iban( $iban ) ) {
					return true;
				}

				// Only the last group of an IBAN may be shorter than four.
				if ( 4 !== strlen( $group ) ) {
					break;
				}
			}
		}

		return false;
	}

	/**
	 * ISO 13616 check: the length of the country's IBAN, and the rearranged
	 * number, letters as 10–35, mod 97 is 1.
	 *
	 * @param string $iban IBAN without spaces, upper case.
	 */
	private static function valid_iban( string $iban ): bool {
		if ( 1 !== preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $iban ) || ( self::IBAN_LENGTHS[ substr( $iban, 0, 2 ) ] ?? 0 ) !== strlen( $iban ) ) {
			return false;
		}

		$remainder = 0;

		foreach ( str_split( substr( $iban, 4 ) . substr( $iban, 0, 4 ) ) as $char ) {
			$digits = ctype_digit( $char ) ? $char : (string) ( ord( $char ) - 55 );

			foreach ( str_split( $digits ) as $digit ) {
				$remainder = ( $remainder * 10 + (int) $digit ) % 97;
			}
		}

		return 1 === $remainder;
	}

	/**
	 * Whether the text contains the account number as a whole digit
	 * sequence (separators between digits ignored), with or without leading
	 * zeros, alone or after the payout's own registration number. Other
	 * digits before it are not the account (e.g. "4412345678" for account
	 * 12345678 with registration number 1234).
	 *
	 * @param string $text         Text.
	 * @param string $registration Registration number (4 digits, PayoutDestination::bank()).
	 * @param string $account      Account number (digits).
	 */
	private static function contains_account( string $text, string $registration, string $account ): bool {
		$significant = ltrim( $account, '0' );

		if ( strlen( $significant ) < self::MIN_ACCOUNT_DIGITS ) {
			return false;
		}

		// Digit runs joined by separators.
		if ( false === preg_match_all( '/\d+(?:' . self::SEPARATOR . '\d+)*/', $text, $matches ) ) {
			return false;
		}

		foreach ( $matches[0] as $sequence ) {
			$runs = preg_split( '/\D+/', $sequence );

			if ( false === $runs ) {
				continue;
			}

			// Every stretch of whole runs, so a date or number before or after the account cannot hide it.
			$count = count( $runs );

			for ( $first = 0; $first < $count; $first++ ) {
				$digits = '';

				for ( $last = $first; $last < $count; $last++ ) {
					$digits .= $runs[ $last ];

					if ( strlen( ltrim( $digits, '0' ) ) > strlen( $registration ) + self::MAX_ACCOUNT_DIGITS ) {
						break;
					}

					// The account alone, or after the payout's own registration number, with or without leading zeros.
					if ( ltrim( $digits, '0' ) === $significant
						|| ( '' !== $registration && str_starts_with( $digits, $registration ) && ltrim( substr( $digits, strlen( $registration ) ), '0' ) === $significant ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}
}
