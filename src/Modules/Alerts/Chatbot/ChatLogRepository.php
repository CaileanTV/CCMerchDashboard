<?php
/**
 * The channels' latest chat messages.
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
 * `merchboard_alert_chat_log` (schema version 20): chat messages the bot
 * receives over EventSub, for the admin's chat window (ADR-066, status note
 * 2026-10-20). They are public chat messages of the viewers (username and
 * text), so they are kept only 24 hours and at most 200 per channel; older
 * ones are deleted whenever a new one arrives. A message Twitch sends twice
 * (same message id) is stored once.
 */
final class ChatLogRepository {

	/**
	 * Hours a message is kept.
	 */
	public const KEEP_HOURS = 24;

	/**
	 * Messages kept per channel.
	 */
	public const KEEP_PER_CHANNEL = 200;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Stores a message and deletes what is too old or too many.
	 *
	 * @param string $broadcaster_id Channel.
	 * @param string $message_id     Twitch message id.
	 * @param string $login          Chatter login.
	 * @param string $name           Chatter display name.
	 * @param string $color          Chatter colour (#rrggbb or '').
	 * @param string $text           Message text.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function add( string $broadcaster_id, string $message_id, string $login, string $name, string $color, string $text ): void {
		$wpdb = $this->wpdb;
		$sql  = $wpdb->prepare(
			'INSERT IGNORE INTO %i (broadcaster_id, message_id, chatter_login, chatter_name, color, text, created_at) VALUES (%s, %s, %s, %s, %s, %s, %s)',
			$this->table(),
			substr( $broadcaster_id, 0, 20 ),
			substr( $message_id, 0, 64 ),
			substr( $login, 0, 25 ),
			mb_substr( $name, 0, 64 ),
			1 === preg_match( '/^#[0-9a-f]{6}$/i', $color ) ? strtolower( $color ) : '',
			mb_substr( $text, 0, 600 ),
			gmdate( 'Y-m-d H:i:s' )
		);

		if ( null === $sql || false === $wpdb->query( $sql ) || '' !== $wpdb->last_error ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared above; a custom table.
			throw new DatabaseException( 'Could not store the chat message.' );
		}

		$this->prune( $broadcaster_id );
	}

	/**
	 * A channel's messages after a cursor (row id), oldest first; the
	 * latest $limit when the cursor is 0.
	 *
	 * @param string $broadcaster_id Channel.
	 * @param int    $after          Last id already shown.
	 * @param int    $limit          At most this many (1–200).
	 * @return list<array{id: int, login: string, name: string, color: string, text: string, at: string}>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function after( string $broadcaster_id, int $after, int $limit = 100 ): array {
		$wpdb  = $this->wpdb;
		$limit = max( 1, min( self::KEEP_PER_CHANNEL, $limit ) );
		$rows  = 0 === $after
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM (SELECT id, chatter_login, chatter_name, color, text, created_at FROM %i WHERE broadcaster_id = %s ORDER BY id DESC LIMIT %d) AS latest ORDER BY id', $this->table(), $broadcaster_id, $limit ), ARRAY_A ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
			: $wpdb->get_results( $wpdb->prepare( 'SELECT id, chatter_login, chatter_name, color, text, created_at FROM %i WHERE broadcaster_id = %s AND id > %d ORDER BY id LIMIT %d', $this->table(), $broadcaster_id, $after, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the chat messages.' );
		}

		return array_map(
			static fn( array $row ): array => array(
				'id'    => (int) ( $row['id'] ?? 0 ),
				'login' => (string) ( $row['chatter_login'] ?? '' ),
				'name'  => (string) ( $row['chatter_name'] ?? '' ),
				'color' => (string) ( $row['color'] ?? '' ),
				'text'  => (string) ( $row['text'] ?? '' ),
				'at'    => str_replace( ' ', 'T', (string) ( $row['created_at'] ?? '' ) ) . 'Z',
			),
			$rows
		);
	}

	/**
	 * Deletes all messages of a channel (the bot left it).
	 *
	 * @param string $broadcaster_id Channel.
	 * @throws DatabaseException When it cannot be deleted.
	 */
	public function clear( string $broadcaster_id ): void {
		if ( false === $this->wpdb->delete( $this->table(), array( 'broadcaster_id' => $broadcaster_id ), array( '%s' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
			throw new DatabaseException( 'Could not delete the chat messages.' );
		}
	}

	/**
	 * Deletes messages older than KEEP_HOURS and beyond KEEP_PER_CHANNEL.
	 *
	 * @param string $broadcaster_id Channel.
	 * @throws DatabaseException When they cannot be deleted.
	 */
	private function prune( string $broadcaster_id ): void {
		$wpdb     = $this->wpdb;
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - self::KEEP_HOURS * HOUR_IN_SECONDS );
		$old      = $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $this->table(), $cutoff );
		$boundary = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE broadcaster_id = %s ORDER BY id DESC LIMIT 1 OFFSET %d', $this->table(), $broadcaster_id, self::KEEP_PER_CHANNEL ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
		$extra    = is_numeric( $boundary ) ? $wpdb->prepare( 'DELETE FROM %i WHERE broadcaster_id = %s AND id <= %d', $this->table(), $broadcaster_id, (int) $boundary ) : '';

		if ( null === $old || null === $extra || false === $wpdb->query( $old ) || ( '' !== $extra && false === $wpdb->query( $extra ) ) || '' !== $wpdb->last_error ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared above; a custom table.
			throw new DatabaseException( 'Could not delete old chat messages.' );
		}
	}

	/**
	 * The full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::ALERT_CHAT_LOG );
	}
}
