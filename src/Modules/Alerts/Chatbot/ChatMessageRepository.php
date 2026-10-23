<?php
/**
 * The chat message log.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Chatbot;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_alert_chat_messages` (schema versions 20 and 21, ADR-066 pkt. 3):
 * one row per creator, platform, order and line (line 0: one message for the
 * whole order).
 * A message is sent only while its row is not `sent`, so an order that goes
 * from processing to completed, or a job that runs twice, never announces a
 * purchase twice. Only status codes are stored, never the message.
 */
final class ChatMessageRepository {

	public const SENT    = 'sent';
	public const WAITING = 'waiting';
	public const FAILED  = 'failed';
	public const SKIPPED = 'skipped';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Whether the message was sent.
	 *
	 * @param int          $creator_id    Creator.
	 * @param ChatPlatform $platform      Platform.
	 * @param int          $order_id      Order.
	 * @param int          $order_item_id Line; 0 for the whole order.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function sent( int $creator_id, ChatPlatform $platform, int $order_id, int $order_item_id ): bool {
		return self::SENT === $this->row( $creator_id, $platform, $order_id, $order_item_id )['status'];
	}

	/**
	 * Whether the message is finished: sent, failed for good or skipped.
	 *
	 * @param int          $creator_id    Creator.
	 * @param ChatPlatform $platform      Platform.
	 * @param int          $order_id      Order.
	 * @param int          $order_item_id Line; 0 for the whole order.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function finished( int $creator_id, ChatPlatform $platform, int $order_id, int $order_item_id ): bool {
		return in_array( $this->row( $creator_id, $platform, $order_id, $order_item_id )['status'], array( self::SENT, self::FAILED, self::SKIPPED ), true );
	}

	/**
	 * Whether a message of the order was skipped for the reason.
	 *
	 * @param int          $creator_id Creator.
	 * @param ChatPlatform $platform   Platform.
	 * @param int          $order_id   Order.
	 * @param string       $reason     Reason code.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function skipped_in_order( int $creator_id, ChatPlatform $platform, int $order_id, string $reason ): bool {
		$wpdb    = $this->wpdb;
		$skipped = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE creator_id = %d AND platform = %s AND order_id = %d AND status = %s AND reason = %s', $this->table(), $creator_id, $platform->value, $order_id, self::SKIPPED, $reason ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the chat message log.' );
		}

		return (int) $skipped > 0;
	}

	/**
	 * How often sending was tried.
	 *
	 * @param int          $creator_id    Creator.
	 * @param ChatPlatform $platform      Platform.
	 * @param int          $order_id      Order.
	 * @param int          $order_item_id Line; 0 for the whole order.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function attempts( int $creator_id, ChatPlatform $platform, int $order_id, int $order_item_id ): int {
		return $this->row( $creator_id, $platform, $order_id, $order_item_id )['attempts'];
	}

	/**
	 * Notes the outcome of an attempt.
	 *
	 * @param int          $creator_id    Creator.
	 * @param ChatPlatform $platform      Platform.
	 * @param int          $order_id      Order.
	 * @param int          $order_item_id Line; 0 for the whole order.
	 * @param string       $status        One of the constants.
	 * @param string       $reason        Reason code ('' for none).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function note( int $creator_id, ChatPlatform $platform, int $order_id, int $order_item_id, string $status, string $reason = '' ): void {
		$wpdb = $this->wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$sql  = $wpdb->prepare(
			'INSERT INTO %i (creator_id, platform, order_id, order_item_id, status, reason, attempts, created_at, updated_at) VALUES (%d, %s, %d, %d, %s, %s, 1, %s, %s)
			ON DUPLICATE KEY UPDATE status = VALUES(status), reason = VALUES(reason), attempts = attempts + 1, updated_at = VALUES(updated_at)',
			$this->table(),
			$creator_id,
			$platform->value,
			$order_id,
			$order_item_id,
			$status,
			substr( $reason, 0, 60 ),
			$now,
			$now
		);

		if ( null === $sql || false === $wpdb->query( $sql ) || '' !== $wpdb->last_error ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared above; a custom table.
			throw new DatabaseException( 'Could not store the chat message log.' );
		}
	}

	/**
	 * Notes a message MerchAlert's chatbot sent (the import, step 25.10.1),
	 * with its time, so the line is never announced again; an existing
	 * entry stays as it is.
	 *
	 * @param int          $creator_id    Creator.
	 * @param ChatPlatform $platform      Platform.
	 * @param int          $order_id      Order.
	 * @param int          $order_item_id Line.
	 * @param string       $sent_at       When it was sent (UTC, Y-m-d H:i:s).
	 * @return bool Whether it was added.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function import_sent( int $creator_id, ChatPlatform $platform, int $order_id, int $order_item_id, string $sent_at ): bool {
		if ( $order_id < 1 || $order_item_id < 1 ) {
			return false;
		}

		$time = 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $sent_at ) ? $sent_at : gmdate( 'Y-m-d H:i:s' );
		$wpdb = $this->wpdb;
		$sql  = $wpdb->prepare(
			'INSERT IGNORE INTO %i (creator_id, platform, order_id, order_item_id, status, reason, attempts, created_at, updated_at) VALUES (%d, %s, %d, %d, %s, %s, 1, %s, %s)',
			$this->table(),
			$creator_id,
			$platform->value,
			$order_id,
			$order_item_id,
			self::SENT,
			'',
			$time,
			$time
		);
		$rows = null === $sql ? false : $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared above; a custom table.

		if ( false === $rows || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not store the chat message log.' );
		}

		return $rows > 0;
	}

	/**
	 * Forgets a message, so an explicit resend sends it again.
	 *
	 * @param int          $creator_id    Creator.
	 * @param ChatPlatform $platform      Platform.
	 * @param int          $order_id      Order.
	 * @param int          $order_item_id Line; 0 for the whole order.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function forget( int $creator_id, ChatPlatform $platform, int $order_id, int $order_item_id ): void {
		$deleted = $this->wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
			$this->table(),
			array(
				'creator_id'    => $creator_id,
				'platform'      => $platform->value,
				'order_id'      => $order_id,
				'order_item_id' => $order_item_id,
			),
			array( '%d', '%s', '%d', '%d' )
		);

		if ( false === $deleted ) {
			throw new DatabaseException( 'Could not update the chat message log.' );
		}
	}

	/**
	 * The orders with messages that wait for a reason (e.g. `not_live`).
	 *
	 * @param int          $creator_id Creator.
	 * @param ChatPlatform $platform   Platform.
	 * @param string       $reason     Reason code.
	 * @return list<int>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function waiting_orders( int $creator_id, ChatPlatform $platform, string $reason ): array {
		$wpdb   = $this->wpdb;
		$orders = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT order_id FROM %i WHERE creator_id = %d AND platform = %s AND status = %s AND reason = %s ORDER BY order_id', $this->table(), $creator_id, $platform->value, self::WAITING, $reason ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the chat message log.' );
		}

		return array_map( 'intval', $orders );
	}

	/**
	 * The message outcomes of some orders for the administrator's order log
	 * (step 25.9.3): per order, creator, platform and status, the number of
	 * messages and a reason ('' for none).
	 *
	 * @phpstan-param list<int> $order_ids
	 *
	 * @param int[] $order_ids Orders.
	 * @return list<array{order_id: int, creator_id: int, platform: string, status: string, reason: string, count: int}>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function outcomes( array $order_ids ): array {
		$order_ids = array_values( array_unique( array_filter( $order_ids, static fn( int $id ): bool => $id > 0 ) ) );

		if ( array() === $order_ids ) {
			return array();
		}

		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT order_id, creator_id, platform, status, MAX(reason) AS reason, COUNT(*) AS count FROM %i WHERE order_id IN (' . implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) ) . ') GROUP BY order_id, creator_id, platform, status ORDER BY order_id, creator_id, platform, status', $this->table(), ...$order_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery -- One placeholder per id; values are prepared.

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the chat message log.' );
		}

		$outcomes = array();

		foreach ( $rows as $row ) {
			$outcomes[] = array(
				'order_id'   => (int) ( $row['order_id'] ?? 0 ),
				'creator_id' => (int) ( $row['creator_id'] ?? 0 ),
				'platform'   => (string) ( $row['platform'] ?? '' ),
				'status'     => (string) ( $row['status'] ?? '' ),
				'reason'     => (string) ( $row['reason'] ?? '' ),
				'count'      => (int) ( $row['count'] ?? 0 ),
			);
		}

		return $outcomes;
	}

	/**
	 * The creator's latest message outcome, or null.
	 *
	 * @param int          $creator_id Creator.
	 * @param ChatPlatform $platform   Platform.
	 * @return array{status: string, reason: string, at: int}|null
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function latest( int $creator_id, ChatPlatform $platform ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT status, reason, updated_at FROM %i WHERE creator_id = %d AND platform = %s ORDER BY updated_at DESC, id DESC LIMIT 1', $this->table(), $creator_id, $platform->value ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the chat message log.' );
		}

		if ( ! is_array( $row ) ) {
			return null;
		}

		$at = strtotime( (string) ( $row['updated_at'] ?? '' ) . ' UTC' );

		return array(
			'status' => (string) ( $row['status'] ?? '' ),
			'reason' => (string) ( $row['reason'] ?? '' ),
			'at'     => false === $at ? 0 : $at,
		);
	}

	/**
	 * A message's row (status '' and 0 attempts when there is none).
	 *
	 * @param int          $creator_id    Creator.
	 * @param ChatPlatform $platform      Platform.
	 * @param int          $order_id      Order.
	 * @param int          $order_item_id Line.
	 * @return array{status: string, attempts: int}
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function row( int $creator_id, ChatPlatform $platform, int $order_id, int $order_item_id ): array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT status, attempts FROM %i WHERE creator_id = %d AND platform = %s AND order_id = %d AND order_item_id = %d', $this->table(), $creator_id, $platform->value, $order_id, $order_item_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the chat message log.' );
		}

		return array(
			'status'   => is_array( $row ) ? (string) ( $row['status'] ?? '' ) : '',
			'attempts' => is_array( $row ) ? (int) ( $row['attempts'] ?? 0 ) : 0,
		);
	}

	/**
	 * The full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::ALERT_CHAT_MESSAGES );
	}
}
