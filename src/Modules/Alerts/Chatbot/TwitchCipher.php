<?php
/**
 * Encryption of the Twitch secrets.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use SodiumException;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts the Twitch app secret, the app access token and the webhook
 * secret (ADR-066 pkt. 5) with sodium `secretbox`. The key is derived from
 * `wp_salt( 'auth' )` with a domain of its own, separate from the payout key
 * (`MERCHBOARD_PAYOUT_KEY`, ADR-056). When the salts change, stored values
 * can no longer be read (decrypt() gives null) and the administrator enters
 * the secret and connects the bot again.
 */
final class TwitchCipher {

	/**
	 * Domain of the key derivation.
	 */
	private const DOMAIN = 'merchboard-alerts-twitch-v1|';

	/**
	 * The raw key; null without sodium.
	 *
	 * @var string|null
	 */
	private ?string $key;

	/**
	 * Constructor.
	 *
	 * @param string|null $salt Secret material; defaults to wp_salt( 'auth' ).
	 */
	public function __construct( ?string $salt = null ) {
		$this->key = function_exists( 'sodium_crypto_secretbox' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' )
			? hash( 'sha256', self::DOMAIN . ( $salt ?? wp_salt( 'auth' ) ), true )
			: null;
	}

	/**
	 * Whether values can be encrypted.
	 */
	public function available(): bool {
		return null !== $this->key;
	}

	/**
	 * Encrypts plain text; null when sodium is not available.
	 *
	 * @param string $plain Plain text.
	 */
	public function encrypt( string $plain ): ?string {
		if ( null === $this->key ) {
			return null;
		}

		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext stored as text, not code.
		} catch ( SodiumException ) {
			return null;
		}
	}

	/**
	 * Decrypts a value of encrypt(); null when it cannot be read (another
	 * key, a changed value or no sodium).
	 *
	 * @param string $ciphertext Value from encrypt().
	 */
	public function decrypt( string $ciphertext ): ?string {
		$raw = base64_decode( $ciphertext, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Stored ciphertext, not code.

		if ( null === $this->key || false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $this->key );
		} catch ( SodiumException ) {
			return null;
		}

		return false === $plain ? null : $plain;
	}
}
