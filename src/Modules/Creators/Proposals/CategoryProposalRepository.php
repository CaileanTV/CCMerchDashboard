<?php
/**
 * Category proposal storage.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Proposals;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_category_proposals` (ADR-077). Storage only: the rules live in
 * CategoryProposals. A pending row carries `pending_key` ("{creator}:{field}",
 * unique), so a creator never has two pending proposals for one field; a
 * decision clears it.
 */
final class CategoryProposalRepository {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * A proposal, or null.
	 *
	 * @param int $id Proposal id.
	 * @throws DatabaseException When the query fails or the row is invalid.
	 */
	public function find( int $id ): ?CategoryProposal {
		if ( $id < 1 ) {
			return null;
		}

		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT id, creator_id, field, value, status, reason, created_at, created_by, decided_at, decided_by FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, current state.

		if ( ! is_array( $row ) ) {
			$this->assert_read_succeeded();

			return null;
		}

		return self::hydrate( $row );
	}

	/**
	 * A creator's pending proposals by field.
	 *
	 * @param int $creator_id Creator.
	 * @return array<string, CategoryProposal>
	 * @throws DatabaseException When the query fails or a row is invalid.
	 */
	public function pending_for( int $creator_id ): array {
		$pending = array();

		foreach ( $this->rows( $this->wpdb->prepare( 'SELECT id, creator_id, field, value, status, reason, created_at, created_by, decided_at, decided_by FROM %i WHERE creator_id = %d AND status = %s ORDER BY id ASC', $this->table(), $creator_id, ProposalStatus::Pending->value ) ) as $proposal ) {
			$pending[ $proposal->field->value ] = $proposal;
		}

		return $pending;
	}

	/**
	 * A creator's latest rejection per field that no later proposal followed
	 * (what the creator should see: "rejected, with this note").
	 *
	 * @param int $creator_id Creator.
	 * @return array<string, CategoryProposal>
	 * @throws DatabaseException When the query fails or a row is invalid.
	 */
	public function rejected_for( int $creator_id ): array {
		$latest = array();

		// Every non-replaced proposal of the creator, oldest first: the last one per field wins.
		foreach ( $this->rows( $this->wpdb->prepare( 'SELECT id, creator_id, field, value, status, reason, created_at, created_by, decided_at, decided_by FROM %i WHERE creator_id = %d AND status <> %s ORDER BY id ASC', $this->table(), $creator_id, ProposalStatus::Replaced->value ) ) as $proposal ) {
			$latest[ $proposal->field->value ] = $proposal;
		}

		return array_filter( $latest, static fn( CategoryProposal $proposal ): bool => ProposalStatus::Rejected === $proposal->status );
	}

	/**
	 * Creators with pending proposals: how many and when the oldest was
	 * made, oldest first.
	 *
	 * @return list<array{creator_id: int, count: int, oldest: string}>
	 * @throws DatabaseException When the query fails.
	 */
	public function pending_creators(): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT creator_id, COUNT(*) AS proposals, MIN(created_at) AS oldest FROM %i WHERE status = %s GROUP BY creator_id ORDER BY oldest ASC, creator_id ASC', $this->table(), ProposalStatus::Pending->value ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, current state.

		if ( ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the category proposals.' );
		}

		return array_map(
			static fn( array $row ): array => array(
				'creator_id' => (int) $row['creator_id'],
				'count'      => (int) $row['proposals'],
				'oldest'     => (string) $row['oldest'],
			),
			$rows
		);
	}

	/**
	 * Pending proposals of all creators.
	 *
	 * @throws DatabaseException When the query fails.
	 */
	public function pending_count(): int {
		$wpdb  = $this->wpdb;
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $this->table(), ProposalStatus::Pending->value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, current state.

		if ( ! is_numeric( $count ) ) {
			throw new DatabaseException( 'Could not count the category proposals.' );
		}

		return (int) $count;
	}

	/**
	 * Every proposal of a creator (its deletion).
	 *
	 * @param int $creator_id Creator.
	 * @return list<CategoryProposal>
	 * @throws DatabaseException When the query fails or a row is invalid.
	 */
	public function all_for( int $creator_id ): array {
		return $this->rows( $this->wpdb->prepare( 'SELECT id, creator_id, field, value, status, reason, created_at, created_by, decided_at, decided_by FROM %i WHERE creator_id = %d ORDER BY id ASC', $this->table(), $creator_id ) );
	}

	/**
	 * Stores a pending proposal.
	 *
	 * @param int               $creator_id Creator.
	 * @param ProposalField     $field      Field.
	 * @param string            $value      Value.
	 * @param int|null          $created_by Who proposed it.
	 * @param DateTimeImmutable $now        Now (UTC).
	 * @return int Proposal id.
	 * @throws DatabaseException When it cannot be stored (e.g. another one is pending for the field).
	 */
	public function insert( int $creator_id, ProposalField $field, string $value, ?int $created_by, DateTimeImmutable $now ): int {
		$inserted = $this->wpdb->insert(
			$this->table(),
			array(
				'creator_id'  => $creator_id,
				'field'       => $field->value,
				'value'       => $value,
				'status'      => ProposalStatus::Pending->value,
				'pending_key' => self::pending_key( $creator_id, $field ),
				'created_at'  => $now->format( 'Y-m-d H:i:s' ),
				'created_by'  => null !== $created_by && $created_by > 0 ? $created_by : null,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( 1 !== $inserted || $this->wpdb->insert_id < 1 ) {
			throw new DatabaseException( 'Could not store the category proposal.' );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Ends a pending proposal (approved, rejected, replaced or withdrawn).
	 *
	 * @param int               $id         Proposal id.
	 * @param ProposalStatus    $status     New state (not pending).
	 * @param string|null       $reason     Note on a rejection.
	 * @param int|null          $decided_by Who ended it.
	 * @param DateTimeImmutable $now        Now (UTC).
	 * @return bool False when it was not pending (any longer).
	 * @throws DatabaseException When the update fails.
	 */
	public function end( int $id, ProposalStatus $status, ?string $reason, ?int $decided_by, DateTimeImmutable $now ): bool {
		// wpdb writes null as NULL.
		$updated = $this->wpdb->update(
			$this->table(),
			array(
				'status'      => $status->value,
				'pending_key' => null,
				'reason'      => null !== $reason && '' !== $reason ? $reason : null,
				'decided_at'  => $now->format( 'Y-m-d H:i:s' ),
				'decided_by'  => null !== $decided_by && $decided_by > 0 ? $decided_by : null,
			),
			array(
				'id'     => $id,
				'status' => ProposalStatus::Pending->value,
			),
			array( '%s', '%s', '%s', '%s', '%d' ),
			array( '%d', '%s' )
		);

		if ( false === $updated || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not update the category proposal.' );
		}

		return 1 === $updated;
	}

	/**
	 * Deletes every proposal of a creator.
	 *
	 * @param int $creator_id Creator.
	 * @return int Rows deleted.
	 * @throws DatabaseException When they cannot be deleted.
	 */
	public function delete_for( int $creator_id ): int {
		$deleted = $this->wpdb->delete( $this->table(), array( 'creator_id' => $creator_id ), array( '%d' ) );

		if ( false === $deleted ) {
			throw new DatabaseException( 'Could not delete the category proposals.' );
		}

		return $deleted;
	}

	/**
	 * The unique key of a pending proposal.
	 *
	 * @param int           $creator_id Creator.
	 * @param ProposalField $field      Field.
	 */
	private static function pending_key( int $creator_id, ProposalField $field ): string {
		return $creator_id . ':' . $field->value;
	}

	/**
	 * Proposals of a prepared query.
	 *
	 * @param string|null $sql Prepared query.
	 * @return list<CategoryProposal>
	 * @throws DatabaseException When the query fails or a row is invalid.
	 */
	private function rows( ?string $sql ): array {
		$rows = null === $sql ? null : $this->wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the callers; the plugin's own table, current state.

		if ( ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the category proposals.' );
		}

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::CATEGORY_PROPOSALS );
	}

	/**
	 * Requires the last read to have succeeded.
	 *
	 * @throws DatabaseException When it failed.
	 */
	private function assert_read_succeeded(): void {
		if ( '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the category proposal.' );
		}
	}

	/**
	 * A proposal from a row, validating every column.
	 *
	 * @param array<string, mixed> $row Row.
	 * @throws DatabaseException When the row does not match the schema.
	 */
	private static function hydrate( array $row ): CategoryProposal {
		$field  = ProposalField::tryFrom( (string) ( $row['field'] ?? '' ) );
		$status = ProposalStatus::tryFrom( (string) ( $row['status'] ?? '' ) );

		if ( null === $field || null === $status || ! is_numeric( $row['id'] ?? null ) || ! is_numeric( $row['creator_id'] ?? null ) || ! is_string( $row['value'] ?? null ) ) {
			throw new DatabaseException( 'Invalid category proposal row.' );
		}

		return new CategoryProposal(
			(int) $row['id'],
			(int) $row['creator_id'],
			$field,
			$row['value'],
			$status,
			is_string( $row['reason'] ?? null ) && '' !== $row['reason'] ? $row['reason'] : null,
			self::time( $row['created_at'] ?? null ) ?? throw new DatabaseException( 'Invalid category proposal row.' ),
			is_numeric( $row['created_by'] ?? null ) ? (int) $row['created_by'] : null,
			self::time( $row['decided_at'] ?? null ),
			is_numeric( $row['decided_by'] ?? null ) ? (int) $row['decided_by'] : null
		);
	}

	/**
	 * A stored UTC time, or null.
	 *
	 * @param mixed $value Column value.
	 */
	private static function time( mixed $value ): ?DateTimeImmutable {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
		} catch ( Exception ) {
			return null;
		}
	}
}
