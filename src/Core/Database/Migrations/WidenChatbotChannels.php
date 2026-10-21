<?php
/**
 * Schema version 22: room for YouTube channels in the alert chatbot (ADR-070).
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
 * Widens the channel columns of the chatbot tables (phase 25, step 8.4):
 * a YouTube channel id has 24 characters (`UC…`) and a channel name can be
 * long, while version 20 sized them for Twitch (`broadcaster_id`
 * varchar(20), `broadcaster_login` varchar(25)).
 *
 * - `merchboard_alert_chatbot`: `broadcaster_id` varchar(64),
 *   `broadcaster_login` varchar(100).
 * - `merchboard_alert_chat_log`: `broadcaster_id` varchar(64).
 *
 * dbDelta() changes the column types; existing values stay. Idempotent. The
 * widths are verified where the database reports them (information_schema).
 */
final class WidenChatbotChannels implements Migration {

	/**
	 * Expected widths after the migration, by table and column.
	 */
	private const WIDTHS = array(
		Tables::ALERT_CHATBOT  => array(
			'broadcaster_id'    => 64,
			'broadcaster_login' => 100,
		),
		Tables::ALERT_CHAT_LOG => array(
			'broadcaster_id' => 64,
		),
	);

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a column is still narrower afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = $wpdb->get_charset_collate();

		foreach ( self::WIDTHS as $name => $columns ) {
			$table = Tables::name( $wpdb, $name );

			dbDelta( self::schema( $name, $table, $charset ) );

			foreach ( $columns as $column => $width ) {
				$length = $wpdb->get_var( $wpdb->prepare( 'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $table, $column ) );

				// Databases without information_schema (e.g. SQLite in Playground) report nothing; dbDelta() did the work.
				if ( is_numeric( $length ) && (int) $length < $width ) {
					throw new DatabaseException( 'Could not widen the alert chatbot channel columns.' );
				}
			}
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
		return Tables::ALERT_CHATBOT === $name
			? "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
platform varchar(10) NOT NULL DEFAULT 'twitch',
enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
template varchar(500) NOT NULL DEFAULT '',
broadcaster_id varchar(64) NOT NULL DEFAULT '',
broadcaster_login varchar(100) NOT NULL DEFAULT '',
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
) {$charset_collate};"
			: "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
platform varchar(10) NOT NULL DEFAULT 'twitch',
broadcaster_id varchar(64) NOT NULL,
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
) {$charset_collate};";
	}
}
