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
 * `merchboard_alert_chatbot` (schema versions 20 and 21): one row per
 * creator and platform. A creator without a row has the defaults (messages
 * on, the default template, no channel). The template is the creator's,
 * shared by the platforms (ADR-069 pkt. 2): it is written to every row of
 * the creator, and a new row starts with it.
 *
 * The creator's Kick tokens (ADR-069 pkt. 5) are stored here as ciphertext
 * (ChatCipher); this class never sees them in plain text and never returns
 * them with a channel.
 */
final class ChatbotChannelRepository {

	private const COLUMNS = 'platform, creator_id, enabled, template, broadcaster_id, broadcaster_login, authorized_at, subscription_id, subscription_status';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * A creator's settings on a platform (the defaults without a row).
	 *
	 * @param int          $creator_id Creator.
	 * @param ChatPlatform $platform   Platform.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function find( int $creator_id, ChatPlatform $platform ): ChatbotChannel {
		return $this->for_creator( $creator_id )[ $platform->value ];
	}

	/**
	 * A creator's settings on every platform, by platform value.
	 *
	 * @param int $creator_id Creator.
	 * @return array<string, ChatbotChannel>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function for_creator( int $creator_id ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d ORDER BY id', $this->table(), $creator_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Constant column list; values are prepared.

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the chatbot settings.' );
		}

		$found = array();

		foreach ( $rows as $row ) {
			$channel = self::hydrate( $row );

			if ( null !== $channel ) {
				$found[ $channel->platform->value ] = $channel;
			}
		}

		$template = self::shared_template( $found );
		$channels = array();

		foreach ( ChatPlatform::cases() as $platform ) {
			$channels[ $platform->value ] = $found[ $platform->value ] ?? new ChatbotChannel( $platform, $creator_id, true, $template, '', '', 0 );
		}

		return $channels;
	}

	/**
	 * The creator whose channel this is, or null.
	 *
	 * @param ChatPlatform $platform       Platform.
	 * @param string       $broadcaster_id Channel id at the platform.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function find_by_broadcaster( ChatPlatform $platform, string $broadcaster_id ): ?ChatbotChannel {
		if ( '' === $broadcaster_id ) {
			return null;
		}

		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE platform = %s AND broadcaster_id = %s ORDER BY id LIMIT 1', $this->table(), $platform->value, $broadcaster_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Constant column list; values are prepared.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the chatbot settings.' );
		}

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Every channel with the bot, by platform and channel login.
	 *
	 * @param ChatPlatform|null $platform Only this platform; null for all.
	 * @return list<ChatbotChannel>
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function connected( ?ChatPlatform $platform = null ): array {
		$wpdb = $this->wpdb;
		$rows = null === $platform
			? $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM %i WHERE broadcaster_id <> '' ORDER BY platform DESC, broadcaster_login", $this->table() ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Constant column list; values are prepared.
			: $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM %i WHERE platform = %s AND broadcaster_id <> '' ORDER BY broadcaster_login", $this->table(), $platform->value ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Constant column list; values are prepared.

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the chatbot settings.' );
		}

		return array_values( array_filter( array_map( self::hydrate( ... ), $rows ) ) );
	}

	/**
	 * Saves the creator's template on every platform.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $template   Template ('' for the default).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save_template( int $creator_id, string $template ): void {
		foreach ( ChatPlatform::cases() as $platform ) {
			$this->upsert( $creator_id, $platform, array( 'template' => $template ) );
		}
	}

	/**
	 * Saves whether purchases are announced on a platform.
	 *
	 * @param int          $creator_id Creator.
	 * @param ChatPlatform $platform   Platform.
	 * @param bool         $enabled    Messages on or off.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save_enabled( int $creator_id, ChatPlatform $platform, bool $enabled ): void {
		$this->upsert( $creator_id, $platform, array( 'enabled' => $enabled ? 1 : 0 ) );
	}

	/**
	 * Stores the channel the creator added the bot to.
	 *
	 * @param int          $creator_id Creator.
	 * @param ChatPlatform $platform   Platform.
	 * @param string       $id         Channel id at the platform.
	 * @param string       $login      Channel login (Twitch) or slug (Kick).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function connect( int $creator_id, ChatPlatform $platform, string $id, string $login ): void {
		$this->upsert(
			$creator_id,
			$platform,
			array(
				'broadcaster_id'      => substr( $id, 0, 64 ),
				'broadcaster_login'   => mb_substr( $login, 0, 100 ),
				'authorized_at'       => gmdate( 'Y-m-d H:i:s' ),
				'subscription_id'     => '',
				'subscription_status' => '',
			)
		);
	}

	/**
	 * Forgets the creator's channel and its tokens (settings stay).
	 *
	 * @param int          $creator_id Creator.
	 * @param ChatPlatform $platform   Platform.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function disconnect( int $creator_id, ChatPlatform $platform ): void {
		$this->upsert(
			$creator_id,
			$platform,
			array(
				'broadcaster_id'      => '',
				'broadcaster_login'   => '',
				'authorized_at'       => null,
				'subscription_id'     => '',
				'subscription_status' => '',
				'access_token'        => null,
				'refresh_token'       => null,
				'token_expires'       => null,
			)
		);
	}

	/**
	 * Stores the channel's chat subscription.
	 *
	 * @param int          $creator_id Creator.
	 * @param ChatPlatform $platform   Platform.
	 * @param string       $id         Subscription id.
	 * @param string       $status     Status as the platform reported it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function set_subscription( int $creator_id, ChatPlatform $platform, string $id, string $status ): void {
		$this->upsert(
			$creator_id,
			$platform,
			array(
				'subscription_id'     => substr( $id, 0, 64 ),
				'subscription_status' => substr( $status, 0, 60 ),
			)
		);
	}

	/**
	 * Stores a channel's tokens as ciphertext, both in one write (a rotated
	 * refresh token is never lost).
	 *
	 * @param int          $creator_id Creator.
	 * @param ChatPlatform $platform   Platform.
	 * @param string       $access     Encrypted access token.
	 * @param string       $refresh    Encrypted refresh token.
	 * @param int          $expires    When the access token expires (Unix time).
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function store_tokens( int $creator_id, ChatPlatform $platform, string $access, string $refresh, int $expires ): void {
		$this->upsert(
			$creator_id,
			$platform,
			array(
				'access_token'  => $access,
				'refresh_token' => $refresh,
				'token_expires' => gmdate( 'Y-m-d H:i:s', $expires ),
			)
		);
	}

	/**
	 * A channel's tokens as ciphertext, or null when there are none.
	 *
	 * @param int          $creator_id Creator.
	 * @param ChatPlatform $platform   Platform.
	 * @return array{access: string, refresh: string, expires: int}|null
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function tokens( int $creator_id, ChatPlatform $platform ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT access_token, refresh_token, token_expires FROM %i WHERE creator_id = %d AND platform = %s', $this->table(), $creator_id, $platform->value ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the chatbot settings.' );
		}

		if ( ! is_array( $row ) || ! is_string( $row['access_token'] ?? null ) || '' === $row['access_token'] || ! is_string( $row['refresh_token'] ?? null ) ) {
			return null;
		}

		$expires = is_string( $row['token_expires'] ?? null ) ? strtotime( $row['token_expires'] . ' UTC' ) : false;

		return array(
			'access'  => $row['access_token'],
			'refresh' => $row['refresh_token'],
			'expires' => false === $expires ? 0 : $expires,
		);
	}

	/**
	 * Inserts or updates a creator's row of a platform. A new row starts
	 * with the creator's shared template.
	 *
	 * @param int                            $creator_id Creator.
	 * @param ChatPlatform                   $platform   Platform.
	 * @param array<string, string|int|null> $values     Column values.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function upsert( int $creator_id, ChatPlatform $platform, array $values ): void {
		$wpdb   = $this->wpdb;
		$now    = gmdate( 'Y-m-d H:i:s' );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE creator_id = %d AND platform = %s', $this->table(), $creator_id, $platform->value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.

		if ( null === $exists ) {
			$template = $wpdb->get_var( $wpdb->prepare( 'SELECT template FROM %i WHERE creator_id = %d ORDER BY id LIMIT 1', $this->table(), $creator_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
			$stored   = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
				$this->table(),
				array_merge(
					array(
						'creator_id' => $creator_id,
						'platform'   => $platform->value,
						'enabled'    => 1,
						'template'   => is_string( $template ) ? $template : '',
						'created_at' => $now,
					),
					$values,
					array( 'updated_at' => $now )
				)
			);
		} else {
			$stored = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A custom table.
				$this->table(),
				array_merge( $values, array( 'updated_at' => $now ) ),
				array(
					'creator_id' => $creator_id,
					'platform'   => $platform->value,
				)
			);
		}

		if ( false === $stored || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not store the chatbot settings.' );
		}
	}

	/**
	 * The creator's template from the rows found ('' for none).
	 *
	 * @param array<string, ChatbotChannel> $channels Rows by platform.
	 */
	private static function shared_template( array $channels ): string {
		foreach ( $channels as $channel ) {
			if ( '' !== $channel->template ) {
				return $channel->template;
			}
		}

		return '';
	}

	/**
	 * A settings row; null for an unknown platform.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function hydrate( array $row ): ?ChatbotChannel {
		$platform   = ChatPlatform::parse( $row['platform'] ?? 'twitch' );
		$authorized = is_string( $row['authorized_at'] ?? null ) ? strtotime( $row['authorized_at'] . ' UTC' ) : false;

		if ( null === $platform ) {
			return null;
		}

		return new ChatbotChannel(
			$platform,
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
