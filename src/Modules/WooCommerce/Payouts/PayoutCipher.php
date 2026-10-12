<?php
/**
 * Encryption of payout details.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

use SodiumException;

defined( 'ABSPATH' ) || exit;

/**
 * Sodium `secretbox` with the site's own key (ADR-056, planning decision 6):
 * `MERCHBOARD_PAYOUT_KEY` in wp-config.php, 32 random bytes in base64. The
 * key is never derived from WordPress' salts, never stored in the database
 * and never logged. Without a valid key bank and PayPal are unavailable.
 *
 * Ciphertext = base64( nonce ‖ secretbox ), stored with the key id, so a
 * later key rotation can tell which key a row needs. Sodium comes from PHP
 * (ext-sodium) or WordPress' sodium_compat; no dependency is added.
 */
final class PayoutCipher {

	/**
	 * The wp-config.php constant holding the key.
	 */
	public const KEY_CONSTANT = 'MERCHBOARD_PAYOUT_KEY';

	/**
	 * The raw key, or null when it is missing or invalid.
	 *
	 * @var string|null
	 */
	private ?string $key;

	/**
	 * Constructor.
	 *
	 * @param string|null $encoded_key The base64 key, null when not configured.
	 */
	public function __construct( ?string $encoded_key ) {
		$this->key = self::decode( $encoded_key );
	}

	/**
	 * The cipher with the key from wp-config.php.
	 */
	public static function from_config(): self {
		$value = defined( self::KEY_CONSTANT ) ? constant( self::KEY_CONSTANT ) : null;

		return new self( is_string( $value ) ? $value : null );
	}

	/**
	 * Whether a valid key is configured and sodium is available.
	 */
	public function available(): bool {
		return null !== $this->key;
	}

	/**
	 * Identifier of the configured key (not secret: a hash prefix).
	 *
	 * @throws PayoutException When no valid key is configured.
	 */
	public function key_id(): string {
		return substr( hash( 'sha256', 'merchboard-payout-key|' . $this->require_key() ), 0, 16 );
	}

	/**
	 * Encrypts plain text.
	 *
	 * @param string $plain Plain text.
	 * @throws PayoutException When no valid key is configured or encryption fails.
	 */
	public function encrypt( string $plain ): string {
		$key = $this->require_key();

		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext stored as text (ADR-056), not code.
		} catch ( SodiumException ) {
			throw new PayoutException( PayoutException::METHOD_UNAVAILABLE );
		}
	}

	/**
	 * Decrypts a ciphertext of this key.
	 *
	 * @param string $ciphertext Value from encrypt().
	 * @param string $key_id     Key id stored with it.
	 * @throws PayoutException When the key is missing, another key was used, or the ciphertext was changed.
	 */
	public function decrypt( string $ciphertext, string $key_id ): string {
		if ( ! hash_equals( $this->key_id(), $key_id ) ) {
			throw new PayoutException( PayoutException::DETAILS_UNREADABLE );
		}

		$raw = base64_decode( $ciphertext, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Stored ciphertext (ADR-056), not code.

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			throw new PayoutException( PayoutException::DETAILS_UNREADABLE );
		}

		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $this->require_key() );
		} catch ( SodiumException ) {
			$plain = false;
		}

		if ( false === $plain ) {
			throw new PayoutException( PayoutException::DETAILS_UNREADABLE );
		}

		return $plain;
	}

	/**
	 * The raw key.
	 *
	 * @throws PayoutException When no valid key is configured.
	 */
	private function require_key(): string {
		if ( null === $this->key ) {
			throw new PayoutException( PayoutException::METHOD_UNAVAILABLE );
		}

		return $this->key;
	}

	/**
	 * The raw key from its base64 form, or null when it is not exactly 32
	 * bytes or sodium is not available.
	 *
	 * @param string|null $encoded Base64 key.
	 */
	private static function decode( ?string $encoded ): ?string {
		if ( null === $encoded || ! function_exists( 'sodium_crypto_secretbox' ) || ! defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' ) ) {
			return null;
		}

		$key = base64_decode( trim( $encoded ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The configured key is base64 (ADR-056), not code.

		return is_string( $key ) && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $key ) ? $key : null;
	}
}
