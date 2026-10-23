<?php
/**
 * Storage of the creators' API keys.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Api;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_alert_api_keys` (schema version 26, ADR-073). Revoked keys
 * stay as rows (they never work again); lists show the working ones only.
 * Times are UTC.
 */
class AlertApiKeyRepository {

	private const COLUMNS = 'id, creator_id, name, lookup, secret_hash, created_at, last_used_at, revoked_at';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Adds a key.
	 *
	 * @param int    $creator_id  Creator.
	 * @param string $name        Cleaned name.
	 * @param string $lookup      Lookup part.
	 * @param string $secret_hash HMAC of the secret part.
	 * @return int Id.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function insert( int $creator_id, string $name, string $lookup, string $secret_hash ): int {
		$done = $this->wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
			$this->table(),
			array(
				'creator_id'  => $creator_id,
				'name'        => $name,
				'lookup'      => $lookup,
				'secret_hash' => $secret_hash,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not store the API key.' );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * The key with a lookup part, revoked or not; null when there is none.
	 *
	 * @param string $lookup Lookup part.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function find_by_lookup( string $lookup ): ?AlertApiKey {
		$rows = $this->rows( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE lookup = %s LIMIT 1', $this->table(), $lookup );

		return isset( $rows[0] ) ? self::hydrate( $rows[0] ) : null;
	}

	/**
	 * A creator's working key, or null.
	 *
	 * @param int $creator_id Creator.
	 * @param int $id         Key.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function find_active( int $creator_id, int $id ): ?AlertApiKey {
		$rows = $this->rows( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d AND creator_id = %d AND revoked_at IS NULL LIMIT 1', $this->table(), $id, $creator_id );

		return isset( $rows[0] ) ? self::hydrate( $rows[0] ) : null;
	}

	/**
	 * A creator's working keys, the newest first.
	 *
	 * @param int $creator_id Creator.
	 * @return list<AlertApiKey>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function active_for( int $creator_id ): array {
		return array_map( self::hydrate( ... ), $this->rows( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d AND revoked_at IS NULL ORDER BY id DESC', $this->table(), $creator_id ) );
	}

	/**
	 * The creators with working keys: how many and when one was last used.
	 *
	 * @return list<array{creator_id: int, keys: int, last_used_at: DateTimeImmutable|null}>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function overview(): array {
		$utc    = new DateTimeZone( 'UTC' );
		$result = array();

		foreach ( $this->rows( 'SELECT creator_id, COUNT(*) AS total, MAX(last_used_at) AS last_used_at FROM %i WHERE revoked_at IS NULL GROUP BY creator_id ORDER BY creator_id', $this->table() ) as $row ) {
			try {
				$last_used = is_string( $row['last_used_at'] ?? null ) ? new DateTimeImmutable( $row['last_used_at'], $utc ) : null;
			} catch ( Exception ) {
				$last_used = null;
			}

			$result[] = array(
				'creator_id'   => is_numeric( $row['creator_id'] ?? null ) ? (int) $row['creator_id'] : 0,
				'keys'         => is_numeric( $row['total'] ?? null ) ? (int) $row['total'] : 0,
				'last_used_at' => $last_used,
			);
		}

		return $result;
	}

	/**
	 * Revokes a key.
	 *
	 * @param int $id Key.
	 * @return bool False when it was revoked already.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function revoke( int $id ): bool {
		$wpdb = $this->wpdb;
		$sql  = $wpdb->prepare( 'UPDATE %i SET revoked_at = %s WHERE id = %d AND revoked_at IS NULL', $this->table(), gmdate( 'Y-m-d H:i:s' ), $id );

		if ( null === $sql ) {
			throw new DatabaseException( 'Could not prepare the API key update.' );
		}

		$changed = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; a custom table.

		if ( false === $changed || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not revoke the API key.' );
		}

		return $changed > 0;
	}

	/**
	 * Notes that a key was used.
	 *
	 * @param int               $id  Key.
	 * @param DateTimeImmutable $now Now.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function touch( int $id, DateTimeImmutable $now ): void {
		$wpdb = $this->wpdb;

		if ( false === $wpdb->update( $this->table(), array( 'last_used_at' => $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ) || '' !== $wpdb->last_error ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
			throw new DatabaseException( 'Could not note the use of the API key.' );
		}
	}

	/**
	 * Rows of a prepared query.
	 *
	 * @phpstan-param literal-string $query
	 *
	 * @param string     $query   Query with placeholders.
	 * @param int|string ...$args Values.
	 * @return list<array<mixed>>
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function rows( string $query, int|string ...$args ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( $query, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared; a custom table.

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the API keys.' );
		}

		return $rows;
	}

	/**
	 * A key from a row.
	 *
	 * @param array<mixed> $row Row.
	 * @throws DatabaseException When the row is invalid.
	 */
	private static function hydrate( array $row ): AlertApiKey {
		$utc  = new DateTimeZone( 'UTC' );
		$time = static fn( mixed $value ): ?DateTimeImmutable => is_string( $value ) ? new DateTimeImmutable( $value, $utc ) : null;

		try {
			$created   = $time( $row['created_at'] ?? null ) ?? new DateTimeImmutable( 'now', $utc );
			$last_used = $time( $row['last_used_at'] ?? null );
			$revoked   = $time( $row['revoked_at'] ?? null );
		} catch ( Exception ) {
			throw new DatabaseException( 'Invalid API key row.' );
		}

		return new AlertApiKey(
			is_numeric( $row['id'] ?? null ) ? (int) $row['id'] : 0,
			is_numeric( $row['creator_id'] ?? null ) ? (int) $row['creator_id'] : 0,
			is_string( $row['name'] ?? null ) ? $row['name'] : '',
			is_string( $row['lookup'] ?? null ) ? $row['lookup'] : '',
			is_string( $row['secret_hash'] ?? null ) ? $row['secret_hash'] : '',
			$created,
			$last_used,
			$revoked
		);
	}

	/**
	 * The keys' table.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::ALERT_API_KEYS );
	}
}
