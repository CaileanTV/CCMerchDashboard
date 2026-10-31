<?php
/**
 * Storage of the requests to remove a legacy product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\ProductRemovals;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_product_removal_requests` (ADR-083). Storage only: the rules
 * live in ProductRemovals. A pending row carries `active_product_id`
 * (unique), so a product never has two pending requests; closing it clears
 * the column. Nothing here touches a product.
 */
final class ProductRemovalRepository {

	private const COLUMNS = 'id, creator_id, wc_product_id, product_name, status, note, requested_at, requested_by, handled_at, handled_by';

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
	public function find( int $id ): ?ProductRemovalRequest {
		$wpdb = $this->wpdb;

		return $this->requests( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', $this->table(), $id ) )[0] ?? null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * The pending request of a product, or null.
	 *
	 * @phpstan-impure
	 *
	 * @param int $product_id WooCommerce parent product.
	 * @throws DatabaseException When the read fails or the row is invalid.
	 */
	public function pending_for_product( int $product_id ): ?ProductRemovalRequest {
		$wpdb = $this->wpdb;

		return $this->requests( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE active_product_id = %d', $this->table(), $product_id ) )[0] ?? null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * Pending requests, oldest first.
	 *
	 * @return list<ProductRemovalRequest>
	 * @throws DatabaseException When the read fails or a row is invalid.
	 */
	public function pending(): array {
		$wpdb = $this->wpdb;

		return $this->requests( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE status = %s ORDER BY requested_at ASC, id ASC', $this->table(), ProductRemovalStatus::Pending->value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * The latest closed requests, newest first.
	 *
	 * @param int $limit Most requests, 1–100.
	 * @return list<ProductRemovalRequest>
	 * @throws DatabaseException When the read fails or a row is invalid.
	 */
	public function closed( int $limit = 50 ): array {
		$wpdb = $this->wpdb;

		return $this->requests( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE status <> %s ORDER BY handled_at DESC, id DESC LIMIT %d', $this->table(), ProductRemovalStatus::Pending->value, max( 1, min( 100, $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * Pending requests of all creators.
	 *
	 * @throws DatabaseException When the read fails.
	 */
	public function pending_count(): int {
		$wpdb  = $this->wpdb;
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $this->table(), ProductRemovalStatus::Pending->value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, current state.

		if ( ! is_numeric( $count ) ) {
			throw new DatabaseException( 'Could not count the product removal requests.' );
		}

		return (int) $count;
	}

	/**
	 * Which of the products have a pending request.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param int[] $product_ids WooCommerce parent products.
	 * @return list<int>
	 * @throws DatabaseException When the read fails.
	 */
	public function pending_among( array $product_ids ): array {
		return $this->ids_among( $product_ids, Tables::PRODUCT_REMOVAL_REQUESTS, 'active_product_id' );
	}

	/**
	 * Which of the products a designer row names. It only decides what the
	 * dashboard offers; a request is checked by DesignerProductGuard.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param int[] $product_ids WooCommerce parent products.
	 * @return list<int>
	 * @throws DatabaseException When the read fails.
	 */
	public function designer_among( array $product_ids ): array {
		return $this->ids_among( $product_ids, Tables::DESIGNER_PRODUCTS, 'wc_product_id' );
	}

	/**
	 * Stores a pending request.
	 *
	 * @param int               $creator_id Creator.
	 * @param int               $product_id WooCommerce parent product.
	 * @param string            $name       The product's name.
	 * @param int|null          $user       WordPress user.
	 * @param DateTimeImmutable $now        When.
	 * @return int The request's id.
	 * @throws DatabaseException When it cannot be stored (also when the UNIQUE key refuses it).
	 */
	public function insert( int $creator_id, int $product_id, string $name, ?int $user, DateTimeImmutable $now ): int {
		$wpdb     = $this->wpdb;
		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'creator_id'        => $creator_id,
				'wc_product_id'     => $product_id,
				'active_product_id' => $product_id,
				'product_name'      => $name,
				'status'            => ProductRemovalStatus::Pending->value,
				'requested_at'      => self::utc( $now ),
				'requested_by'      => $user,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%d' )
		);

		if ( 1 !== $inserted || $wpdb->insert_id < 1 ) {
			throw new DatabaseException( 'Could not store the product removal request.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Closes a pending request.
	 *
	 * @param int                  $id     Request.
	 * @param ProductRemovalStatus $status Completed or Declined.
	 * @param string|null          $note   Note to the creator.
	 * @param int|null             $user   WordPress user.
	 * @param DateTimeImmutable    $now    When.
	 * @return bool Whether a pending request was closed.
	 * @throws DatabaseException When the write fails.
	 */
	public function close( int $id, ProductRemovalStatus $status, ?string $note, ?int $user, DateTimeImmutable $now ): bool {
		$wpdb    = $this->wpdb;
		$updated = $wpdb->update(
			$this->table(),
			array(
				'status'            => $status->value,
				'active_product_id' => null,
				'note'              => $note,
				'handled_at'        => self::utc( $now ),
				'handled_by'        => $user,
			),
			array(
				'id'     => $id,
				'status' => ProductRemovalStatus::Pending->value,
			),
			array( '%s', '%d', '%s', '%s', '%d' ),
			array( '%d', '%s' )
		);

		if ( '' !== $wpdb->last_error || false === $updated ) {
			throw new DatabaseException( 'Could not close the product removal request.' );
		}

		return 1 === $updated;
	}

	/**
	 * Deletes a creator's requests (the creator's deletion).
	 *
	 * @param int $creator_id Creator.
	 * @return int The rows deleted.
	 * @throws DatabaseException When the write fails.
	 */
	public function delete_for( int $creator_id ): int {
		$wpdb    = $this->wpdb;
		$deleted = $wpdb->delete( $this->table(), array( 'creator_id' => $creator_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.

		if ( false === $deleted ) {
			throw new DatabaseException( 'Could not delete the product removal requests.' );
		}

		return (int) $deleted;
	}

	/**
	 * Which of the ids a table's column holds.
	 *
	 * @phpstan-param list<int> $ids
	 * @phpstan-param literal-string $column
	 *
	 * @param int[]  $ids    Ids.
	 * @param string $table  One of Tables' constants.
	 * @param string $column Constant column name.
	 * @return list<int>
	 * @throws DatabaseException When the read fails.
	 */
	private function ids_among( array $ids, string $table, string $column ): array {
		if ( array() === $ids ) {
			return array();
		}

		$wpdb  = $this->wpdb;
		$marks = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT {$column} FROM %i WHERE {$column} IN ( {$marks} )", Tables::name( $wpdb, $table ), ...$ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Constant column; $marks holds one %d per id.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the products.' );
		}

		return array_map( 'intval', $found );
	}

	/**
	 * Rows of a prepared query as requests.
	 *
	 * @param string|null $sql Prepared query.
	 * @return list<ProductRemovalRequest>
	 * @throws DatabaseException When the read fails or a row is invalid.
	 */
	private function requests( ?string $sql ): array {
		$wpdb = $this->wpdb;
		$rows = null === $sql ? null : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; the plugin's own table, current state.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the product removal requests.' );
		}

		$requests = array();
		$utc      = new DateTimeZone( 'UTC' );

		foreach ( $rows as $row ) {
			$status = ProductRemovalStatus::tryFrom( (string) $row['status'] );

			if ( null === $status ) {
				throw new DatabaseException( 'Invalid product removal request.' );
			}

			try {
				$requests[] = new ProductRemovalRequest(
					(int) $row['id'],
					(int) $row['creator_id'],
					(int) $row['wc_product_id'],
					(string) $row['product_name'],
					$status,
					null === $row['note'] ? null : (string) $row['note'],
					new DateTimeImmutable( (string) $row['requested_at'], $utc ),
					null === $row['requested_by'] ? null : (int) $row['requested_by'],
					null === $row['handled_at'] ? null : new DateTimeImmutable( (string) $row['handled_at'], $utc ),
					null === $row['handled_by'] ? null : (int) $row['handled_by']
				);
			} catch ( Exception ) {
				throw new DatabaseException( 'Invalid product removal request.' );
			}
		}

		return $requests;
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
	 * Full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::PRODUCT_REMOVAL_REQUESTS );
	}
}
