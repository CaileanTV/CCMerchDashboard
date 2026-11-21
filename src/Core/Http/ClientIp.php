<?php
/**
 * The visitor's IP address for rate limits.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Http;

defined( 'ABSPATH' ) || exit;

/**
 * The address every rate limit counts by (ADR-116). The shop is behind
 * Cloudflare: without nginx' real_ip, REMOTE_ADDR is Cloudflare's, and every
 * visitor would share one limit.
 *
 * - `CF-Connecting-IP` is used only when the request came from Cloudflare,
 *   i.e. REMOTE_ADDR lies in Cloudflare's published ranges (filter
 *   `merchboard_trusted_proxy_ranges`). A request straight to the origin
 *   cannot set it.
 * - Otherwise REMOTE_ADDR, as the web server saw it. When nginx already
 *   rewrote it (real_ip), it is the visitor's and no longer Cloudflare's,
 *   so the header is not read.
 * - Only valid IPv4 or IPv6 addresses count; anything else is `unknown`.
 */
final class ClientIp {

	/**
	 * Cloudflare's ranges (https://www.cloudflare.com/ips/, 2026-11-21).
	 */
	public const CLOUDFLARE = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * The filter of the trusted proxy ranges.
	 */
	public const FILTER = 'merchboard_trusted_proxy_ranges';

	/**
	 * The visitor's address, or `unknown`.
	 *
	 * @param array<mixed>|null $server Server variables; null for $_SERVER.
	 */
	public static function address( ?array $server = null ): string {
		$server = $server ?? $_SERVER;
		$remote = self::valid( $server['REMOTE_ADDR'] ?? null );

		if ( null === $remote ) {
			return 'unknown';
		}

		$forwarded = self::valid( $server['HTTP_CF_CONNECTING_IP'] ?? null );

		if ( null !== $forwarded && self::trusted( $remote ) ) {
			return $forwarded;
		}

		return $remote;
	}

	/**
	 * Whether an address lies in one of the trusted proxy ranges.
	 *
	 * @param string $address IP address.
	 */
	public static function trusted( string $address ): bool {
		$ranges = apply_filters( 'merchboard_trusted_proxy_ranges', self::CLOUDFLARE );

		foreach ( is_array( $ranges ) ? $ranges : array() as $range ) {
			if ( is_string( $range ) && self::in_range( $address, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an address lies in a CIDR range (IPv4 or IPv6).
	 *
	 * @param string $address IP address.
	 * @param string $range   CIDR range, e.g. `104.16.0.0/13`.
	 */
	public static function in_range( string $address, string $range ): bool {
		[ $network, $bits ] = array_pad( explode( '/', trim( $range ), 2 ), 2, '' );
		$ip                 = @inet_pton( $address ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An invalid address is no match.
		$net                = @inet_pton( $network ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An invalid range is no match.

		if ( false === $ip || false === $net || strlen( $ip ) !== strlen( $net ) || ! ctype_digit( $bits ) ) {
			return false;
		}

		$bits = (int) $bits;

		if ( $bits > strlen( $ip ) * 8 ) {
			return false;
		}

		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( substr( $ip, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
			return false;
		}

		if ( 0 === $rest ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;

		return ( ord( $ip[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask );
	}

	/**
	 * A valid IP address, or null.
	 *
	 * @param mixed $value Server variable.
	 */
	private static function valid( mixed $value ): ?string {
		$value = is_string( $value ) ? trim( $value ) : '';

		return false !== filter_var( $value, FILTER_VALIDATE_IP ) ? $value : null;
	}
}
