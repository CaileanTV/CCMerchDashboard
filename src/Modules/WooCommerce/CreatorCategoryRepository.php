<?php
/**
 * Storage of the creator ↔ product category mapping.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes `merchboard_creator_categories` (ADR-033). Storage only:
 * no authorization, validation of creators or terms, or audit; that is
 * CreatorCategoryService, which runs the writes in a transaction.
 *
 * - The primary key on `term_id` guarantees one creator per category in the
 *   database; a duplicate insert fails and is reported.
 * - Removal matches term and creator, so a mapping of another creator is
 *   never removed by mistake.
 * - Database errors surface as DatabaseException with a generic message.
 */
final class CreatorCategoryRepository implements MappingLookup {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * The mapping of a category, if any.
	 *
	 * @param int $term_id `product_cat` term id.
	 * @throws InvalidArgumentException When the id is not positive.
	 * @throws DatabaseException When the query fails or the stored row is invalid.
	 */
	public function find( int $term_id ): ?CreatorCategory {
		self::assert_positive_id( $term_id, 'Term id' );

		// Local $wpdb so WPCS can verify the query is prepared.
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT term_id, creator_id, created_at, created_by FROM %i WHERE term_id = %d', $this->table(), $term_id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			$this->assert_read_succeeded();

			return null;
		}

		return self::hydrate( $row );
	}

	/**
	 * The categories mapped to a creator, by term id.
	 *
	 * @param int $creator_id Creator id.
	 * @return list<CreatorCategory>
	 * @throws DatabaseException When the query fails or a stored row is invalid (InvalidArgumentException for a non-positive id).
	 */
	public function for_creator( int $creator_id ): array {
		self::assert_positive_id( $creator_id, 'Creator id' );

		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT term_id, creator_id, created_at, created_by FROM %i WHERE creator_id = %d ORDER BY term_id ASC', $this->table(), $creator_id ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read creator categories.' );
		}

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Every mapping, by term id (a small table: at most one per category).
	 *
	 * @return list<CreatorCategory>
	 * @throws DatabaseException When the query fails or a stored row is invalid.
	 */
	public function all(): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT term_id, creator_id, created_at, created_by FROM %i ORDER BY term_id ASC', $this->table() ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read creator categories.' );
		}

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Stores a new mapping.
	 *
	 * @param int      $term_id    `product_cat` term id.
	 * @param int      $creator_id Creator id.
	 * @param int|null $created_by WordPress user id, or null (no user).
	 * @throws DatabaseException When the row cannot be stored, e.g. the category is already mapped (InvalidArgumentException for a non-positive id).
	 */
	public function insert( int $term_id, int $creator_id, ?int $created_by ): CreatorCategory {
		self::assert_positive_id( $term_id, 'Term id' );
		self::assert_positive_id( $creator_id, 'Creator id' );

		$inserted = $this->wpdb->insert(
			$this->table(),
			array(
				'term_id'    => $term_id,
				'creator_id' => $creator_id,
				'created_at' => current_time( 'mysql', true ),
				'created_by' => null !== $created_by && $created_by > 0 ? $created_by : null,
			),
			array( '%d', '%d', '%s', '%d' )
		);

		if ( 1 !== $inserted ) {
			throw new DatabaseException( 'Could not store the creator category.' );
		}

		$mapping = $this->find( $term_id );

		if ( null === $mapping || $mapping->creator_id !== $creator_id ) {
			throw new DatabaseException( 'Stored creator category could not be read back.' );
		}

		return $mapping;
	}

	/**
	 * Removes the mapping of a category to exactly this creator.
	 *
	 * @param int $term_id    `product_cat` term id.
	 * @param int $creator_id Creator id.
	 * @throws DatabaseException When the row cannot be removed or was not there (InvalidArgumentException for a non-positive id).
	 */
	public function delete( int $term_id, int $creator_id ): void {
		self::assert_positive_id( $term_id, 'Term id' );
		self::assert_positive_id( $creator_id, 'Creator id' );

		$deleted = $this->wpdb->delete(
			$this->table(),
			array(
				'term_id'    => $term_id,
				'creator_id' => $creator_id,
			),
			array( '%d', '%d' )
		);

		if ( 1 !== $deleted ) {
			throw new DatabaseException( 'Could not remove the creator category.' );
		}
	}

	/**
	 * Full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::CREATOR_CATEGORIES );
	}

	/**
	 * Requires the last read query to have succeeded (wpdb resets last_error
	 * on every query).
	 *
	 * @throws DatabaseException When it failed.
	 */
	private function assert_read_succeeded(): void {
		if ( '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not read creator category.' );
		}
	}

	/**
	 * Builds a mapping from a database row, validating every column.
	 *
	 * @param array<string, mixed> $row Row.
	 * @throws DatabaseException When the row does not match the schema.
	 */
	private static function hydrate( array $row ): CreatorCategory {
		$term_id    = $row['term_id'] ?? null;
		$creator_id = $row['creator_id'] ?? null;
		$created_by = $row['created_by'] ?? null;
		$created_at = is_string( $row['created_at'] ?? null )
			? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $row['created_at'], new DateTimeZone( 'UTC' ) )
			: false;

		if ( ! is_numeric( $term_id ) || (int) $term_id < 1 || ! is_numeric( $creator_id ) || (int) $creator_id < 1 || false === $created_at ) {
			throw new DatabaseException( 'Invalid creator category row.' );
		}

		return new CreatorCategory( (int) $term_id, (int) $creator_id, $created_at, is_numeric( $created_by ) ? (int) $created_by : null );
	}

	/**
	 * Rejects ids that cannot exist.
	 *
	 * @param int    $id    Id to check.
	 * @param string $label Name for the message.
	 * @throws InvalidArgumentException When not positive.
	 */
	private static function assert_positive_id( int $id, string $label ): void {
		if ( $id < 1 ) {
			throw new InvalidArgumentException( $label . ' must be a positive integer.' );
		}
	}
}
