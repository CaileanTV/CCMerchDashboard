<?php
/**
 * Storage of the creators' requests to go live.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\GoLive;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_go_live_requests` (ADR-091): `active_creator_id` is set only
 * while a request is pending, so the unique key allows one pending request
 * per creator. A decided request keeps its status, note and decider.
 */
class GoLiveRepository {

	public const PENDING = 'pending';

	public const APPROVED = 'approved';

	public const DECLINED = 'declined';

	private const COLUMNS = 'id, creator_id, status, note, requested_at, decided_at';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Stores a pending request.
	 *
	 * @param int               $creator_id Creator.
	 * @param int|null          $user_id    Who asked.
	 * @param DateTimeImmutable $now        Now (UTC).
	 * @return bool Whether it was stored (false: one is pending already).
	 * @throws DatabaseException When it cannot be stored for another reason.
	 */
	public function insert( int $creator_id, ?int $user_id, DateTimeImmutable $now ): bool {
		$wpdb = $this->wpdb;
		$wpdb->suppress_errors( true );
		$stored = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
			$this->table(),
			array(
				'creator_id'        => $creator_id,
				'active_creator_id' => $creator_id,
				'status'            => self::PENDING,
				'requested_at'      => self::utc( $now ),
				'requested_by'      => $user_id,
			),
			array( '%d', '%d', '%s', '%s', '%d' )
		);
		$wpdb->suppress_errors( false );

		if ( 1 === $stored ) {
			return true;
		}

		if ( null !== $this->pending_for( $creator_id ) ) {
			return false;
		}

		throw new DatabaseException( 'Could not store the request to go live.' );
	}

	/**
	 * A request, or null.
	 *
	 * @param int $id Request.
	 * @return array{id: int, creator_id: int, status: string, note: string, requested_at: string, decided_at: string|null}|null
	 * @throws DatabaseException When it cannot be read.
	 */
	public function find( int $id ): ?array {
		return $this->one( 'id = %d', $id );
	}

	/**
	 * A creator's pending request, or null.
	 *
	 * @param int $creator_id Creator.
	 * @return array{id: int, creator_id: int, status: string, note: string, requested_at: string, decided_at: string|null}|null
	 * @throws DatabaseException When it cannot be read.
	 */
	public function pending_for( int $creator_id ): ?array {
		return $this->one( 'active_creator_id = %d', $creator_id );
	}

	/**
	 * A creator's newest request, or null.
	 *
	 * @param int $creator_id Creator.
	 * @return array{id: int, creator_id: int, status: string, note: string, requested_at: string, decided_at: string|null}|null
	 * @throws DatabaseException When it cannot be read.
	 */
	public function latest_for( int $creator_id ): ?array {
		return $this->one( 'creator_id = %d', $creator_id );
	}

	/**
	 * The pending requests, oldest first.
	 *
	 * @return list<array{id: int, creator_id: int, status: string, note: string, requested_at: string, decided_at: string|null}>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function pending(): array {
		return $this->many( 'status = %s ORDER BY requested_at ASC, id ASC LIMIT 200', self::PENDING );
	}

	/**
	 * The approved requests, newest first (for a category that could not be made).
	 *
	 * @return list<array{id: int, creator_id: int, status: string, note: string, requested_at: string, decided_at: string|null}>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function approved(): array {
		return $this->many( 'status = %s ORDER BY decided_at DESC, id DESC LIMIT 50', self::APPROVED );
	}

	/**
	 * The number of pending requests.
	 *
	 * @throws DatabaseException When it cannot be read.
	 */
	public function pending_count(): int {
		$wpdb  = $this->wpdb;
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $this->table(), self::PENDING ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the red count.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not count the requests to go live.' );
		}

		return (int) $count;
	}

	/**
	 * Decides a pending request.
	 *
	 * @param int               $id      Request.
	 * @param string            $status  APPROVED or DECLINED.
	 * @param string|null       $note    The note to the creator.
	 * @param int|null          $user_id Who decided.
	 * @param DateTimeImmutable $now     Now (UTC).
	 * @return bool Whether it was pending and is decided now.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function decide( int $id, string $status, ?string $note, ?int $user_id, DateTimeImmutable $now ): bool {
		$updated = $this->wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$this->table(),
			array(
				'status'            => $status,
				'active_creator_id' => null,
				'note'              => $note,
				'decided_at'        => self::utc( $now ),
				'decided_by'        => $user_id,
			),
			array(
				'id'     => $id,
				'status' => self::PENDING,
			),
			array( '%s', '%d', '%s', '%s', '%d' ),
			array( '%d', '%s' )
		);

		if ( false === $updated ) {
			throw new DatabaseException( 'Could not decide the request to go live.' );
		}

		return 1 === $updated;
	}

	/**
	 * Deletes a creator's requests (the creator is deleted, ADR-076).
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When they cannot be deleted.
	 */
	public function delete_for( int $creator_id ): void {
		if ( false === $this->wpdb->delete( $this->table(), array( 'creator_id' => $creator_id ), array( '%d' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			throw new DatabaseException( 'Could not delete the requests to go live.' );
		}
	}

	/**
	 * One row by a condition, newest first.
	 *
	 * @param string $where Condition with one %d placeholder.
	 * @param int    $value Its value.
	 * @phpstan-param literal-string $where
	 * @return array{id: int, creator_id: int, status: string, note: string, requested_at: string, decided_at: string|null}|null
	 * @throws DatabaseException When it cannot be read.
	 */
	private function one( string $where, int $value ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE ' . $where . ' ORDER BY id DESC LIMIT 1', $this->table(), $value ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant columns and conditions with one placeholder each; values are prepared.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the request to go live.' );
		}

		return is_array( $row ) ? self::row( $row ) : null;
	}

	/**
	 * Rows by a condition.
	 *
	 * @param string $where Condition with one %s placeholder (and the order).
	 * @param string $value Its value.
	 * @phpstan-param literal-string $where
	 * @return list<array{id: int, creator_id: int, status: string, note: string, requested_at: string, decided_at: string|null}>
	 * @throws DatabaseException When they cannot be read.
	 */
	private function many( string $where, string $value ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE ' . $where, $this->table(), $value ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant columns and conditions with one placeholder each; values are prepared.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the requests to go live.' );
		}

		return array_map( self::row( ... ), $rows );
	}

	/**
	 * A request from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array{id: int, creator_id: int, status: string, note: string, requested_at: string, decided_at: string|null}
	 */
	private static function row( array $row ): array {
		return array(
			'id'           => is_numeric( $row['id'] ?? null ) ? (int) $row['id'] : 0,
			'creator_id'   => is_numeric( $row['creator_id'] ?? null ) ? (int) $row['creator_id'] : 0,
			'status'       => is_string( $row['status'] ?? null ) ? $row['status'] : '',
			'note'         => is_string( $row['note'] ?? null ) ? $row['note'] : '',
			'requested_at' => is_string( $row['requested_at'] ?? null ) ? $row['requested_at'] : '',
			'decided_at'   => is_string( $row['decided_at'] ?? null ) ? $row['decided_at'] : null,
		);
	}

	/**
	 * A UTC DATETIME value.
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function utc( DateTimeImmutable $time ): string {
		return $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::GO_LIVE_REQUESTS );
	}
}
