<?php
/**
 * Schema version 20: the alert chatbot (ADR-066).
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
 * The Twitch chatbot of the alerts (phase 25, step 8):
 *
 * - `merchboard_alert_chatbot`: one row per creator: messages on or off,
 *   the template, and the Twitch channel the creator added the bot to
 *   (id and login only; the creator's token is not stored) with the
 *   channel's EventSub chat subscription.
 * - `merchboard_alert_chat_messages`: the message log, one row per creator,
 *   order and line (line 0: the whole order), so a message is never sent
 *   twice for the same purchase.
 * - `merchboard_alert_chat_log`: the latest chat messages of the channels
 *   (EventSub), for the admin's chat window; kept for 24 hours, at most 200
 *   per channel.
 *
 * Idempotent: dbDelta().
 */
final class CreateAlertChatbotTables implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a table does not exist afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = $wpdb->get_charset_collate();

		foreach ( array( Tables::ALERT_CHATBOT, Tables::ALERT_CHAT_MESSAGES, Tables::ALERT_CHAT_LOG ) as $name ) {
			$table = Tables::name( $wpdb, $name );

			dbDelta( self::schema( $name, $table, $charset ) );

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new DatabaseException( 'Could not create the alert chatbot tables.' );
			}
		}
	}

	/**
	 * CREATE TABLE statement in dbDelta() format.
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
enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
template varchar(500) NOT NULL DEFAULT '',
broadcaster_id varchar(20) NOT NULL DEFAULT '',
broadcaster_login varchar(25) NOT NULL DEFAULT '',
authorized_at datetime NULL DEFAULT NULL,
subscription_id varchar(64) NOT NULL DEFAULT '',
subscription_status varchar(60) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY creator_id (creator_id),
KEY broadcaster_id (broadcaster_id)
) {$charset_collate};",
			Tables::ALERT_CHAT_MESSAGES => "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
order_id bigint(20) unsigned NOT NULL,
order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
status varchar(20) NOT NULL,
reason varchar(60) NOT NULL DEFAULT '',
attempts smallint(5) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY message (creator_id,order_id,order_item_id),
KEY creator_id (creator_id,id)
) {$charset_collate};",
			default => "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
broadcaster_id varchar(20) NOT NULL,
message_id varchar(64) NOT NULL,
chatter_login varchar(25) NOT NULL DEFAULT '',
chatter_name varchar(64) NOT NULL DEFAULT '',
color varchar(7) NOT NULL DEFAULT '',
text varchar(600) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY message_id (message_id),
KEY broadcaster_id (broadcaster_id,id),
KEY created_at (created_at)
) {$charset_collate};",
		};
	}
}
