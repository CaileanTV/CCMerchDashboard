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

	private const COLUMNS = 'id, creator_id, order_id, order_number, order_item_id, product_id, variation_id, product_name, variation, quantity, image_id, category_ids, customer_first_name, customer_username, created_at';

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
			'INSERT INTO %i (creator_id, order_id, order_number, order_item_id, product_id, variation_id, product_name, variation, quantity, image_id, category_ids, customer_first_name, customer_username, created_at)
			VALUES (%d, %d, %s, %d, %d, %d, %s, %s, %d, %d, %s, %s, %s, %s)
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
			self::cut( $event->customer_first_name, 191 ),
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
			(string) ( $row['customer_first_name'] ?? '' ),
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
