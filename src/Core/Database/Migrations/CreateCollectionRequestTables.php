<?php
/**
 * Schema version 53: the creators' requests to have designs collected.
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
 * Creates `{prefix}merchboard_collection_requests` and
 * `{prefix}merchboard_collection_request_items` with dbDelta() (ADR-120;
 * idempotent).
 */
final class CreateCollectionRequestTables implements Migration {

	/**
	 * Applies the migration.
	 *
	 * The names are built without Tables::name(), which refuses a table that
	 * is not in Tables::all(): the migration must be able to run before that
	 * list names the tables, as a listed table that is missing stops every
	 * transaction.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a table does not exist afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$requests = $wpdb->prefix . Tables::COLLECTION_REQUESTS;
		$items    = $wpdb->prefix . Tables::COLLECTION_REQUEST_ITEMS;
		$collate  = $wpdb->get_charset_collate();

		dbDelta( self::requests_schema( $requests, $collate ) );
		dbDelta( self::items_schema( $items, $collate ) );

		foreach ( array( $requests, $items ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

			if ( $found !== $table ) {
				throw new DatabaseException( 'Could not create the collection request tables.' );
			}
		}
	}

	/**
	 * The requests, in dbDelta() format. `kind` is a new collection or an
	 * addition to `collection_id`, which a new one gets when its draft is
	 * made; `token` marks that draft, so approving again never makes two.
	 * `name` is the creator's, or a copy of the collection's. Times are UTC.
	 * No foreign keys (ADR-019/020).
	 *
	 * @param string $table           Full table name.
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function requests_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
kind varchar(16) NOT NULL,
name varchar(191) NOT NULL,
collection_id bigint(20) unsigned DEFAULT NULL,
token char(32) NOT NULL,
status varchar(16) NOT NULL,
note varchar(500) DEFAULT NULL,
requested_at datetime NOT NULL,
requested_by bigint(20) unsigned DEFAULT NULL,
approved_at datetime DEFAULT NULL,
approved_by bigint(20) unsigned DEFAULT NULL,
closed_at datetime DEFAULT NULL,
closed_by bigint(20) unsigned DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY token (token),
KEY creator_status (creator_id,status),
KEY status_requested (status,requested_at),
KEY collection_status (collection_id,status)
) {$charset_collate};";
	}

	/**
	 * The requests' designs, in dbDelta() format. `active_product_id` is set
	 * only while the request is open, so the unique key allows one open
	 * request per product. `product_name` is a copy: the product can be gone
	 * when the request is read.
	 *
	 * @param string $table           Full table name.
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function items_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
request_id bigint(20) unsigned NOT NULL,
wc_product_id bigint(20) unsigned NOT NULL,
active_product_id bigint(20) unsigned DEFAULT NULL,
product_name varchar(191) NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY request_product (request_id,wc_product_id),
UNIQUE KEY active_product_id (active_product_id)
) {$charset_collate};";
	}
}
