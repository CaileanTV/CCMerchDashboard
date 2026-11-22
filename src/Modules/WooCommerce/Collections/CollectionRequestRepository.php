<?php
/**
 * Storage of the collection requests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Collections;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_collection_requests` and `merchboard_collection_request_items`
 * (ADR-120). Storage only: the rules live in CollectionRequests. An open
 * request's designs carry `active_product_id` (unique), so a product never
 * has two open requests; closing clears the column. Nothing here touches a
 * product. The writes that belong together are run in one transaction by the
 * caller.
 */
class CollectionRequestRepository {

	private const COLUMNS = 'id, creator_id, kind, name, collection_id, token, status, note, requested_at, approved_at, closed_at';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * A request, or null.
	 *
	 * @phpstan-impure
	 *
	 * @param int $id Request.
	 * @throws DatabaseException When the read fails or the row is invalid.
	 */
	public function find( int $id ): ?CollectionRequest {
		$wpdb = $this->wpdb;

		return $this->requests( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', $this->table(), $id ) )[0] ?? null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * The open requests of all creators, oldest first.
	 *
	 * @return list<CollectionRequest>
	 * @throws DatabaseException When the read fails or a row is invalid.
	 */
	public function open(): array {
		$wpdb = $this->wpdb;

		return $this->requests( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE status IN ( %s, %s ) ORDER BY requested_at ASC, id ASC', $this->table(), CollectionRequestStatus::Pending->value, CollectionRequestStatus::Approved->value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * The latest closed requests, newest first.
	 *
	 * @param int $limit Most requests, 1–100.
	 * @return list<CollectionRequest>
	 * @throws DatabaseException When the read fails or a row is invalid.
	 */
	public function closed( int $limit = 50 ): array {
		$wpdb = $this->wpdb;

		return $this->requests( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE status IN ( %s, %s ) ORDER BY closed_at DESC, id DESC LIMIT %d', $this->table(), CollectionRequestStatus::Ready->value, CollectionRequestStatus::Declined->value, max( 1, min( 100, $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * A creator's latest requests, newest first.
	 *
	 * @param int $creator_id Creator.
	 * @param int $limit      Most requests, 1–100.
	 * @return list<CollectionRequest>
	 * @throws DatabaseException When the read fails or a row is invalid.
	 */
	public function for_creator( int $creator_id, int $limit = 50 ): array {
		$wpdb = $this->wpdb;

		return $this->requests( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d ORDER BY requested_at DESC, id DESC LIMIT %d', $this->table(), $creator_id, max( 1, min( 100, $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * The requests waiting for the administrator's approval.
	 *
	 * @throws DatabaseException When the read fails.
	 */
	public function pending_count(): int {
		$wpdb  = $this->wpdb;
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $this->table(), CollectionRequestStatus::Pending->value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, current state.

		if ( ! is_numeric( $count ) ) {
			throw new DatabaseException( 'Could not count the collection requests.' );
		}

		return (int) $count;
	}

	/**
	 * Which of the products are in an open request.
	 *
	 * @phpstan-impure
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param int[] $product_ids WooCommerce parent products.
	 * @return list<int>
	 * @throws DatabaseException When the read fails.
	 */
	public function requested_among( array $product_ids ): array {
		if ( array() === $product_ids ) {
			return array();
		}

		$wpdb  = $this->wpdb;
		$marks = implode( ', ', array_fill( 0, count( $product_ids ), '%d' ) );
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT active_product_id FROM %i WHERE active_product_id IN ( {$marks} )", $this->items(), ...$product_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $marks holds one %d per id; the plugin's own table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the requested products.' );
		}

		return array_map( 'intval', $found );
	}

	/**
	 * Stores a pending request with its designs (in the caller's transaction).
	 *
	 * @phpstan-param list<array{id: int, name: string}> $designs
	 *
	 * @param int                   $creator_id    Creator.
	 * @param CollectionRequestKind $kind          A new collection or an addition.
	 * @param string                $name          The collection's name.
	 * @param int|null              $collection_id The collection added to.
	 * @param string                $token         Marks the draft.
	 * @param array[]               $designs       Product and its name.
	 * @param int|null              $user          WordPress user.
	 * @param DateTimeImmutable     $now           When.
	 * @return int The request's id.
	 * @throws DatabaseException When it cannot be stored (also when the UNIQUE key refuses a design).
	 */
	public function insert( int $creator_id, CollectionRequestKind $kind, string $name, ?int $collection_id, string $token, array $designs, ?int $user, DateTimeImmutable $now ): int {
		$wpdb     = $this->wpdb;
		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'creator_id'    => $creator_id,
				'kind'          => $kind->value,
				'name'          => $name,
				'collection_id' => $collection_id,
				'token'         => $token,
				'status'        => CollectionRequestStatus::Pending->value,
				'requested_at'  => self::utc( $now ),
				'requested_by'  => $user,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d' )
		);

		if ( 1 !== $inserted || $wpdb->insert_id < 1 ) {
			throw new DatabaseException( 'Could not store the collection request.' );
		}

		$id = (int) $wpdb->insert_id;

		foreach ( $designs as $design ) {
			$stored = $wpdb->insert(
				$this->items(),
				array(
					'request_id'        => $id,
					'wc_product_id'     => $design['id'],
					'active_product_id' => $design['id'],
					'product_name'      => $design['name'],
				),
				array( '%d', '%d', '%d', '%s' )
			);

			if ( 1 !== $stored ) {
				throw new DatabaseException( 'Could not store the collection request\'s designs.' );
			}
		}

		return $id;
	}

	/**
	 * Approves a pending request.
	 *
	 * @param int               $id   Request.
	 * @param int|null          $user WordPress user.
	 * @param DateTimeImmutable $now  When.
	 * @return bool Whether a pending request was approved.
	 * @throws DatabaseException When the write fails.
	 */
	public function approve( int $id, ?int $user, DateTimeImmutable $now ): bool {
		$wpdb    = $this->wpdb;
		$updated = $wpdb->update(
			$this->table(),
			array(
				'status'      => CollectionRequestStatus::Approved->value,
				'approved_at' => self::utc( $now ),
				'approved_by' => $user,
			),
			array(
				'id'     => $id,
				'status' => CollectionRequestStatus::Pending->value,
			),
			array( '%s', '%s', '%d' ),
			array( '%d', '%s' )
		);

		if ( '' !== $wpdb->last_error || false === $updated ) {
			throw new DatabaseException( 'Could not approve the collection request.' );
		}

		return 1 === $updated;
	}

	/**
	 * Records the draft made for an approved request; only the first one wins.
	 *
	 * @param int $id            Request.
	 * @param int $collection_id The draft.
	 * @return bool Whether it was recorded.
	 * @throws DatabaseException When the write fails.
	 */
	public function set_collection( int $id, int $collection_id ): bool {
		$wpdb    = $this->wpdb;
		$sql     = $wpdb->prepare( 'UPDATE %i SET collection_id = %d WHERE id = %d AND status = %s AND collection_id IS NULL', $this->table(), $collection_id, $id, CollectionRequestStatus::Approved->value );
		$updated = is_string( $sql ) ? $wpdb->query( $sql ) : false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; the plugin's own table, conditional.

		if ( '' !== $wpdb->last_error || false === $updated ) {
			throw new DatabaseException( 'Could not record the collection.' );
		}

		return 1 === $updated;
	}

	/**
	 * Closes a request in one of the given states and frees its designs (in
	 * the caller's transaction).
	 *
	 * @param CollectionRequest       $request The request as read.
	 * @param CollectionRequestStatus $status  Ready or Declined.
	 * @param string|null             $note    Note to the creator.
	 * @param int|null                $user    WordPress user.
	 * @param DateTimeImmutable       $now     When.
	 * @return bool Whether it was closed: false when its state changed since it was read.
	 * @throws DatabaseException When the write fails.
	 */
	public function close( CollectionRequest $request, CollectionRequestStatus $status, ?string $note, ?int $user, DateTimeImmutable $now ): bool {
		$wpdb    = $this->wpdb;
		$updated = $wpdb->update(
			$this->table(),
			array(
				'status'    => $status->value,
				'note'      => $note,
				'closed_at' => self::utc( $now ),
				'closed_by' => $user,
			),
			array(
				'id'     => $request->id,
				'status' => $request->status->value,
			),
			array( '%s', '%s', '%s', '%d' ),
			array( '%d', '%s' )
		);

		if ( '' !== $wpdb->last_error || false === $updated ) {
			throw new DatabaseException( 'Could not close the collection request.' );
		}

		if ( 1 !== $updated ) {
			return false;
		}

		$sql   = $wpdb->prepare( 'UPDATE %i SET active_product_id = NULL WHERE request_id = %d', $this->items(), $request->id );
		$freed = is_string( $sql ) ? $wpdb->query( $sql ) : false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; the plugin's own table.

		if ( false === $freed ) {
			throw new DatabaseException( 'Could not free the collection request\'s designs.' );
		}

		return true;
	}

	/**
	 * Deletes a creator's requests (the creator's deletion).
	 *
	 * @param int $creator_id Creator.
	 * @return int The requests deleted.
	 * @throws DatabaseException When a write fails.
	 */
	public function delete_for( int $creator_id ): int {
		$wpdb  = $this->wpdb;
		$sql   = $wpdb->prepare( 'DELETE FROM %i WHERE request_id IN ( SELECT id FROM %i WHERE creator_id = %d )', $this->items(), $this->table(), $creator_id );
		$items = is_string( $sql ) ? $wpdb->query( $sql ) : false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; the plugin's own tables.

		if ( false === $items ) {
			throw new DatabaseException( 'Could not delete the collection requests\' designs.' );
		}

		$deleted = $wpdb->delete( $this->table(), array( 'creator_id' => $creator_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.

		if ( false === $deleted ) {
			throw new DatabaseException( 'Could not delete the collection requests.' );
		}

		return (int) $deleted;
	}

	/**
	 * Rows of a prepared query as requests with their designs.
	 *
	 * @param string|null $sql Prepared query.
	 * @return list<CollectionRequest>
	 * @throws DatabaseException When the read fails or a row is invalid.
	 */
	private function requests( ?string $sql ): array {
		$wpdb = $this->wpdb;
		$rows = null === $sql ? null : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; the plugin's own table, current state.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the collection requests.' );
		}

		$designs  = $this->designs( array_map( static fn( array $row ): int => (int) $row['id'], $rows ) );
		$requests = array();
		$utc      = new DateTimeZone( 'UTC' );

		foreach ( $rows as $row ) {
			$status = CollectionRequestStatus::tryFrom( (string) $row['status'] );
			$kind   = CollectionRequestKind::tryFrom( (string) $row['kind'] );

			if ( null === $status || null === $kind ) {
				throw new DatabaseException( 'Invalid collection request.' );
			}

			try {
				$requests[] = new CollectionRequest(
					(int) $row['id'],
					(int) $row['creator_id'],
					$kind,
					(string) $row['name'],
					null === $row['collection_id'] ? null : (int) $row['collection_id'],
					(string) $row['token'],
					$status,
					null === $row['note'] ? null : (string) $row['note'],
					$designs[ (int) $row['id'] ] ?? array(),
					new DateTimeImmutable( (string) $row['requested_at'], $utc ),
					null === $row['approved_at'] ? null : new DateTimeImmutable( (string) $row['approved_at'], $utc ),
					null === $row['closed_at'] ? null : new DateTimeImmutable( (string) $row['closed_at'], $utc )
				);
			} catch ( Exception ) {
				throw new DatabaseException( 'Invalid collection request.' );
			}
		}

		return $requests;
	}

	/**
	 * The designs of requests, in the order they were chosen.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @param int[] $ids Requests.
	 * @return array<int, list<array{id: int, name: string}>>
	 * @throws DatabaseException When the read fails.
	 */
	private function designs( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$wpdb  = $this->wpdb;
		$marks = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT request_id, wc_product_id, product_name FROM %i WHERE request_id IN ( {$marks} ) ORDER BY id ASC", $this->items(), ...$ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $marks holds one %d per id; the plugin's own table.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the collection requests\' designs.' );
		}

		$designs = array();

		foreach ( $rows as $row ) {
			$designs[ (int) $row['request_id'] ][] = array(
				'id'   => (int) $row['wc_product_id'],
				'name' => (string) $row['product_name'],
			);
		}

		return $designs;
	}

	/**
	 * A time as stored (UTC).
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function utc( DateTimeImmutable $time ): string {
		return $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * The requests' table.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::COLLECTION_REQUESTS );
	}

	/**
	 * The designs' table.
	 */
	private function items(): string {
		return Tables::name( $this->wpdb, Tables::COLLECTION_REQUEST_ITEMS );
	}
}
