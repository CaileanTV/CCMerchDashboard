<?php
/**
 * Schema version 27: the creators' webhooks of the alert API (ADR-073, step 25.12.2).
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
 * `merchboard_alert_webhooks`: the addresses a creator's alerts are sent to
 * (JSON or Discord), with the address and the signing secret encrypted, the
 * host and a short hint for the list, the Discord template, whether it is on
 * and how many deliveries failed in a row (paused after 10).
 *
 * `merchboard_alert_webhook_deliveries`: the deliveries of a webhook (the
 * newest 50 are kept): the event id, the kind, the body that was sent and
 * the outcome.
 *
 * Idempotent: dbDelta() and a table check afterwards.
 */
final class CreateAlertWebhookTables implements Migration {

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

		foreach ( array( Tables::ALERT_WEBHOOKS, Tables::ALERT_WEBHOOK_DELIVERIES ) as $name ) {
			$table = Tables::name( $wpdb, $name );

			dbDelta( self::schema( $name, $table, $wpdb->get_charset_collate() ) );

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new DatabaseException( 'Could not create the alert webhook tables.' );
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
		if ( Tables::ALERT_WEBHOOK_DELIVERIES === $name ) {
			return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
webhook_id bigint(20) unsigned NOT NULL,
creator_id bigint(20) unsigned NOT NULL,
event_id varchar(40) NOT NULL,
kind varchar(10) NOT NULL,
order_ref char(16) NOT NULL DEFAULT '',
payload longtext NOT NULL,
status varchar(10) NOT NULL,
http_status smallint(5) unsigned NULL,
error varchar(190) NULL,
created_at datetime NOT NULL,
sent_at datetime NULL,
PRIMARY KEY  (id),
KEY webhook_id (webhook_id,id),
KEY creator_id (creator_id,id)
) {$charset_collate};";
		}

		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
name varchar(60) NOT NULL,
format varchar(10) NOT NULL,
url_encrypted text NOT NULL,
url_host varchar(255) NOT NULL,
url_hint varchar(24) NOT NULL,
secret_encrypted text NULL,
template varchar(500) NOT NULL DEFAULT '',
enabled tinyint(1) NOT NULL DEFAULT 1,
failures int(10) unsigned NOT NULL DEFAULT 0,
paused_at datetime NULL,
last_delivery_at datetime NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY creator_id (creator_id)
) {$charset_collate};";
	}
}
