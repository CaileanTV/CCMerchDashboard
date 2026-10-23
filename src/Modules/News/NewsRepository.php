<?php
/**
 * Storage of the news messages and who read them.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\News;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_news` and `merchboard_news_reads` (schema version 25,
 * ADR-072). Times are UTC. A creator's seen and closed times keep the first
 * one: marking again changes nothing.
 */
class NewsRepository {

	private const COLUMNS = 'id, title, body, status, published_at, updated_at';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Adds a draft.
	 *
	 * @param string $title      Cleaned title.
	 * @param string $body       Cleaned HTML.
	 * @param int    $created_by User.
	 * @return int Id.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function insert( string $title, string $body, int $created_by ): int {
		$now  = gmdate( 'Y-m-d H:i:s' );
		$done = $this->wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
			$this->table(),
			array(
				'title'      => $title,
				'body'       => $body,
				'status'     => NewsMessage::DRAFT,
				'created_by' => $created_by,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not store the news message.' );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Changes a message's title and body.
	 *
	 * @param int    $id    Id.
	 * @param string $title Cleaned title.
	 * @param string $body  Cleaned HTML.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function update_text( int $id, string $title, string $body ): void {
		$this->update(
			$id,
			array(
				'title' => $title,
				'body'  => $body,
			)
		);
	}

	/**
	 * Changes a message's status (and the publication time).
	 *
	 * @param int                    $id           Id.
	 * @param string                 $status       NewsMessage constant.
	 * @param DateTimeImmutable|null $published_at Publication time (UTC); null keeps it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set_status( int $id, string $status, ?DateTimeImmutable $published_at = null ): void {
		$values = array( 'status' => $status );

		if ( null !== $published_at ) {
			$values['published_at'] = $published_at->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}

		$this->update( $id, $values );
	}

	/**
	 * Deletes a message and its reads.
	 *
	 * @param int $id Id.
	 * @throws DatabaseException When it cannot be deleted.
	 */
	public function delete( int $id ): void {
		$wpdb = $this->wpdb;

		if ( false === $wpdb->delete( $this->reads_table(), array( 'news_id' => $id ), array( '%d' ) ) || false === $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) ) || '' !== $wpdb->last_error ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom tables.
			throw new DatabaseException( 'Could not delete the news message.' );
		}
	}

	/**
	 * A message, or null.
	 *
	 * @param int $id Id.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function find( int $id ): ?NewsMessage {
		$rows = $this->rows( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d', $this->table(), $id );

		return isset( $rows[0] ) ? self::hydrate( $rows[0] ) : null;
	}

	/**
	 * Every message for the admin page: drafts first, then the newest.
	 *
	 * @return list<NewsMessage>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function all(): array {
		return array_map( self::hydrate( ... ), $this->rows( 'SELECT ' . self::COLUMNS . " FROM %i ORDER BY status = 'draft' DESC, COALESCE(published_at, updated_at) DESC, id DESC", $this->table() ) );
	}

	/**
	 * The published messages a creator created at a time sees, newest first:
	 * those published at or after that time.
	 *
	 * @param DateTimeImmutable $since The creator's creation (UTC).
	 * @return list<NewsMessage>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function published_since( DateTimeImmutable $since ): array {
		return array_map(
			self::hydrate( ... ),
			$this->rows( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE status = %s AND published_at >= %s ORDER BY published_at DESC, id DESC', $this->table(), NewsMessage::PUBLISHED, $since->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) )
		);
	}

	/**
	 * Notes that a creator saw a message (the first time stays).
	 *
	 * @param int $news_id    Message.
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function mark_seen( int $news_id, int $creator_id ): void {
		$this->mark( $news_id, $creator_id, false );
	}

	/**
	 * Notes that a creator closed a message (also seen; the first times stay).
	 *
	 * @param int $news_id    Message.
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function mark_closed( int $news_id, int $creator_id ): void {
		$this->mark( $news_id, $creator_id, true );
	}

	/**
	 * When creators saw and closed a message, by creator.
	 *
	 * @param int $news_id Message.
	 * @return array<int, array{seen_at: string|null, closed_at: string|null}>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function reads( int $news_id ): array {
		$reads = array();

		foreach ( $this->rows( 'SELECT creator_id, seen_at, closed_at FROM %i WHERE news_id = %d', $this->reads_table(), $news_id ) as $row ) {
			$reads[ (int) $row['creator_id'] ] = array(
				'seen_at'   => is_string( $row['seen_at'] ?? null ) ? $row['seen_at'] : null,
				'closed_at' => is_string( $row['closed_at'] ?? null ) ? $row['closed_at'] : null,
			);
		}

		return $reads;
	}

	/**
	 * When a creator saw and closed messages, by message.
	 *
	 * @param int $creator_id Creator.
	 * @return array<int, array{seen_at: string|null, closed_at: string|null}>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function reads_of( int $creator_id ): array {
		$reads = array();

		foreach ( $this->rows( 'SELECT news_id, seen_at, closed_at FROM %i WHERE creator_id = %d', $this->reads_table(), $creator_id ) as $row ) {
			$reads[ (int) $row['news_id'] ] = array(
				'seen_at'   => is_string( $row['seen_at'] ?? null ) ? $row['seen_at'] : null,
				'closed_at' => is_string( $row['closed_at'] ?? null ) ? $row['closed_at'] : null,
			);
		}

		return $reads;
	}

	/**
	 * Inserts or updates a read; the first seen and closed times stay.
	 *
	 * @param int  $news_id    Message.
	 * @param int  $creator_id Creator.
	 * @param bool $closed     Whether the message was closed.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function mark( int $news_id, int $creator_id, bool $closed ): void {
		$wpdb = $this->wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$sql  = $wpdb->prepare(
			"INSERT INTO %i (news_id, creator_id, seen_at, closed_at) VALUES (%d, %d, %s, NULLIF(%s, '')) ON DUPLICATE KEY UPDATE seen_at = COALESCE(seen_at, VALUES(seen_at)), closed_at = COALESCE(closed_at, VALUES(closed_at))",
			$this->reads_table(),
			$news_id,
			$creator_id,
			$now,
			$closed ? $now : ''
		);

		if ( null === $sql || false === $wpdb->query( $sql ) || '' !== $wpdb->last_error ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared above; a custom table.
			throw new DatabaseException( 'Could not store that the news message was read.' );
		}
	}

	/**
	 * Updates a message's columns and its change time.
	 *
	 * @param int                  $id     Id.
	 * @param array<string, mixed> $values Columns.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function update( int $id, array $values ): void {
		$values['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		if ( false === $this->wpdb->update( $this->table(), $values, array( 'id' => $id ) ) || '' !== $this->wpdb->last_error ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
			throw new DatabaseException( 'Could not change the news message.' );
		}
	}

	/**
	 * Rows of a prepared query.
	 *
	 * @phpstan-param literal-string $query
	 *
	 * @param string     $query   Query with placeholders.
	 * @param int|string ...$args Values.
	 * @return list<array<mixed>>
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function rows( string $query, int|string ...$args ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( $query, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared; custom tables.

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the news.' );
		}

		return $rows;
	}

	/**
	 * A message from a row.
	 *
	 * @param array<mixed> $row Row.
	 * @throws DatabaseException When the row is invalid.
	 */
	private static function hydrate( array $row ): NewsMessage {
		$utc = new DateTimeZone( 'UTC' );

		try {
			$published = is_string( $row['published_at'] ?? null ) ? new DateTimeImmutable( $row['published_at'], $utc ) : null;
			$updated   = new DateTimeImmutable( is_string( $row['updated_at'] ?? null ) ? $row['updated_at'] : 'now', $utc );
		} catch ( Exception ) {
			throw new DatabaseException( 'Invalid news row.' );
		}

		return new NewsMessage(
			is_numeric( $row['id'] ?? null ) ? (int) $row['id'] : 0,
			is_string( $row['title'] ?? null ) ? $row['title'] : '',
			NewsHtml::clean( is_string( $row['body'] ?? null ) ? $row['body'] : '' ),
			is_string( $row['status'] ?? null ) ? $row['status'] : NewsMessage::DRAFT,
			$published,
			$updated
		);
	}

	/**
	 * The messages' table.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::NEWS );
	}

	/**
	 * The reads' table.
	 */
	private function reads_table(): string {
		return Tables::name( $this->wpdb, Tables::NEWS_READS );
	}
}
