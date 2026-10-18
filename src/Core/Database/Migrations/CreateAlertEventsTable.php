<?php
/**
 * Schema version 18: purchase alerts (ADR-065).
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
 * `merchboard_alert_events`: one row per paid order line and creator, with
 * what the alert shows (order number, product, variation, quantity, image
 * and categories) and the customer's first name and voluntary username, as
 * at the time the order was paid. `UNIQUE (order_item_id, creator_id)`
 * gives at most one alert per line and creator, also when the order goes
 * from processing to completed. The category ids are a JSON list. No
 * defaults on text columns (MySQL before 8.0.13 rejects them). Nothing
 * existing is changed. Idempotent: dbDelta().
 */
final class CreateAlertEventsTable implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When the table does not exist afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table = Tables::name( $wpdb, Tables::ALERT_EVENTS );

		dbDelta( self::schema( $table, $wpdb->get_charset_collate() ) );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			throw new DatabaseException( 'Could not create the alert events table.' );
		}
	}

	/**
	 * CREATE TABLE statement in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
order_id bigint(20) unsigned NOT NULL,
order_number varchar(64) NOT NULL,
order_item_id bigint(20) unsigned NOT NULL,
product_id bigint(20) unsigned NOT NULL,
variation_id bigint(20) unsigned NOT NULL,
product_name varchar(255) NOT NULL,
variation varchar(255) NOT NULL,
quantity int(10) unsigned NOT NULL,
image_id bigint(20) unsigned NOT NULL,
category_ids varchar(1000) NOT NULL,
customer_first_name varchar(191) NOT NULL,
customer_username varchar(191) NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY order_item_creator (order_item_id,creator_id),
KEY creator_created (creator_id,created_at,id),
KEY order_id (order_id)
) {$charset_collate};";
	}
}
