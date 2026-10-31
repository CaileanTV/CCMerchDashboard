<?php
/**
 * Schema version 40: the creators' requests to remove a legacy product.
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
 * Creates `{prefix}merchboard_product_removal_requests` with dbDelta()
 * (ADR-083; idempotent).
 */
final class CreateProductRemovalRequestsTable implements Migration {

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

		$table = Tables::name( $wpdb, Tables::PRODUCT_REMOVAL_REQUESTS );

		dbDelta( self::schema( $table, $wpdb->get_charset_collate() ) );

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		if ( $found !== $table ) {
			throw new DatabaseException( 'Could not create the product removal requests table.' );
		}
	}

	/**
	 * CREATE TABLE statement in dbDelta() format. `active_product_id` is set
	 * only while a request is pending, so the unique key allows one pending
	 * request per product. `product_name` is a copy: the product can be gone
	 * when the request is read. Times are UTC. No foreign keys (ADR-019/020).
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
wc_product_id bigint(20) unsigned NOT NULL,
active_product_id bigint(20) unsigned DEFAULT NULL,
product_name varchar(191) NOT NULL,
status varchar(16) NOT NULL,
note varchar(500) DEFAULT NULL,
requested_at datetime NOT NULL,
requested_by bigint(20) unsigned DEFAULT NULL,
handled_at datetime DEFAULT NULL,
handled_by bigint(20) unsigned DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY active_product_id (active_product_id),
KEY creator_status (creator_id,status),
KEY status_requested (status,requested_at)
) {$charset_collate};";
	}
}
