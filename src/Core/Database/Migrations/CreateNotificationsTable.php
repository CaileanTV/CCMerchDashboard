<?php
/**
 * Migration 34: the notifications table (phase 29, ADR-079).
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
 * Creates `merchboard_notifications`: one row per notification, for a
 * creator or the administrator.
 *
 * - `type` is a key of the catalog (Modules\Notifications\NotificationTypes),
 *   `data` its JSON values (never secrets).
 * - `dedupe_key` is unique: the same event (e.g. an order synchronized
 *   again) gives one notification. NULL for events that cannot repeat.
 * - `in_dashboard` and `read_at`: whether it is shown at the creator's bell
 *   and when it was read.
 * - `email_status` (`none`, `pending`, `sent`, `failed`, `skipped`),
 *   `email_attempts`, `email_sent_at` and `email_error` (a short reason,
 *   never the message).
 */
final class CreateNotificationsTable implements Migration {

	/**
	 * Creates the table.
	 *
	 * @param wpdb $wpdb Database.
	 * @throws DatabaseException When the table is missing afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table = Tables::name( $wpdb, Tables::NOTIFICATIONS );

		dbDelta( self::schema( $table, $wpdb->get_charset_collate() ) );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			throw new DatabaseException( 'Could not create the notifications table.' );
		}
	}

	/**
	 * The table's definition (dbDelta format).
	 *
	 * @param string $table           Prefixed table name.
	 * @param string $charset_collate Charset and collation.
	 */
	public static function schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
type varchar(64) NOT NULL,
audience varchar(10) NOT NULL,
creator_id bigint(20) unsigned NULL,
data longtext NOT NULL,
dedupe_key varchar(191) NULL,
in_dashboard tinyint(1) NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
read_at datetime NULL,
email_status varchar(10) NOT NULL DEFAULT 'none',
email_attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
email_sent_at datetime NULL,
email_error varchar(191) NULL,
PRIMARY KEY  (id),
UNIQUE KEY dedupe_key (dedupe_key),
KEY creator_dashboard (creator_id,in_dashboard,read_at),
KEY created_at (created_at),
KEY email_status (email_status)
) {$charset_collate};";
	}
}
