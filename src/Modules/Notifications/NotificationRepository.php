<?php
/**
 * Storage of notifications.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes `merchboard_notifications` (migration 34, ADR-079).
 */
class NotificationRepository {

	/**
	 * How long notifications are kept (days).
	 */
	public const KEEP_DAYS = 180;

	private const COLUMNS = 'id, type, audience, creator_id, data, in_dashboard, created_at, read_at, email_status, email_attempts, email_sent_at, email_error';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Stores a notification; nothing when its dedupe key is taken.
	 *
	 * @param string               $type         Type key.
	 * @param string               $audience     NotificationType::CREATOR or ADMIN.
	 * @param int|null             $creator_id   Creator.
	 * @param array<string, mixed> $data         Values.
	 * @param string               $dedupe       Dedupe key ('' for none).
	 * @param bool                 $in_dashboard Shown at the bell.
	 * @param string               $email_status Notification::EMAIL_ constant.
	 * @return int|null Id, or null when the same notification exists.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function insert( string $type, string $audience, ?int $creator_id, array $data, string $dedupe, bool $in_dashboard, string $email_status ): ?int {
		$values = array(
			'type'         => $type,
			'audience'     => $audience,
			'data'         => (string) wp_json_encode( $data ),
			'in_dashboard' => $in_dashboard ? 1 : 0,
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			'email_status' => $email_status,
		);

		if ( null !== $creator_id ) {
			$values['creator_id'] = $creator_id;
		}

		if ( '' !== $dedupe ) {
			$values['dedupe_key'] = substr( $dedupe, 0, 191 );
		}

		$columns = implode( ', ', array_keys( $values ) );
		$marks   = implode( ', ', array_map( static fn( mixed $value ): string => is_int( $value ) ? '%d' : '%s', $values ) );
		$done    = $this->wpdb->query( (string) $this->wpdb->prepare( "INSERT IGNORE INTO %i ({$columns}) VALUES ({$marks})", $this->table(), ...array_values( $values ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Constant column names; one placeholder per value.

		if ( false === $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not store the notification.' );
		}

		return 0 === (int) $done ? null : (int) $this->wpdb->insert_id;
	}

	/**
	 * A notification, or null.
	 *
	 * @param int $id Id.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function find( int $id ): ?Notification {
		return $this->rows( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', $id )[0] ?? null;
	}

	/**
	 * Stores the outcome of sending a notification's e-mail.
	 *
	 * @param int         $id       Id.
	 * @param string      $status   Notification::EMAIL_ constant.
	 * @param int         $attempts Attempts so far.
	 * @param string|null $error    Short reason ('' or null for none).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function update_email( int $id, string $status, int $attempts, ?string $error ): void {
		$done = $this->wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
			$this->table(),
			array(
				'email_status'   => $status,
				'email_attempts' => min( 255, $attempts ),
				'email_sent_at'  => Notification::EMAIL_SENT === $status ? gmdate( 'Y-m-d H:i:s' ) : null,
				'email_error'    => null === $error || '' === $error ? null : substr( $error, 0, 191 ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $done ) {
			throw new DatabaseException( 'Could not store the e-mail state of the notification.' );
		}
	}

	/**
	 * A creator's notifications at the bell, newest first.
	 *
	 * @param int $creator_id Creator.
	 * @param int $limit      Page size.
	 * @param int $offset     Offset.
	 * @return list<Notification>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function for_creator( int $creator_id, int $limit, int $offset = 0 ): array {
		return $this->rows( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d AND in_dashboard = 1 ORDER BY id DESC LIMIT %d OFFSET %d', $creator_id, max( 1, $limit ), max( 0, $offset ) );
	}

	/**
	 * How many of a creator's notifications are at the bell.
	 *
	 * @param int  $creator_id Creator.
	 * @param bool $unread     Only unread ones.
	 * @throws DatabaseException When it cannot be read.
	 */
	public function count_for_creator( int $creator_id, bool $unread = false ): int {
		$query = 'SELECT COUNT(*) FROM %i WHERE creator_id = %d AND in_dashboard = 1' . ( $unread ? ' AND read_at IS NULL' : '' );
		$count = $this->wpdb->get_var( $this->wpdb->prepare( $query, $this->table(), $creator_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Constant query.

		if ( null === $count && '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not count the notifications.' );
		}

		return (int) $count;
	}

	/**
	 * Marks a creator's notifications as read.
	 *
	 * @param int            $creator_id Creator.
	 * @param list<int>|null $ids        These ids, or null for all.
	 * @return int How many were marked.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function mark_read( int $creator_id, ?array $ids ): int {
		$now = gmdate( 'Y-m-d H:i:s' );

		if ( null === $ids ) {
			$done = $this->wpdb->query( (string) $this->wpdb->prepare( 'UPDATE %i SET read_at = %s WHERE creator_id = %d AND in_dashboard = 1 AND read_at IS NULL', $this->table(), $now, $creator_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A custom table; constant query.
		} else {
			$ids = array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) );

			if ( array() === $ids ) {
				return 0;
			}

			$marks = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$done  = $this->wpdb->query( (string) $this->wpdb->prepare( "UPDATE %i SET read_at = %s WHERE creator_id = %d AND in_dashboard = 1 AND read_at IS NULL AND id IN ({$marks})", $this->table(), $now, $creator_id, ...$ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One %d per id.
		}

		if ( false === $done ) {
			throw new DatabaseException( 'Could not mark the notifications as read.' );
		}

		return (int) $done;
	}

	/**
	 * The latest notifications with an e-mail (the admin page's log).
	 *
	 * @param int $limit Count.
	 * @return list<Notification>
	 * @throws DatabaseException When they cannot be read.
	 */
	public function recent_emails( int $limit ): array {
		return $this->rows( 'SELECT ' . self::COLUMNS . " FROM %i WHERE email_status <> 'none' ORDER BY id DESC LIMIT %d", max( 1, $limit ) );
	}

	/**
	 * Deletes notifications older than KEEP_DAYS, a batch at a time.
	 *
	 * @return int How many were deleted.
	 */
	public function prune(): int {
		$before = gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS );
		$done   = $this->wpdb->query( (string) $this->wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT 200', $this->table(), $before ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A custom table; constant query.

		return false === $done ? 0 : (int) $done;
	}

	/**
	 * Deletes a creator's notifications (creator deletion).
	 *
	 * @param int $creator_id Creator.
	 * @return int How many were deleted.
	 * @throws DatabaseException When they cannot be deleted.
	 */
	public function delete_for_creator( int $creator_id ): int {
		$done = $this->wpdb->delete( $this->table(), array( 'creator_id' => $creator_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A custom table.

		if ( false === $done ) {
			throw new DatabaseException( 'Could not delete the notifications of the creator.' );
		}

		return (int) $done;
	}

	/**
	 * Rows as notifications.
	 *
	 * @phpstan-param literal-string $query
	 *
	 * @param string     $query Query with %i for the table first.
	 * @param int|string ...$args Further arguments.
	 * @return list<Notification>
	 * @throws DatabaseException When they cannot be read.
	 */
	private function rows( string $query, int|string ...$args ): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $query, $this->table(), ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Constant queries.

		if ( ! is_array( $rows ) || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the notifications.' );
		}

		$result = array();

		foreach ( $rows as $row ) {
			$data     = json_decode( (string) ( $row['data'] ?? '' ), true );
			$result[] = new Notification(
				(int) ( $row['id'] ?? 0 ),
				(string) ( $row['type'] ?? '' ),
				(string) ( $row['audience'] ?? '' ),
				is_numeric( $row['creator_id'] ?? null ) ? (int) $row['creator_id'] : null,
				is_array( $data ) ? $data : array(),
				'1' === (string) ( $row['in_dashboard'] ?? '0' ),
				(string) ( $row['created_at'] ?? '' ),
				self::optional( $row, 'read_at' ),
				(string) ( $row['email_status'] ?? Notification::EMAIL_NONE ),
				(int) ( $row['email_attempts'] ?? 0 ),
				self::optional( $row, 'email_sent_at' ),
				self::optional( $row, 'email_error' )
			);
		}

		return $result;
	}

	/**
	 * A nullable text column.
	 *
	 * @param array<mixed> $row Row.
	 * @param string       $key Column.
	 */
	private static function optional( array $row, string $key ): ?string {
		$value = $row[ $key ] ?? null;

		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * The table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::NOTIFICATIONS );
	}
}
