<?php
/**
 * The creators' API keys of the alert API.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Api;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Making, revoking and checking API keys (ADR-073).
 *
 * - **Format:** `mbk_{lookup}_{secret}`: 12 and 48 hex characters from
 *   `random_bytes()`. Only the lookup part and an HMAC-SHA256 of the secret
 *   part (keyed with WordPress' auth salt) are stored, so the key is shown
 *   once, when it is made, and a copy of the database does not give it away.
 *   Changing the salt stops every key.
 * - **Limits:** at most MAX_KEYS working keys per creator; only while the
 *   administrator has the API on and has given the creator access (AlertApiAccess, step 25.12.4).
 * - **Audit:** made and revoked keys (key id only, never the key).
 *
 * Callers pass the creator that CreatorContext resolved (ADR-007); a creator
 * only ever revokes their own keys. The administrator revokes all of a
 * creator's keys at once.
 */
class AlertApiKeys {

	/**
	 * Working keys per creator.
	 */
	public const MAX_KEYS = 5;

	/**
	 * Longest name.
	 */
	public const NAME_MAX = 60;

	/**
	 * The last use is noted at most this often (seconds).
	 */
	public const TOUCH_INTERVAL = 60;

	/**
	 * The current time.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @param AlertApiKeyRepository               $keys        Storage.
	 * @param AlertApiSwitch                      $api         The administrator's switch.
	 * @param AuditTrail                          $audit       Audit log.
	 * @param Transaction                         $transaction Database transaction.
	 * @param AlertApiAccess                      $access      The creators' access (step 25.12.4).
	 * @param (Closure(): DateTimeImmutable)|null $clock       Current time; defaults to now.
	 */
	public function __construct( private AlertApiKeyRepository $keys, private AlertApiSwitch $api, private AuditTrail $audit, private Transaction $transaction, private AlertApiAccess $access, ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * A creator's working keys, the newest first.
	 *
	 * @param int $creator_id Creator.
	 * @return list<AlertApiKey>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function for_creator( int $creator_id ): array {
		return $this->keys->active_for( $creator_id );
	}

	/**
	 * Makes a key.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $name       The creator's name for it.
	 * @return string The whole key; it is never shown again.
	 * @throws AlertApiException When the API is off, the creator has no access, the name is invalid or the creator has MAX_KEYS keys.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create( int $creator_id, string $name ): string {
		if ( ! $this->api->enabled() ) {
			self::refuse( AlertApiException::DISABLED );
		}

		if ( ! $this->access->keys( $creator_id ) ) {
			self::refuse( AlertApiException::NO_ACCESS );
		}

		$name = trim( sanitize_text_field( $name ) );

		if ( '' === $name || mb_strlen( $name ) > self::NAME_MAX ) {
			self::refuse( AlertApiException::INVALID_NAME );
		}

		return $this->transaction->run(
			function () use ( $creator_id, $name ): string {
				if ( count( $this->keys->active_for( $creator_id ) ) >= self::MAX_KEYS ) {
					self::refuse( AlertApiException::KEY_LIMIT );
				}

				$lookup = bin2hex( random_bytes( 6 ) );
				$secret = bin2hex( random_bytes( 24 ) );
				$id     = $this->keys->insert( $creator_id, $name, $lookup, self::hash( $secret ) );

				$this->audit->record( AuditAction::AlertApiKeyCreated, $creator_id, array( 'key_id' => $id ) );

				return AlertApiKey::PREFIX . $lookup . '_' . $secret;
			}
		);
	}

	/**
	 * Revokes one of the creator's keys.
	 *
	 * @param int $creator_id Creator.
	 * @param int $key_id     Key.
	 * @throws AlertApiException When it is not one of the creator's working keys.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function revoke( int $creator_id, int $key_id ): void {
		$this->transaction->run(
			function () use ( $creator_id, $key_id ): void {
				$key = $this->keys->find_active( $creator_id, $key_id );

				if ( null === $key || ! $this->keys->revoke( $key->id ) ) {
					self::refuse( AlertApiException::UNKNOWN_KEY );
				}

				$this->audit->record(
					AuditAction::AlertApiKeyRevoked,
					$creator_id,
					array(
						'key_id'   => $key->id,
						'by_admin' => false,
					)
				);
			}
		);
	}

	/**
	 * Revokes all of a creator's keys (the administrator).
	 *
	 * @param int $creator_id Creator.
	 * @return int Keys revoked.
	 * @throws AlertApiException When the current user may not manage alerts.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function revoke_all( int $creator_id ): int {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			self::refuse( AlertApiException::NOT_AUTHORIZED );
		}

		return $this->transaction->run(
			function () use ( $creator_id ): int {
				$revoked = 0;

				foreach ( $this->keys->active_for( $creator_id ) as $key ) {
					if ( $this->keys->revoke( $key->id ) ) {
						$this->audit->record(
							AuditAction::AlertApiKeyRevoked,
							$creator_id,
							array(
								'key_id'   => $key->id,
								'by_admin' => true,
							)
						);
						++$revoked;
					}
				}

				return $revoked;
			}
		);
	}

	/**
	 * The working key of an `Authorization: Bearer …` header, or null for
	 * anything else. Notes the use at most every TOUCH_INTERVAL seconds. Does
	 * not check the creator or the switch (the feed does).
	 *
	 * @param string $header The Authorization header.
	 * @throws DatabaseException When the database cannot be read or written.
	 */
	public function authenticate( string $header ): ?AlertApiKey {
		if ( 1 !== preg_match( '/^Bearer\s+mbk_([0-9a-f]{12})_([0-9a-f]{48})$/D', trim( $header ), $parts ) ) {
			return null;
		}

		$key = $this->keys->find_by_lookup( $parts[1] );

		if ( null === $key || ! $key->is_active() || ! hash_equals( $key->secret_hash, self::hash( $parts[2] ) ) ) {
			return null;
		}

		$now = ( $this->clock )();

		if ( null === $key->last_used_at || $now->getTimestamp() - $key->last_used_at->getTimestamp() >= self::TOUCH_INTERVAL ) {
			$this->keys->touch( $key->id, $now );
		}

		return $key;
	}

	/**
	 * The stored HMAC of a secret part.
	 *
	 * @param string $secret Secret part.
	 */
	public static function hash( string $secret ): string {
		return hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) );
	}

	/**
	 * Refuses a change.
	 *
	 * @param string $reason AlertApiException constant.
	 * @throws AlertApiException Always.
	 */
	private static function refuse( string $reason ): never {
		throw new AlertApiException( $reason );
	}
}
