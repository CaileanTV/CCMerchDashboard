<?php
/**
 * New creator discount codes.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

defined( 'ABSPATH' ) || exit;

/**
 * A new code for a creator (ADR-078, owner's choice 2026-10-26): the
 * creator's name in capitals without accents (A–Z and 0–9, at most
 * NAME_LENGTH characters; "CREATOR" when nothing is left), a dash and
 * SUFFIX_LENGTH random characters without look-alikes, e.g. `NOVA-7K3Q`.
 * Recognisable for the creator, hard to guess.
 */
final class CreatorCodeGenerator {

	public const NAME_LENGTH = 10;

	public const SUFFIX_LENGTH = 4;

	/**
	 * Characters of the random part (no 0/O, 1/I/L).
	 */
	public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

	/**
	 * A new code for a creator's name.
	 *
	 * @param string $display_name The creator's name.
	 */
	public static function candidate( string $display_name ): string {
		$name   = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]+/', '', remove_accents( $display_name ) ) );
		$name   = '' === $name ? 'CREATOR' : substr( $name, 0, self::NAME_LENGTH );
		$suffix = '';

		for ( $i = 0; $i < self::SUFFIX_LENGTH; $i++ ) {
			$suffix .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
		}

		return $name . '-' . $suffix;
	}
}
