<?php
/**
 * Creator social links.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

defined( 'ABSPATH' ) || exit;

/**
 * The fixed set of social platforms on a creator profile. Each value is an
 * https URL of at most MAX_LENGTH characters, or '' when not set.
 */
final class SocialLinks {

	/**
	 * Platforms, in display order.
	 */
	public const PLATFORMS = array( 'website', 'instagram', 'tiktok', 'youtube', 'x', 'facebook' );

	/**
	 * Maximum URL length.
	 */
	public const MAX_LENGTH = 200;

	/**
	 * All platforms with empty values.
	 *
	 * @return array<string, string>
	 */
	public static function empty(): array {
		return array_fill_keys( self::PLATFORMS, '' );
	}

	/**
	 * Stored links with every platform present (unknown keys and non-strings
	 * dropped, so corrupt meta never produces unexpected values).
	 *
	 * @param mixed $stored Stored value.
	 * @return array<string, string>
	 */
	public static function from_stored( mixed $stored ): array {
		$links = self::empty();

		if ( ! is_array( $stored ) ) {
			return $links;
		}

		foreach ( self::PLATFORMS as $platform ) {
			$value = $stored[ $platform ] ?? '';

			if ( is_string( $value ) && ( '' === $value || null !== self::normalize_url( $value ) ) ) {
				$links[ $platform ] = $value;
			}
		}

		return $links;
	}

	/**
	 * A valid https URL (trimmed), '' for an empty value, or null when invalid:
	 * not https, no host, credentials, whitespace or control characters, or
	 * too long.
	 *
	 * @param string $url Candidate URL.
	 */
	public static function normalize_url( string $url ): ?string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		if ( mb_strlen( $url ) > self::MAX_LENGTH || 1 === preg_match( '/[\s\x00-\x1F\x7F<>"\'\\\\]/', $url ) ) {
			return null;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || '' === (string) ( $parts['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		return $url;
	}
}
