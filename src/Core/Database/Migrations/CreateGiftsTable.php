<?php
/**
 * Migration 50: Twitch gifts (phase 44, ADR-107).
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
 * Creates `merchboard_gifts`: one row per gift a viewer bought in a
 * creator's Twitch stream (ADR-107 pkt. 4), from the paid gift order to the
 * claimed or returned gift code.
 */
final class CreateGiftsTable implements Migration {

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

		$table = Tables::name( $wpdb, Tables::GIFTS );

		dbDelta( self::schema( $table, $wpdb->get_charset_collate() ) );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			throw new DatabaseException( 'Could not create the gifts table.' );
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
creator_id bigint(20) unsigned NOT NULL,
order_id bigint(20) unsigned NOT NULL,
product_id bigint(20) unsigned NOT NULL,
unit_price decimal(19,4) NOT NULL,
shipping_credit decimal(19,4) NOT NULL,
currency char(3) NOT NULL,
buyer_name varchar(50) NULL,
status varchar(12) NOT NULL,
draws tinyint(3) unsigned NOT NULL DEFAULT 0,
entrants longtext NULL,
window_ends datetime NULL,
winner_twitch_id varchar(20) NULL,
winner_login varchar(25) NULL,
won_at datetime NULL,
claim_deadline datetime NULL,
claim_token char(32) NULL,
coupon_id bigint(20) unsigned NULL,
claimed_order_id bigint(20) unsigned NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY order_id (order_id),
UNIQUE KEY claim_token (claim_token),
KEY creator_status (creator_id,status),
KEY status_deadline (status,claim_deadline)
) {$charset_collate};";
	}
}
