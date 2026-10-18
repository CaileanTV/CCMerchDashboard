<?php
/**
 * Schema version 17: the creators' own discount codes and the orders they
 * exclude (ADR-068).
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
 * Two tables (ADR-068, K1 and M2):
 *
 * - `merchboard_creator_codes`: one row per registration of a creator's own
 *   WooCommerce coupon, kept permanently. A registration is active while
 *   `valid_to` is NULL; ending it sets `valid_to` and clears the two
 *   `active_*` columns. Those columns hold the creator and the normalized
 *   code only while the registration is active, so their UNIQUE keys allow
 *   at most one active code per creator and one active creator per code
 *   (several NULLs are allowed), also when two requests race.
 * - `merchboard_order_exclusions`: the decision for one order, stored at its
 *   first assessment (`excluded`, or `open` when the order has settled rows)
 *   and never deleted automatically. The rows of an order listed here do not
 *   count in sales, profit, payouts or new settlement documents.
 *
 * No existing data is changed and no row is made for existing orders: a code
 * applies from its registration on (K1). Idempotent: dbDelta().
 */
final class CreateCreatorCodeTables implements Migration {

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

		$charset_collate = $wpdb->get_charset_collate();
		$codes           = Tables::name( $wpdb, Tables::CREATOR_CODES );
		$exclusions      = Tables::name( $wpdb, Tables::ORDER_EXCLUSIONS );

		dbDelta( self::codes_schema( $codes, $charset_collate ) );
		dbDelta( self::exclusions_schema( $exclusions, $charset_collate ) );

		foreach ( array( $codes, $exclusions ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new DatabaseException( 'Could not create the creator discount code tables.' );
			}
		}
	}

	/**
	 * CREATE TABLE statement of the registrations in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function codes_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
code varchar(191) NOT NULL,
coupon_id bigint(20) unsigned NOT NULL,
valid_from datetime NOT NULL,
valid_to datetime DEFAULT NULL,
active_creator_id bigint(20) unsigned DEFAULT NULL,
active_code varchar(191) DEFAULT NULL,
created_by bigint(20) unsigned DEFAULT NULL,
ended_by bigint(20) unsigned DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY active_creator (active_creator_id),
UNIQUE KEY active_code (active_code),
KEY code_period (code,valid_from),
KEY creator (creator_id,id)
) {$charset_collate};";
	}

	/**
	 * CREATE TABLE statement of the order exclusions in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function exclusions_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
order_id bigint(20) unsigned NOT NULL,
status varchar(20) NOT NULL,
reason varchar(20) NOT NULL,
code_id bigint(20) unsigned DEFAULT NULL,
creator_id bigint(20) unsigned DEFAULT NULL,
code varchar(191) NOT NULL,
coupon_deviation tinyint(1) unsigned NOT NULL DEFAULT 0,
order_created_at datetime NOT NULL,
assessed_at datetime NOT NULL,
PRIMARY KEY  (order_id),
KEY status_assessed (status,assessed_at),
KEY creator (creator_id)
) {$charset_collate};";
	}
}
