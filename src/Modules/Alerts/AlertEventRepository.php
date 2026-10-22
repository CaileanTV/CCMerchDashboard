<?php
/**
 * Storage of purchase alerts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Http\Pagination;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_alert_events` (ADR-065). A line gets at most one alert per
 * creator (`UNIQUE (order_item_id, creator_id)`): a second insert, also a
 * concurrent one, keeps the first row. Text is cut to the column lengths,
 * so a long product name never makes the insert fail in strict mode.
 */
final class AlertEventRepository {

	/**
	 * At most this many category ids are stored per alert: 40 ids of up to 20
	 * digits with separators fit the column (varchar(1000)).
	 */
	public const MAX_CATEGORIES = 40;

	private const COLUMNS = 'id, creator_id, order_id, order_number, order_item_id, product_id, variation_id, product_name, variation, quantity, image_id, category_ids, customer_username, created_at';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Stores an alert unless the line already has one for the creator.
	 *
	 * @param AlertEvent $event Alert.
	 * @return bool True when it was stored, false when it existed (kept unchanged).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function insert( AlertEvent $event ): bool {
		$wpdb = $this->wpdb;
		$sql  = $wpdb->prepare(
			'INSERT INTO %i (creator_id, order_id, order_number, order_item_id, product_id, variation_id, product_name, variation, quantity, image_id, category_ids, customer_username, created_at)
			VALUES (%d, %d, %s, %d, %d, %d, %s, %s, %d, %d, %s, %s, %s)
			ON DUPLICATE KEY UPDATE id = id',
			$this->table(),
			$event->creator_id,
			$event->order_id,
			self::cut( $event->order_number, 64 ),
			$event->order_item_id,
			$event->product_id,
			$event->variation_id,
			self::cut( $event->product_name, 255 ),
			self::cut( $event->variation, 255 ),
			$event->quantity,
			$event->image_id,
			self::categories( $event->category_ids ),
			self::cut( $event->customer_username, 191 ),
			$event->created_at->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' )
		);

		if ( null === $sql ) {
			throw new DatabaseException( 'Could not prepare the alert.' );
		}

		$affected = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared above; a custom table.

		if ( false === $affected || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not store the alert.' );
		}

		return 1 === $affected;
	}

	/**
	 * The alerts of an order, oldest first.
	 *
	 * @param int $order_id Order.
	 * @return list<AlertEvent>
	 * @throws DatabaseException When the database cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function for_order( int $order_id ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE order_id = %d ORDER BY id', $this->table(), $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Constant column list; values are prepared.

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the alerts.' );
		}

		return array_map( self::hydrate( ... ), $rows );
	}

	/**
	 * The number of the creator's orders with alerts (the history, step 25.7).
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the database cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function order_count( int $creator_id ): int {
		$wpdb  = $this->wpdb;
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT order_id) FROM %i WHERE creator_id = %d', $this->table(), $creator_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the alerts.' );
		}

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * A page of the creator's orders with alerts, the newest alert first.
	 *
	 * @param int        $creator_id Creator.
	 * @param Pagination $pagination Page.
	 * @return list<int> Order ids.
	 * @throws DatabaseException When the database cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function order_page( int $creator_id, Pagination $pagination ): array {
		$wpdb = $this->wpdb;
		$ids  = $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE creator_id = %d GROUP BY order_id ORDER BY MAX(id) DESC LIMIT %d OFFSET %d', $this->table(), $creator_id, $pagination->per_page, $pagination->offset() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the alerts.' );
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * Totals of the administrator's order log (step 25.9.3): orders, alerts
	 * and creators with alerts recorded since a moment (null = all).
	 *
	 * @param DateTimeImmutable|null $since Lower bound, inclusive; null for all.
	 * @return array{orders: int, alerts: int, creators: int}
	 * @throws DatabaseException When the database cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function log_totals( ?DateTimeImmutable $since ): array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(DISTINCT order_id) AS orders, COUNT(*) AS alerts, COUNT(DISTINCT creator_id) AS creators FROM %i WHERE created_at >= %s', $this->table(), self::lower_bound( $since ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.

		if ( ! is_array( $row ) || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the alerts.' );
		}

		return array(
			'orders'   => self::int( $row['orders'] ?? null ),
			'alerts'   => self::int( $row['alerts'] ?? null ),
			'creators' => self::int( $row['creators'] ?? null ),
		);
	}

	/**
	 * A page of the order log: orders with alerts recorded since a moment,
	 * the newest alert first, across all creators.
	 *
	 * @param DateTimeImmutable|null $since      Lower bound, inclusive; null for all.
	 * @param Pagination             $pagination Page.
	 * @return list<int> Order ids.
	 * @throws DatabaseException When the database cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function log_page( ?DateTimeImmutable $since, Pagination $pagination ): array {
		$wpdb = $this->wpdb;
		$ids  = $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE created_at >= %s GROUP BY order_id ORDER BY MAX(id) DESC LIMIT %d OFFSET %d', $this->table(), self::lower_bound( $since ), $pagination->per_page, $pagination->offset() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the alerts.' );
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * Every creator's alerts of some orders, oldest first (the order log).
	 *
	 * @phpstan-param list<int> $order_ids
	 *
	 * @param int[] $order_ids Orders.
	 * @return list<AlertEvent>
	 * @throws DatabaseException When the database cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function for_orders( array $order_ids ): array {
		$order_ids = array_values( array_unique( array_filter( $order_ids, static fn( int $id ): bool => $id > 0 ) ) );

		if ( array() === $order_ids ) {
			return array();
		}

		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE order_id IN (' . implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) ) . ') ORDER BY id', $this->table(), ...$order_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Constant column list and one placeholder per id; values are prepared.

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the alerts.' );
		}

		return array_map( self::hydrate( ... ), $rows );
	}

	/**
	 * The stored (UTC) lower bound of a time filter.
	 *
	 * @param DateTimeImmutable|null $since Lower bound; null for all.
	 */
	private static function lower_bound( ?DateTimeImmutable $since ): string {
		return null === $since ? '1000-01-01 00:00:00' : $since->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * The creator's alerts of some orders, oldest first; never another
	 * creator's alerts of the same order.
	 *
	 * @phpstan-param list<int> $order_ids
	 *
	 * @param int   $creator_id Creator.
	 * @param int[] $order_ids  Orders.
	 * @return list<AlertEvent>
	 * @throws DatabaseException When the database cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function for_creator_orders( int $creator_id, array $order_ids ): array {
		$order_ids = array_unique( array_filter( $order_ids, static fn( int $id ): bool => $id > 0 ) );

		if ( array() === $order_ids ) {
			return array();
		}

		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d AND order_id IN (' . implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) ) . ') ORDER BY id', $this->table(), $creator_id, ...$order_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Constant column list and one placeholder per id; values are prepared.

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the alerts.' );
		}

		return array_map( self::hydrate( ... ), $rows );
	}

	/**
	 * The creators with alerts in an order (the chat messages, step 25.8).
	 *
	 * @param int $order_id Order.
	 * @return list<int>
	 * @throws DatabaseException When the database cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function creators_of_order( int $order_id ): array {
		$wpdb = $this->wpdb;
		$ids  = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT creator_id FROM %i WHERE order_id = %d ORDER BY creator_id', $this->table(), $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the alerts.' );
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * One of the creator's alerts, or null (also for another creator's alert).
	 *
	 * @param int $creator_id Creator.
	 * @param int $id         Alert id.
	 * @throws DatabaseException When the database cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function find_for_creator( int $creator_id, int $id ): ?AlertEvent {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d AND id = %d', $this->table(), $creator_id, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Constant column list; values are prepared.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the alert.' );
		}

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * The id of the creator's newest alert, 0 when there is none: the
	 * overlay's starting cursor, so it only shows alerts from now on.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the database cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function latest_id( int $creator_id ): int {
		$wpdb = $this->wpdb;
		$id   = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i WHERE creator_id = %d', $this->table(), $creator_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the alerts.' );
		}

		return is_numeric( $id ) ? (int) $id : 0;
	}

	/**
	 * The creator's alerts after a cursor (row id), oldest first. Ids grow
	 * with every insert, so a cursor never skips or repeats an alert.
	 *
	 * @param int $creator_id Creator.
	 * @param int $after_id   Cursor: the last id already delivered.
	 * @param int $limit      At most this many (1–50).
	 * @return list<AlertEvent>
	 * @throws DatabaseException When the database cannot be read.
	 * @phpstan-impure Reads the database.
	 */
	public function after( int $creator_id, int $after_id, int $limit ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d AND id > %d ORDER BY id LIMIT %d', $this->table(), $creator_id, max( 0, $after_id ), max( 1, min( 50, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Constant column list; values are prepared.

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the alerts.' );
		}

		return array_map( self::hydrate( ... ), $rows );
	}

	/**
	 * An alert from a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @throws DatabaseException When the row is invalid.
	 */
	private static function hydrate( array $row ): AlertEvent {
		try {
			$created = new DateTimeImmutable( (string) ( $row['created_at'] ?? '' ), new DateTimeZone( 'UTC' ) );
		} catch ( Exception ) {
			throw new DatabaseException( 'Invalid alert row.' );
		}

		$categories = json_decode( (string) ( $row['category_ids'] ?? '' ), true );

		return new AlertEvent(
			self::int( $row['creator_id'] ?? null ),
			self::int( $row['order_id'] ?? null ),
			(string) ( $row['order_number'] ?? '' ),
			self::int( $row['order_item_id'] ?? null ),
			self::int( $row['product_id'] ?? null ),
			self::int( $row['variation_id'] ?? null ),
			(string) ( $row['product_name'] ?? '' ),
			(string) ( $row['variation'] ?? '' ),
			self::int( $row['quantity'] ?? null ),
			self::int( $row['image_id'] ?? null ),
			is_array( $categories ) ? array_values( array_filter( array_map( 'intval', $categories ), static fn( int $id ): bool => $id > 0 ) ) : array(),
			(string) ( $row['customer_username'] ?? '' ),
			$created,
			self::int( $row['id'] ?? null )
		);
	}

	/**
	 * A stored integer.
	 *
	 * @param mixed $value Value.
	 * @throws DatabaseException When it is not a non-negative integer.
	 */
	private static function int( mixed $value ): int {
		if ( ! is_numeric( $value ) || (int) $value < 0 ) {
			throw new DatabaseException( 'Invalid alert row.' );
		}

		return (int) $value;
	}

	/**
	 * Category ids as a JSON list: positive, unique, at most MAX_CATEGORIES.
	 *
	 * @param int[] $ids Category ids.
	 */
	private static function categories( array $ids ): string {
		$ids = array_slice( array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) ), 0, self::MAX_CATEGORIES );

		return (string) wp_json_encode( $ids );
	}

	/**
	 * Text cut to a column length in characters.
	 *
	 * @param string $text   Text.
	 * @param int    $length Column length.
	 */
	private static function cut( string $text, int $length ): string {
		return mb_substr( $text, 0, $length );
	}

	/**
	 * The table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::ALERT_EVENTS );
	}
}
