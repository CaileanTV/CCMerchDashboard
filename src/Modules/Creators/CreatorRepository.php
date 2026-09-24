<?php
/**
 * Data access for creators.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes `{prefix}merchboard_creators`.
 *
 * Security contract:
 * - Performs no authorization. Callers must check capabilities or resolve the
 *   current creator (CreatorContext, roadmap phase 8) before calling it with
 *   anything derived from a request.
 * - Every query is prepared; the table name comes from Tables, never from input.
 * - Columns are listed explicitly so new columns are never exposed by accident.
 * - Ids are validated here as well, so a missing check upstream fails loudly
 *   instead of querying with 0 or a negative id.
 * - There is deliberately no arbitrary filter or "update anything" method:
 *   each write is an explicit operation (rename, set_status, link_user,
 *   unlink_user, release_user) and the only list is paginate() with
 *   whitelisted sorting.
 *   There is no delete (creators are deactivated; see docs/DATABASE.md).
 * - Database errors surface as DatabaseException with a generic message.
 */
final class CreatorRepository {

	/**
	 * Maximum display name length (column is VARCHAR(191)).
	 */
	public const DISPLAY_NAME_MAX_LENGTH = 191;

	/**
	 * Columns the admin list may sort by.
	 */
	public const SORTABLE_COLUMNS = array( 'display_name', 'status', 'created_at', 'updated_at', 'id' );

	/**
	 * Maximum page size.
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Finds a creator by its creator id.
	 *
	 * @param int $id Creator id.
	 * @throws InvalidArgumentException When the id is not positive.
	 * @throws DatabaseException When the stored row is invalid.
	 */
	public function find( int $id ): ?Creator {
		self::assert_positive_id( $id, 'Creator id' );

		// Local $wpdb so WPCS can verify the query is prepared.
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, user_id, display_name, status, created_at, updated_at FROM %i WHERE id = %d', $this->table(), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Finds the creator linked to a WordPress user.
	 *
	 * @param int $user_id WordPress user id.
	 * @throws InvalidArgumentException When the id is not positive.
	 * @throws DatabaseException When the stored row is invalid.
	 */
	public function find_by_user_id( int $user_id ): ?Creator {
		self::assert_positive_id( $user_id, 'User id' );

		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, user_id, display_name, status, created_at, updated_at FROM %i WHERE user_id = %d', $this->table(), $user_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Creates a creator. The creator id is assigned by the database and is
	 * independent of the WordPress user id.
	 *
	 * Business rules (who may create creators, role assignment, audit) belong to
	 * CreatorService; this method only guards data integrity.
	 *
	 * @param string        $display_name Display name (stored as given, escaped on output).
	 * @param CreatorStatus $status       Initial status.
	 * @param int|null      $user_id      WordPress user to link, or null.
	 * @throws InvalidArgumentException|DatabaseException On invalid input (column constraints) or when the insert fails, e.g. the user is already linked.
	 */
	public function create( string $display_name, CreatorStatus $status, ?int $user_id = null ): Creator {
		$display_name = self::normalize_display_name( $display_name );

		if ( null !== $user_id ) {
			self::assert_positive_id( $user_id, 'User id' );
		}

		$now = current_time( 'mysql', true );

		$inserted = $this->wpdb->insert(
			$this->table(),
			array(
				'user_id'      => $user_id,
				'display_name' => $display_name,
				'status'       => $status->value,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw new DatabaseException( 'Could not create creator.' );
		}

		$creator = $this->find( (int) $this->wpdb->insert_id );

		if ( null === $creator ) {
			throw new DatabaseException( 'Created creator could not be read back.' );
		}

		return $creator;
	}

	/**
	 * Changes the display name.
	 *
	 * @param int    $id           Creator id.
	 * @param string $display_name New display name.
	 * @throws InvalidArgumentException When the id or name is invalid.
	 * @throws DatabaseException When the creator does not exist or the update fails.
	 */
	public function rename( int $id, string $display_name ): Creator {
		return $this->update( $id, array( 'display_name' => self::normalize_display_name( $display_name ) ), array( '%s' ) );
	}

	/**
	 * Changes the status.
	 *
	 * @param int           $id     Creator id.
	 * @param CreatorStatus $status New status.
	 * @throws InvalidArgumentException When the id is invalid.
	 * @throws DatabaseException When the creator does not exist or the update fails.
	 */
	public function set_status( int $id, CreatorStatus $status ): Creator {
		return $this->update( $id, array( 'status' => $status->value ), array( '%s' ) );
	}

	/**
	 * Links a WordPress user. The UNIQUE index on user_id rejects a user that is
	 * already linked to another creator.
	 *
	 * @param int $id      Creator id.
	 * @param int $user_id WordPress user id.
	 * @throws InvalidArgumentException When an id is invalid.
	 * @throws DatabaseException When the creator does not exist or the update fails.
	 */
	public function link_user( int $id, int $user_id ): Creator {
		self::assert_positive_id( $user_id, 'User id' );

		return $this->update( $id, array( 'user_id' => $user_id ), array( '%d' ) );
	}

	/**
	 * Removes the WordPress user link. Neither the user nor the creator is deleted.
	 *
	 * @param int $id Creator id.
	 * @throws InvalidArgumentException When the id is invalid.
	 * @throws DatabaseException When the creator does not exist or the update fails.
	 */
	public function unlink_user( int $id ): Creator {
		return $this->update( $id, array( 'user_id' => null ), array( '%d' ) );
	}

	/**
	 * Removes the user link and deactivates the creator in one statement, but
	 * only while the creator is still linked to that user. Used when the
	 * WordPress user was deleted: if an administrator re-linked the creator in
	 * the meantime, nothing changes.
	 *
	 * @param int $id      Creator id.
	 * @param int $user_id User id the creator must still be linked to.
	 * @return bool Whether the creator was released.
	 * @throws InvalidArgumentException|DatabaseException When an id is invalid or the update fails.
	 */
	public function release_user( int $id, int $user_id ): bool {
		self::assert_positive_id( $id, 'Creator id' );
		self::assert_positive_id( $user_id, 'User id' );

		$updated = $this->wpdb->update(
			$this->table(),
			array(
				'user_id'    => null,
				'status'     => CreatorStatus::Inactive->value,
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%d', '%s', '%s' ),
			array( '%d', '%d' )
		);

		if ( false === $updated ) {
			throw new DatabaseException( 'Could not release creator user.' );
		}

		return 1 === $updated;
	}

	/**
	 * One page of creators for the admin list.
	 *
	 * @param int                $page     Page number (1-based).
	 * @param int                $per_page Items per page (1-100).
	 * @param CreatorStatus|null $status   Optional status filter.
	 * @param string             $orderby  Column; anything outside the whitelist falls back to created_at.
	 * @param string             $order    'asc' or 'desc' (anything else: desc).
	 * @return array{items: list<Creator>, total: int}
	 * @throws DatabaseException When a stored row is invalid.
	 */
	public function paginate( int $page, int $per_page, ?CreatorStatus $status = null, string $orderby = 'created_at', string $order = 'desc' ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$orderby  = in_array( $orderby, self::SORTABLE_COLUMNS, true ) ? $orderby : 'created_at';
		$offset   = ( $page - 1 ) * $per_page;
		$wpdb     = $this->wpdb;
		$table    = $this->table();

		// ORDER BY direction cannot be a placeholder, so it is one of two literals.
		// The column goes through %i and is whitelisted above.
		$direction = 'asc' === strtolower( $order ) ? 'ASC' : 'DESC';

		if ( null === $status ) {
			$total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, display_name, status, created_at, updated_at FROM %i ORDER BY %i {$direction}, id {$direction} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $direction is the literal ASC or DESC.
					$table,
					$orderby,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			$total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $table, $status->value ) );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, display_name, status, created_at, updated_at FROM %i WHERE status = %s ORDER BY %i {$direction}, id {$direction} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $direction is the literal ASC or DESC.
					$table,
					$status->value,
					$orderby,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return array(
			'items' => array_map( array( $this, 'hydrate' ), is_array( $rows ) ? $rows : array() ),
			'total' => is_numeric( $total ) ? (int) $total : 0,
		);
	}

	/**
	 * WordPress user ids that are linked to any creator.
	 *
	 * @return list<int>
	 */
	public function linked_user_ids(): array {
		$wpdb = $this->wpdb;
		$ids  = $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM %i WHERE user_id IS NOT NULL', $this->table() ) );

		return array_map( 'intval', $ids );
	}

	/**
	 * Updates columns of one creator and refreshes updated_at.
	 *
	 * @param int                  $id      Creator id.
	 * @param array<string, mixed> $data    Column values.
	 * @param string[]             $formats Formats for $data.
	 * @throws InvalidArgumentException|DatabaseException On an invalid id, or when the creator does not exist or the update fails.
	 */
	private function update( int $id, array $data, array $formats ): Creator {
		self::assert_positive_id( $id, 'Creator id' );

		$data['updated_at'] = current_time( 'mysql', true );
		$formats[]          = '%s';

		$updated = $this->wpdb->update( $this->table(), $data, array( 'id' => $id ), $formats, array( '%d' ) );

		if ( false === $updated ) {
			throw new DatabaseException( 'Could not update creator.' );
		}

		$creator = $this->find( $id );

		if ( null === $creator ) {
			throw new DatabaseException( 'Creator to update does not exist.' );
		}

		return $creator;
	}

	/**
	 * Trims and validates a display name.
	 *
	 * @param string $display_name Display name.
	 * @throws InvalidArgumentException When empty or longer than the column.
	 */
	public static function normalize_display_name( string $display_name ): string {
		$display_name = trim( $display_name );

		if ( '' === $display_name || mb_strlen( $display_name ) > self::DISPLAY_NAME_MAX_LENGTH ) {
			throw new InvalidArgumentException( 'Display name must be 1-191 characters.' );
		}

		return $display_name;
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::CREATORS );
	}

	/**
	 * Builds a Creator from a database row, validating every column.
	 *
	 * @param array<string, mixed> $row Row from get_row( …, ARRAY_A ).
	 * @throws DatabaseException When the row does not match the schema.
	 */
	private function hydrate( array $row ): Creator {
		$status = CreatorStatus::tryFrom( is_string( $row['status'] ?? null ) ? $row['status'] : '' );

		if ( null === $status || ! is_numeric( $row['id'] ?? null ) || ! is_string( $row['display_name'] ?? null ) ) {
			throw new DatabaseException( 'Invalid creator row.' );
		}

		$user_id = $row['user_id'] ?? null;

		return new Creator(
			(int) $row['id'],
			is_numeric( $user_id ) ? (int) $user_id : null,
			$row['display_name'],
			$status,
			self::utc( $row['created_at'] ?? null ),
			self::utc( $row['updated_at'] ?? null )
		);
	}

	/**
	 * Parses a stored UTC DATETIME value.
	 *
	 * @param mixed $value Column value.
	 * @throws DatabaseException When the value is not a valid DATETIME.
	 */
	private static function utc( mixed $value ): DateTimeImmutable {
		$date = is_string( $value )
			? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) )
			: false;

		if ( false === $date ) {
			throw new DatabaseException( 'Invalid creator timestamp.' );
		}

		return $date;
	}

	/**
	 * Rejects ids that cannot exist.
	 *
	 * @param int    $id    Id to check.
	 * @param string $label Name used in the exception message.
	 * @throws InvalidArgumentException When the id is not positive.
	 */
	private static function assert_positive_id( int $id, string $label ): void {
		if ( $id < 1 ) {
			throw new InvalidArgumentException( $label . ' must be a positive integer.' );
		}
	}
}
