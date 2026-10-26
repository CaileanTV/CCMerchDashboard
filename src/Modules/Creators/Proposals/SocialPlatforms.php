<?php
/**
 * Social platforms of a creator's category.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Proposals;

defined( 'ABSPATH' ) || exit;

/**
 * The seven platforms the banner plugin shows on the category page (term
 * meta `ccm_social_{platform}`, ADR-077), each limited to its own domains
 * (and their subdomains, e.g. `www.` and `m.`). A value is an https URL of
 * at most MAX_LENGTH characters; a user name ("navn" or "@navn") becomes the
 * profile URL, as in the banner plugin.
 */
final class SocialPlatforms {

	/**
	 * Platforms, in display order (the banner plugin's).
	 */
	public const PLATFORMS = array( 'instagram', 'tiktok', 'youtube', 'x', 'facebook', 'kick', 'twitch' );

	/**
	 * Maximum URL length.
	 */
	public const MAX_LENGTH = 200;

	/**
	 * Allowed domains per platform (owner's decision 2026-10-26).
	 */
	public const DOMAINS = array(
		'instagram' => array( 'instagram.com' ),
		'tiktok'    => array( 'tiktok.com' ),
		'youtube'   => array( 'youtube.com', 'youtu.be' ),
		'x'         => array( 'x.com', 'twitter.com' ),
		'facebook'  => array( 'facebook.com', 'fb.com' ),
		'kick'      => array( 'kick.com' ),
		'twitch'    => array( 'twitch.tv' ),
	);

	/**
	 * The profile URL of a user name, per platform.
	 */
	private const PROFILES = array(
		'instagram' => 'https://www.instagram.com/%s',
		'tiktok'    => 'https://www.tiktok.com/@%s',
		'youtube'   => 'https://www.youtube.com/@%s',
		'x'         => 'https://x.com/%s',
		'facebook'  => 'https://www.facebook.com/%s',
		'kick'      => 'https://kick.com/%s',
		'twitch'    => 'https://www.twitch.tv/%s',
	);

	/**
	 * The link for a platform: '' for an empty value, the https URL (a user
	 * name becomes the profile URL, a missing scheme becomes https), or null
	 * when it is invalid or on another domain.
	 *
	 * @param string $platform One of PLATFORMS.
	 * @param string $value    Input.
	 */
	public static function normalize( string $platform, string $value ): ?string {
		$value = trim( $value );

		if ( ! isset( self::DOMAINS[ $platform ] ) ) {
			return null;
		}

		if ( '' === $value ) {
			return '';
		}

		if ( 1 !== preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ) {
			if ( ! str_starts_with( $value, '@' ) && ( str_contains( $value, '/' ) || self::names_domain( $platform, $value ) ) ) {
				// An address without scheme, e.g. a domain followed by a path.
				$value = 'https://' . $value;
			} elseif ( 1 === preg_match( '/^@?([A-Za-z0-9._-]{1,60})$/', $value, $handle ) ) {
				$value = sprintf( self::PROFILES[ $platform ], $handle[1] );
			} else {
				return null;
			}
		}

		return self::on_domain( $platform, $value ) ? $value : null;
	}

	/**
	 * Whether a value without scheme starts with one of the platform's
	 * domains (so it is an address, not a user name).
	 *
	 * @param string $platform One of PLATFORMS.
	 * @param string $value    Input without scheme.
	 */
	private static function names_domain( string $platform, string $value ): bool {
		$host = strtolower( $value );

		foreach ( self::DOMAINS[ $platform ] as $domain ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a value is a valid https URL on the platform's domains.
	 *
	 * @param string $platform One of PLATFORMS.
	 * @param string $url      URL.
	 */
	public static function on_domain( string $platform, string $url ): bool {
		if ( ! isset( self::DOMAINS[ $platform ] ) || mb_strlen( $url ) > self::MAX_LENGTH || 1 === preg_match( '/[\s\x00-\x1F\x7F<>"\'\\\\]/', $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
			return false;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		foreach ( self::DOMAINS[ $platform ] as $domain ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return true;
			}
		}

		return false;
	}
}
