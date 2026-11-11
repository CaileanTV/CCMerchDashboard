<?php
/**
 * Migration 51: giveaway links (phase 47, ADR-110).
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
 * Creates `merchboard_giveaways` (one row per product a creator gives away,
 * how it is paid and where it stands) and lets a gift of `merchboard_gifts`
 * belong to a giveaway instead of a paid gift order: `order_id` becomes
 * NULL-able and `giveaway_id` is added (unique; NULL for the gifts viewers
 * bought). Existing gifts are unchanged. A payout adjustment may come from a
 * giveaway (`giveaway_id`): the charge when its code is used.
 */
final class CreateGiveawaysTable implements Migration {

	/**
	 * Creates the table and changes the gifts table.
	 *
	 * @param wpdb $wpdb Database.
	 * @throws DatabaseException When a change is missing afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table = Tables::name( $wpdb, Tables::GIVEAWAYS );

		dbDelta( self::schema( $table, $wpdb->get_charset_collate() ) );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			throw new DatabaseException( 'Could not create the giveaways table.' );
		}

		$gifts = Tables::name( $wpdb, Tables::GIFTS );

		if ( 'giveaway_id' !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $gifts, 'giveaway_id' ) ) ) {
			$wpdb->query( "ALTER TABLE `{$gifts}` MODIFY `order_id` bigint(20) unsigned NULL, ADD COLUMN `giveaway_id` bigint(20) unsigned NULL AFTER `order_id`, ADD UNIQUE KEY `giveaway_id` (`giveaway_id`)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Constant table name from Tables and constant definitions; versioned migration.
		}

		if ( 'giveaway_id' !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $gifts, 'giveaway_id' ) ) ) {
			throw new DatabaseException( 'Could not let gifts belong to a giveaway.' );
		}

		$adjustments = Tables::name( $wpdb, Tables::PAYOUT_ADJUSTMENTS );

		if ( 'giveaway_id' !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $adjustments, 'giveaway_id' ) ) ) {
			$wpdb->query( "ALTER TABLE `{$adjustments}` ADD COLUMN `giveaway_id` bigint(20) unsigned DEFAULT NULL, ADD UNIQUE KEY `giveaway_id` (`giveaway_id`)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Constant table name from Tables; versioned migration.
		}

		if ( 'giveaway_id' !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $adjustments, 'giveaway_id' ) ) ) {
			throw new DatabaseException( 'Could not let payout adjustments come from a giveaway.' );
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
product_id bigint(20) unsigned NOT NULL,
mode varchar(6) NOT NULL,
funding varchar(7) NOT NULL,
status varchar(16) NOT NULL,
unit_price decimal(19,4) NOT NULL,
shipping_credit decimal(19,4) NOT NULL,
cost decimal(19,4) NOT NULL,
currency char(3) NOT NULL,
window_seconds smallint(5) unsigned NOT NULL DEFAULT 60,
order_id bigint(20) unsigned NULL,
gift_id bigint(20) unsigned NULL,
adjustment_id bigint(20) unsigned NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY order_id (order_id),
KEY creator_status (creator_id,status),
KEY funding_status (funding,status)
) {$charset_collate};";
	}
}
