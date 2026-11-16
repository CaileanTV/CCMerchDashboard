<?php
/**
 * The read-out's switch, key and monthly limit.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Tts;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\AlertSettingsException;
use MerchBoard\Modules\Alerts\Chatbot\ChatCipher;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-089: the read-out of alerts with Google Cloud Text-to-Speech is off
 * until the administrator switches it on. The API key is encrypted with
 * sodium in its own key domain (`alerts-tts`) in an option without
 * autoload, never shown again or logged, and audited only as "set" or
 * "removed". The characters read out this month are counted; at the
 * monthly limit nothing more is read out until the next month.
 * `merchboard_manage_alerts`.
 */
class TtsSettings {

	public const OPTION = 'merchboard_alert_tts';

	public const KEY_OPTION = 'merchboard_alert_tts_key';

	public const USAGE_OPTION = 'merchboard_alert_tts_usage';

	/**
	 * The default monthly limit in characters, under Google's free 4 million.
	 */
	public const DEFAULT_LIMIT = 3000000;

	/**
	 * The highest monthly limit.
	 */
	public const MAX_LIMIT = 100000000;

	/**
	 * A Google API key: letters, digits, `_` and `-`.
	 */
	private const KEY_PATTERN = '/^[A-Za-z0-9_-]{20,100}$/';

	/**
	 * Constructor.
	 *
	 * @param AuditTrail      $audit       Audit trail.
	 * @param Transaction     $transaction Transaction.
	 * @param ChatCipher|null $cipher      Cipher (tests).
	 */
	public function __construct( private AuditTrail $audit, private Transaction $transaction, private ?ChatCipher $cipher = null ) {
	}

	/**
	 * Whether alerts may be read out: switched on and a key stored.
	 */
	public function available(): bool {
		return $this->enabled() && null !== $this->key();
	}

	/**
	 * Whether the administrator switched the read-out on.
	 */
	public function enabled(): bool {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) && true === ( $stored['enabled'] ?? false );
	}

	/**
	 * The monthly limit in characters.
	 */
	public function limit(): int {
		$stored = get_option( self::OPTION, array() );
		$limit  = is_array( $stored ) && is_int( $stored['limit'] ?? null ) ? $stored['limit'] : self::DEFAULT_LIMIT;

		return max( 0, min( self::MAX_LIMIT, $limit ) );
	}

	/**
	 * The characters read out this month.
	 */
	public function used(): int {
		$stored = get_option( self::USAGE_OPTION, array() );

		return is_array( $stored ) && self::month() === ( $stored['month'] ?? '' ) && is_int( $stored['chars'] ?? null ) ? $stored['chars'] : 0;
	}

	/**
	 * Whether a text of this many characters may still be read out this month.
	 *
	 * @param int $chars Characters.
	 */
	public function allows( int $chars ): bool {
		return $this->used() + $chars <= $this->limit();
	}

	/**
	 * Reserves characters this month; the caller must hold the site TTS lock.
	 *
	 * @param int $chars Characters.
	 * @return bool Whether the reservation was persisted.
	 */
	public function count( int $chars ): bool {
		return update_option(
			self::USAGE_OPTION,
			array(
				'month' => self::month(),
				'chars' => $this->used() + max( 0, $chars ),
			),
			false
		);
	}

	/**
	 * The key, or null.
	 */
	public function key(): ?string {
		$stored = get_option( self::KEY_OPTION, '' );

		return is_string( $stored ) && '' !== $stored ? $this->cipher()->decrypt( $stored ) : null;
	}

	/**
	 * Saves the switch, the limit and a new key ('' keeps the stored one).
	 *
	 * @param bool   $enabled Whether alerts are read out.
	 * @param int    $limit   Monthly limit in characters.
	 * @param string $key     New key; '' keeps the stored one.
	 * @return bool Whether something changed.
	 * @throws AlertSettingsException When the user may not or a value is invalid.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save( bool $enabled, int $limit, string $key ): bool {
		self::authorize();
		$key = trim( $key );

		if ( $limit < 0 || $limit > self::MAX_LIMIT ) {
			self::refuse( AlertSettingsException::INVALID_TTS_LIMIT );
		}

		if ( '' !== $key && 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
			self::refuse( AlertSettingsException::INVALID_TTS_KEY );
		}

		$encrypted = '' === $key ? null : ( $this->cipher()->encrypt( $key ) ?? self::refuse( AlertSettingsException::INVALID_TTS_KEY ) );
		$changed   = $enabled !== $this->enabled() || $limit !== $this->limit() || null !== $encrypted;

		if ( ! $changed ) {
			return false;
		}

		$this->transaction->run(
			function () use ( $enabled, $limit, $encrypted ): void {
				$this->audit->record(
					AuditAction::SettingsUpdated,
					null,
					array(
						'setting' => 'alerts_tts',
						'enabled' => $enabled,
						'limit'   => $limit,
						'key'     => null === $encrypted ? 'unchanged' : 'set',
					)
				);
				update_option(
					self::OPTION,
					array(
						'enabled' => $enabled,
						'limit'   => $limit,
					),
					false
				);

				if ( null !== $encrypted ) {
					update_option( self::KEY_OPTION, $encrypted, false );
				}
			}
		);

		return true;
	}

	/**
	 * Removes the key; the read-out stops.
	 *
	 * @throws AlertSettingsException When the user may not.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function clear_key(): void {
		self::authorize();

		if ( false === get_option( self::KEY_OPTION, false ) ) {
			return;
		}

		$this->transaction->run(
			function (): void {
				$this->audit->record(
					AuditAction::SettingsUpdated,
					null,
					array(
						'setting' => 'alerts_tts',
						'key'     => 'removed',
					)
				);
				delete_option( self::KEY_OPTION );
			}
		);
	}

	/**
	 * The current month, e.g. "2026-11" (UTC).
	 */
	private static function month(): string {
		return gmdate( 'Y-m' );
	}

	/**
	 * The cipher of the key's domain.
	 */
	private function cipher(): ChatCipher {
		$this->cipher ??= new ChatCipher( 'alerts-tts' );

		return $this->cipher;
	}

	/**
	 * Requires the alerts capability.
	 *
	 * @throws AlertSettingsException When the user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			self::refuse( AlertSettingsException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Refuses with a reason.
	 *
	 * @param string $reason AlertSettingsException reason.
	 * @throws AlertSettingsException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new AlertSettingsException( $reason );
	}
}
