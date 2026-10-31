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
 * "Creator Base product Addition". The version stores the whole name.
 */
final class DesignerNames {

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
	 * A whole name.
	 *
	 * @param string $prefix  The fixed part.
	 * @param string $postfix The creator's addition.
	 */
	public static function full( string $prefix, string $postfix ): string {
		return trim( $prefix . ' ' . $postfix );
	}

	/**
	 * The creator's addition in a stored name. A name from before the rule
	 * is taken whole, unless it is the base product's own.
	 *
	 * @param string $title  The stored name.
	 * @param string $prefix The fixed part.
	 * @param string $base   The base product's name.
	 */
	public static function postfix( string $title, string $prefix, string $base ): string {
		if ( $title === $prefix || $title === $base ) {
			return '';
		}

		return str_starts_with( $title, $prefix . ' ' ) ? trim( substr( $title, strlen( $prefix ) ) ) : $title;
	}
}
