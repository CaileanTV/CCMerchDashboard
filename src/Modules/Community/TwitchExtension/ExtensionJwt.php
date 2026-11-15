<?php
/**
 * The JWT that Twitch gives the extension in a viewer's browser.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchExtension;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies the extension's JWT (ADR-112 pkt. 3): Twitch signs it with HS256
 * and the extension's shared secret (base64-decoded). Only a token with the
 * right signature, an `exp` in the future and a numeric `channel_id` is
 * accepted; the channel is never taken from anywhere else.
 */
final class ExtensionJwt {

	/**
	 * The longest token read (Twitch's are well under 1 KB).
	 */
	private const MAX_LENGTH = 4096;

	/**
	 * The channel of a valid token, or null.
	 *
	 * @param string $token The token (without "Bearer ").
	 * @param string $key   The extension's secret, base64-decoded.
	 * @param int    $now   Unix time.
	 */
	public static function channel( string $token, string $key, int $now ): ?string {
		if ( '' === $key || '' === $token || strlen( $token ) > self::MAX_LENGTH ) {
			return null;
		}

		$parts = explode( '.', $token );

		if ( 3 !== count( $parts ) ) {
			return null;
		}

		[ $head, $body, $signature ] = $parts;
		$header                      = json_decode( (string) self::decode( $head ), true );
		$given                       = self::decode( $signature );

		if ( ! is_array( $header ) || 'HS256' !== ( $header['alg'] ?? null ) || null === $given ) {
			return null;
		}

		if ( ! hash_equals( hash_hmac( 'sha256', $head . '.' . $body, $key, true ), $given ) ) {
			return null;
		}

		$claims  = json_decode( (string) self::decode( $body ), true );
		$expires = is_array( $claims ) ? ( $claims['exp'] ?? null ) : null;
		$channel = is_array( $claims ) ? ( $claims['channel_id'] ?? null ) : null;

		if ( ! is_int( $expires ) || $expires <= $now || ! is_string( $channel ) || 1 !== preg_match( '/^\d{1,20}$/', $channel ) ) {
			return null;
		}

		return $channel;
	}

	/**
	 * Base64url to bytes; null when it is not base64url.
	 *
	 * @param string $part A part of the token.
	 */
	private static function decode( string $part ): ?string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]*$/', $part ) ) {
			return null;
		}

		$bytes = base64_decode( strtr( $part, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $part ) % 4 ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A JWT part, not code.

		return false === $bytes ? null : $bytes;
	}
}
