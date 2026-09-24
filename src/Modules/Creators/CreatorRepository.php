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
 * - There is deliberately no "find all" / arbitrary filter method; lists are
 *   added with explicit scoping when a phase needs them.
 * - Database errors surface as DatabaseException with a generic message.
 */
final class CreatorRepository {

	/**
	 * Maximum display name length (column is VARCHAR(191)).
	 */
	public const DISPLAY_NAME_MAX_LENGTH = 191;

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
	 * Business rules (who may create creators, role assignment, events) belong to
	 * CreatorService in roadmap phase 6; this method only guards data integrity.
	 *
	 * @param string        $display_name Display name (stored as given, escaped on output).
	 * @param CreatorStatus $status       Initial status.
	 * @param int|null      $user_id      WordPress user to link, or null.
	 * @throws InvalidArgumentException When the input violates column constraints.
	 * @throws DatabaseException When the insert fails, e.g. the user is already linked.
	 */
	public function create( string $display_name, CreatorStatus $status, ?int $user_id = null ): Creator {
		$display_name = trim( $display_name );

		if ( '' === $display_name || mb_strlen( $display_name ) > self::DISPLAY_NAME_MAX_LENGTH ) {
			throw new InvalidArgumentException( 'Display name must be 1-191 characters.' );
		}

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
			throw new InvalidArgumentException( $label . ' must be a positive integer.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $label is a literal from this class; exception messages are never rendered.
		}
	}
}
