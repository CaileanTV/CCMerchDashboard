<?php
/**
 * Verifies Kick's webhook signatures.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Kick signs each webhook request with its private key (ADR-069 pkt. 6):
 * RSA-SHA256 (PKCS#1 v1.5) over `Kick-Event-Message-Id`,
 * `Kick-Event-Message-Timestamp` and the raw body, joined with `.`, sent
 * Base64-encoded in `Kick-Event-Signature`. The public key is fetched from
 * Kick (never hardcoded, Kick may rotate it), cached for a day and fetched
 * again when a signature does not verify (at most every ten minutes, so
 * forged requests cannot make the site call Kick on every request). Requests
 * older than ten minutes are refused.
 */
final class KickWebhookVerifier {

	/**
	 * Transient with Kick's public key.
	 */
	public const KEY_TRANSIENT = 'merchboard_kick_public_key';

	/**
	 * Transient that limits fetching the key again.
	 */
	private const REFETCH_TRANSIENT = 'merchboard_kick_public_key_refetch';

	/**
	 * Oldest accepted request (s).
	 */
	private const MAX_AGE = 600;

	/**
	 * Constructor.
	 *
	 * @param KickApi $api Kick.
	 */
	public function __construct( private KickApi $api ) {
	}

	/**
	 * Whether a request comes from Kick.
	 *
	 * @param string $id        `Kick-Event-Message-Id`.
	 * @param string $timestamp `Kick-Event-Message-Timestamp` (RFC 3339).
	 * @param string $signature `Kick-Event-Signature` (Base64).
	 * @param string $body      Raw body.
	 */
	public function verify( string $id, string $timestamp, string $signature, string $body ): bool {
		$time = strtotime( $timestamp );
		$raw  = base64_decode( $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A signature, not code.

		if ( '' === $id || false === $time || abs( time() - $time ) > self::MAX_AGE || false === $raw || '' === $raw || ! function_exists( 'openssl_verify' ) ) {
			return false;
		}

		$data = $id . '.' . $timestamp . '.' . $body;
		$key  = $this->key( false );

		if ( null !== $key && self::matches( $data, $raw, $key ) ) {
			return true;
		}

		if ( false !== get_transient( self::REFETCH_TRANSIENT ) ) {
			return false;
		}

		set_transient( self::REFETCH_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );
		$fresh = $this->key( true );

		return null !== $fresh && $fresh !== $key && self::matches( $data, $raw, $fresh );
	}

	/**
	 * Kick's public key (PEM), cached; null when it cannot be fetched.
	 *
	 * @param bool $fresh Fetch it again.
	 */
	private function key( bool $fresh ): ?string {
		$cached = get_transient( self::KEY_TRANSIENT );

		if ( ! $fresh && is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		try {
			$key = $this->api->public_key();
		} catch ( KickException ) {
			return is_string( $cached ) && '' !== $cached ? $cached : null;
		}

		set_transient( self::KEY_TRANSIENT, $key, DAY_IN_SECONDS );

		return $key;
	}

	/**
	 * Whether the signature is valid for the data and the key.
	 *
	 * @param string $data      Signed data.
	 * @param string $signature Raw signature.
	 * @param string $key       PEM public key.
	 */
	private static function matches( string $data, string $signature, string $key ): bool {
		$public = openssl_pkey_get_public( $key );

		return false !== $public && 1 === openssl_verify( $data, $signature, $public, OPENSSL_ALGO_SHA256 );
	}
}
