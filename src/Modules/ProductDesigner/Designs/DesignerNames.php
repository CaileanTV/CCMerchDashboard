<?php
/**
 * The name of a designer product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Designs;

defined( 'ABSPATH' ) || exit;

/**
 * A designer product is named after its creator and base product, which the
 * creator cannot change, and an optional addition of their own, so they can
 * have several of the same base product (the owner's choice, 2026-10-31):
 * "Creator Base product - Addition", with " - " before an addition (the
 * owner's choice 2026-11-08, ADR-102; DesignerNameSeparator updates the
 * names from before). The version stores the whole name.
 */
final class DesignerNames {

	/**
	 * What stands between the fixed part and the creator's addition.
	 */
	public const SEPARATOR = ' - ';

	/**
	 * The part of a name the creator cannot change.
	 *
	 * @param string $creator The creator's name.
	 * @param string $base    The base product's name.
	 */
	public static function prefix( string $creator, string $base ): string {
		return trim( $creator . ' ' . $base );
	}

	/**
	 * A whole name: the fixed part, then " - " and the addition, if any.
	 *
	 * @param string $prefix  The fixed part.
	 * @param string $postfix The creator's addition.
	 */
	public static function full( string $prefix, string $postfix ): string {
		$postfix = self::addition( $postfix );

		return '' === $postfix ? trim( $prefix ) : trim( $prefix ) . self::SEPARATOR . $postfix;
	}

	/**
	 * The creator's addition in a stored name, with or without " - " before
	 * it. A name from before the rule is taken whole, unless it is the base
	 * product's own.
	 *
	 * @param string $title  The stored name.
	 * @param string $prefix The fixed part.
	 * @param string $base   The base product's name.
	 */
	public static function postfix( string $title, string $prefix, string $base ): string {
		if ( $title === $prefix || $title === $base ) {
			return '';
		}

		foreach ( array( $prefix . self::SEPARATOR, $prefix . ' ' ) as $start ) {
			if ( str_starts_with( $title, $start ) ) {
				return self::addition( substr( $title, strlen( $start ) ) );
			}
		}

		return $title;
	}

	/**
	 * An addition without the dashes and spaces it may start with, so a
	 * dash the creator typed is not doubled.
	 *
	 * @param string $postfix The addition.
	 */
	private static function addition( string $postfix ): string {
		return trim( (string) preg_replace( '/^[\s\-\x{2013}\x{2014}]+/u', '', trim( $postfix ) ) );
	}
}
