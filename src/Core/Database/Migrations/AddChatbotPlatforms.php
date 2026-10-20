<?php
/**
 * Schema version 21: the alert chatbot on several platforms (ADR-069).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Database\Migrations;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Migration;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the chatbot tables of version 20 platform-aware (Twitch and Kick,
 * phase 25 step 8.2):
 *
 * - `merchboard_alert_chatbot`: `platform` (existing rows: `twitch`), one row
 *   per creator and platform (`UNIQUE creator_platform` replaces
 *   `UNIQUE creator_id`), and the creator's encrypted Kick tokens with their
 *   expiry (ADR-069 pkt. 5; empty for Twitch, which needs no user token).
 * - `merchboard_alert_chat_messages`: `platform` in the message key, so a
 *   purchase is announced once per platform.
 * - `merchboard_alert_chat_log`: `platform` in the channel and message keys
 *   (channel and message ids of the platforms are separate namespaces).
 *
 * dbDelta() adds the columns and the new indexes; the replaced indexes are
 * dropped explicitly (dbDelta() never drops one). Idempotent; the result is
 * verified.
 */
final class AddChatbotPlatforms implements Migration {

	/**
	 * Indexes replaced by this migration, by table.
	 */
	private const REPLACED = array(
		Tables::ALERT_CHATBOT       => array( 'creator_id', 'broadcaster_id' ),
		Tables::ALERT_CHAT_MESSAGES => array( 'message' ),
		Tables::ALERT_CHAT_LOG      => array( 'message_id', 'broadcaster_id' ),
	);

	/**
	 * The unique index each table has afterwards.
	 */
	private const UNIQUE = array(
		Tables::ALERT_CHATBOT       => 'creator_platform',
		Tables::ALERT_CHAT_MESSAGES => 'platform_message',
		Tables::ALERT_CHAT_LOG      => 'platform_message_id',
	);

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a table lacks the platform column or still has a replaced index afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = $wpdb->get_charset_collate();

		foreach ( self::REPLACED as $name => $replaced ) {
			$table = Tables::name( $wpdb, $name );

			dbDelta( self::schema( $name, $table, $charset ) );

			foreach ( $replaced as $index ) {
				if ( self::has_index( $wpdb, $table, $index ) ) {
					$drop = $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, $index );

					if ( null !== $drop ) {
						$wpdb->query( $drop ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared above; a migration.
					}
				}
			}

			self::verify( $wpdb, $name, $table );
		}
	}

	/**
	 * CREATE TABLE statement in dbDelta() format (the tables after this
	 * migration).
	 *
	 * @param string $name            Tables constant.
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function schema( string $name, string $table, string $charset_collate ): string {
		return match ( $name ) {
			Tables::ALERT_CHATBOT => "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
platform varchar(10) NOT NULL DEFAULT 'twitch',
enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
template varchar(500) NOT NULL DEFAULT '',
broadcaster_id varchar(20) NOT NULL DEFAULT '',
broadcaster_login varchar(25) NOT NULL DEFAULT '',
authorized_at datetime NULL DEFAULT NULL,
subscription_id varchar(64) NOT NULL DEFAULT '',
subscription_status varchar(60) NOT NULL DEFAULT '',
access_token text NULL,
refresh_token text NULL,
token_expires datetime NULL DEFAULT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY creator_platform (creator_id,platform),
KEY platform_broadcaster (platform,broadcaster_id)
) {$charset_collate};",
			Tables::ALERT_CHAT_MESSAGES => "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
platform varchar(10) NOT NULL DEFAULT 'twitch',
order_id bigint(20) unsigned NOT NULL,
order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
status varchar(20) NOT NULL,
reason varchar(60) NOT NULL DEFAULT '',
attempts smallint(5) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY platform_message (creator_id,platform,order_id,order_item_id),
KEY creator_id (creator_id,id)
) {$charset_collate};",
			default => "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
platform varchar(10) NOT NULL DEFAULT 'twitch',
broadcaster_id varchar(20) NOT NULL,
message_id varchar(64) NOT NULL,
chatter_login varchar(25) NOT NULL DEFAULT '',
chatter_name varchar(64) NOT NULL DEFAULT '',
color varchar(7) NOT NULL DEFAULT '',
text varchar(600) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY platform_message_id (platform,message_id),
KEY platform_broadcaster (platform,broadcaster_id,id),
KEY created_at (created_at)
) {$charset_collate};",
		};
	}

	/**
	 * Requires the platform column and the new unique index, without the
	 * replaced indexes.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $name  Tables constant.
	 * @param string $table Full table name.
	 * @throws DatabaseException When the table is not as expected.
	 */
	private static function verify( wpdb $wpdb, string $name, string $table ): void {
		$column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'platform' ) );

		if ( null === $column || ! self::has_index( $wpdb, $table, self::UNIQUE[ $name ] ) ) {
			throw new DatabaseException( 'Could not add the platform to the alert chatbot tables.' );
		}

		foreach ( self::REPLACED[ $name ] as $index ) {
			if ( self::has_index( $wpdb, $table, $index ) ) {
				throw new DatabaseException( 'Could not replace an index of the alert chatbot tables.' );
			}
		}
	}

	/**
	 * Whether a table has an index.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full table name.
	 * @param string $index Index name.
	 * @phpstan-impure Reads the database.
	 */
	private static function has_index( wpdb $wpdb, string $table, string $index ): bool {
		return null !== $wpdb->get_row( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $index ) );
	}
}
