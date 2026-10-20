<?php
/**
 * Storage of the creators' chatbot settings and channels.
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
 * `merchboard_alert_chatbot` (schema version 20): one row per creator. A
 * creator without a row has the defaults (messages on, the default template,
 * no channel).
 */
final class ChatbotChannelRepository {

	private const COLUMNS = 'creator_id, enabled, template, broadcaster_id, broadcaster_login, authorized_at, subscription_id, subscription_status';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * A creator's settings (the defaults without a row).
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function find( int $creator_id ): ChatbotChannel {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d', $this->table(), $creator_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Constant column list; values are prepared.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the chatbot settings.' );
		}

		return is_array( $row ) ? self::hydrate( $row ) : new ChatbotChannel( $creator_id, true, '', '', '', 0 );
	}

	/**
	 * The creator whose channel this is, or null.
	 *
	 * @param string $broadcaster_id Twitch channel id.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function find_by_broadcaster( string $broadcaster_id ): ?ChatbotChannel {
		if ( '' === $broadcaster_id ) {
			return null;
		}

		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE broadcaster_id = %s ORDER BY id LIMIT 1', $this->table(), $broadcaster_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Constant column list; values are prepared.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the chatbot settings.' );
		}

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Every creator with a channel, by channel login.
	 *
	 * @return list<ChatbotChannel>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function connected(): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM %i WHERE broadcaster_id <> '' ORDER BY broadcaster_login", $this->table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Constant column list; values are prepared.

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the chatbot settings.' );
		}

		return array_map( self::hydrate( ... ), $rows );
	}

	/**
	 * Saves whether purchases are announced and the template.
	 *
	 * @param int    $creator_id Creator.
	 * @param bool   $enabled    Messages on or off.
	 * @param string $template   Template ('' for the default).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save_settings( int $creator_id, bool $enabled, string $template ): void {
		$this->upsert(
			$creator_id,
			array(
				'enabled'  => $enabled ? 1 : 0,
				'template' => $template,
			)
		);
	}

	/**
	 * Stores the channel the creator added the bot to.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $id         Twitch channel id.
	 * @param string $login      Twitch channel login.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function connect( int $creator_id, string $id, string $login ): void {
		$this->upsert(
			$creator_id,
			array(
				'broadcaster_id'      => $id,
				'broadcaster_login'   => $login,
				'authorized_at'       => gmdate( 'Y-m-d H:i:s' ),
				'subscription_id'     => '',
				'subscription_status' => '',
			)
		);
	}

	/**
	 * Forgets the creator's channel (settings stay).
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function disconnect( int $creator_id ): void {
		$this->upsert(
			$creator_id,
			array(
				'broadcaster_id'      => '',
				'broadcaster_login'   => '',
				'authorized_at'       => null,
				'subscription_id'     => '',
				'subscription_status' => '',
			)
		);
	}

	/**
	 * Stores the channel's chat subscription.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $id         Subscription id.
	 * @param string $status     Status as Twitch reported it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set_subscription( int $creator_id, string $id, string $status ): void {
		$this->upsert(
			$creator_id,
			array(
				'subscription_id'     => substr( $id, 0, 64 ),
				'subscription_status' => substr( $status, 0, 60 ),
			)
		);
	}

	/**
	 * Inserts or updates a creator's row.
	 *
	 * @param int                            $creator_id Creator.
	 * @param array<string, string|int|null> $values     Column values.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function upsert( int $creator_id, array $values ): void {
		$wpdb   = $this->wpdb;
		$now    = gmdate( 'Y-m-d H:i:s' );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE creator_id = %d', $this->table(), $creator_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.

		if ( null === $exists ) {
			$stored = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
				$this->table(),
				array_merge(
					array(
						'creator_id' => $creator_id,
						'enabled'    => 1,
						'template'   => '',
						'created_at' => $now,
					),
					$values,
					array( 'updated_at' => $now )
				)
			);
		} else {
			$stored = $wpdb->update( $this->table(), array_merge( $values, array( 'updated_at' => $now ) ), array( 'creator_id' => $creator_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
		}

		if ( false === $stored || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not store the chatbot settings.' );
		}
	}

	/**
	 * A settings row.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function hydrate( array $row ): ChatbotChannel {
		$authorized = is_string( $row['authorized_at'] ?? null ) ? strtotime( $row['authorized_at'] . ' UTC' ) : false;

		return new ChatbotChannel(
			(int) ( $row['creator_id'] ?? 0 ),
			'1' === (string) ( $row['enabled'] ?? '1' ),
			(string) ( $row['template'] ?? '' ),
			(string) ( $row['broadcaster_id'] ?? '' ),
			(string) ( $row['broadcaster_login'] ?? '' ),
			false === $authorized ? 0 : $authorized,
			(string) ( $row['subscription_id'] ?? '' ),
			(string) ( $row['subscription_status'] ?? '' )
		);
	}

	/**
	 * The full table name.
	 */
	private function table(): string {
		return Tables::name( $this->wpdb, Tables::ALERT_CHATBOT );
	}
}
