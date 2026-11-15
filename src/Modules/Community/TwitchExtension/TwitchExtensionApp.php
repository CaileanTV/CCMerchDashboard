<?php
/**
 * The shop's Twitch Extension as set up by an administrator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchExtension;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Alerts\Chatbot\ChatCipher;

defined( 'ABSPATH' ) || exit;

/**
 * The extension's Client ID, secret and install link (ADR-112 pkt. 5), in one
 * option without autoload. The secret is stored encrypted (ChatCipher) or set
 * as the constant MERCHBOARD_TWITCH_EXTENSION_SECRET in wp-config.php, which
 * wins. It is never shown again, logged or audited (only that it changed).
 */
final class TwitchExtensionApp {

	/**
	 * The option.
	 */
	public const OPTION = 'merchboard_twitch_extension';

	/**
	 * The constant that holds the secret instead of the option.
	 */
	public const SECRET_CONSTANT = 'MERCHBOARD_TWITCH_EXTENSION_SECRET';

	/**
	 * Where an install link may point.
	 */
	private const LINK_PATTERN = '#^https://(dashboard|www)\.twitch\.tv/[A-Za-z0-9/_.\-]+$#';

	/**
	 * Constructor.
	 *
	 * @param ChatCipher  $cipher      Encryption of the secret.
	 * @param AuditTrail  $audit       Audit trail.
	 * @param Transaction $transaction Transactions.
	 */
	public function __construct( private ChatCipher $cipher, private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * The Client ID; '' before it is set.
	 */
	public function client_id(): string {
		return $this->stored()['client_id'];
	}

	/**
	 * The link creators install the extension from; '' when not set.
	 */
	public function install_url(): string {
		return $this->stored()['install_url'];
	}

	/**
	 * Whether the secret comes from wp-config.php.
	 */
	public static function secret_in_config(): bool {
		return defined( self::SECRET_CONSTANT ) && is_string( constant( self::SECRET_CONSTANT ) ) && '' !== constant( self::SECRET_CONSTANT );
	}

	/**
	 * The key that signs the extension's JWTs (the secret, base64-decoded);
	 * null when there is no readable secret.
	 */
	public function key(): ?string {
		$secret = self::secret_in_config() ? (string) constant( self::SECRET_CONSTANT ) : null;

		if ( null === $secret ) {
			$stored = $this->stored()['secret'];
			$secret = '' === $stored ? null : $this->cipher->decrypt( $stored );
		}

		return null === $secret ? null : self::decoded( $secret );
	}

	/**
	 * Whether the extension is set up: a Client ID and a readable secret.
	 */
	public function ready(): bool {
		return '' !== $this->client_id() && null !== $this->key();
	}

	/**
	 * Saves the setup. An empty secret keeps the stored one.
	 *
	 * @param string $client_id   Client ID ('' removes the setup's ID).
	 * @param string $secret      New secret, or '' to keep it.
	 * @param string $install_url Install link, or ''.
	 * @return list<string>|null The fields that changed; null when a value is invalid (nothing is kept).
	 * @throws \MerchBoard\Core\Database\DatabaseException When it cannot be stored.
	 */
	public function save( string $client_id, string $secret, string $install_url ): ?array {
		$client_id   = trim( $client_id );
		$secret      = trim( $secret );
		$install_url = trim( $install_url );

		if ( ( '' !== $client_id && 1 !== preg_match( '/^[a-z0-9]{20,40}$/', $client_id ) )
			|| ( '' !== $secret && null === self::decoded( $secret ) )
			|| ( '' !== $install_url && 1 !== preg_match( self::LINK_PATTERN, $install_url ) ) ) {
			return null;
		}

		$stored = $this->stored();
		$values = $stored;

		$values['client_id']   = $client_id;
		$values['install_url'] = $install_url;
		$changed               = array();

		foreach ( array( 'client_id', 'install_url' ) as $field ) {
			if ( $stored[ $field ] !== $values[ $field ] ) {
				$changed[] = $field;
			}
		}

		// A new secret replaces the stored one; the same or none keeps it.
		if ( '' !== $secret && $this->stored_secret() !== $secret ) {
			$encrypted = $this->cipher->encrypt( $secret );

			if ( null === $encrypted ) {
				return null;
			}

			$values['secret'] = $encrypted;
			$changed[]        = 'secret';
		}

		if ( array() === $changed ) {
			return array();
		}

		$this->transaction->run(
			function () use ( $values, $changed, $stored ): void {
				update_option( self::OPTION, $values, false );

				foreach ( $changed as $field ) {
					$this->audit->record(
						AuditAction::SettingsUpdated,
						null,
						'secret' === $field
							? array( 'setting' => 'twitch_extension_secret' )
							: array(
								'setting'        => 'twitch_extension_' . $field,
								'previous_value' => $stored[ $field ],
								'value'          => $values[ $field ],
							)
					);
				}
			}
		);

		return $changed;
	}

	/**
	 * The stored secret in plain text, or null.
	 */
	private function stored_secret(): ?string {
		$stored = $this->stored()['secret'];

		return '' === $stored ? null : $this->cipher->decrypt( $stored );
	}

	/**
	 * A secret as Twitch shows it (base64) to the key; null when it is not
	 * base64 of at least 16 bytes.
	 *
	 * @param string $secret The secret.
	 */
	private static function decoded( string $secret ): ?string {
		$key = base64_decode( $secret, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Twitch's shared secret is base64.

		return false === $key || strlen( $key ) < 16 ? null : $key;
	}

	/**
	 * The stored values, normalized.
	 *
	 * @return array{client_id: string, secret: string, install_url: string}
	 */
	private function stored(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		return array(
			'client_id'   => is_string( $raw['client_id'] ?? null ) ? $raw['client_id'] : '',
			'secret'      => is_string( $raw['secret'] ?? null ) ? $raw['secret'] : '',
			'install_url' => is_string( $raw['install_url'] ?? null ) ? $raw['install_url'] : '',
		);
	}
}
