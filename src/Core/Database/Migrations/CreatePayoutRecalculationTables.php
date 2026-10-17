<?php
/**
 * Schema version 14: recalculations of settled payouts and payout adjustments (ADR-063).
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
 * What the regulation of ADR-063 needs:
 *
 * - `merchboard_payout_recalculations`: one row per recalculation (preview),
 *   with its status, decision, execution and closing; never deleted.
 * - `merchboard_payout_recalculation_items`: per recalculation, creator,
 *   currency and group the original, recalculated and difference amounts
 *   and the settled rows they come from (`UNIQUE(recalculation_id,
 *   creator_id, currency, category)`).
 * - `merchboard_payout_adjustments`: an executed adjustment of a creator's
 *   balance; `UNIQUE(recalculation_item_id)` executes an item at most once.
 *   The settlement document that settles it is stored in
 *   `settled_document_id`.
 * - `merchboard_payout_adjustment_rows`: the settled rows an adjustment
 *   regulated; the primary key (`rule`, `event_key`) regulates a row at most
 *   once per rule, across all recalculations.
 * - `merchboard_settlement_documents.adjustment_amount`: the adjustments a
 *   document settled (0 for every existing document, so closing = opening +
 *   commission + adjustment − payout holds for them unchanged).
 *
 * No existing amount, document or payout is changed. Idempotent: dbDelta()
 * and a column that is added only when missing.
 */
final class CreatePayoutRecalculationTables implements Migration {

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a table or the column does not exist afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset_collate = $wpdb->get_charset_collate();
		$tables          = array(
			Tables::name( $wpdb, Tables::RECALCULATIONS ) => self::recalculations_schema( Tables::name( $wpdb, Tables::RECALCULATIONS ), $charset_collate ),
			Tables::name( $wpdb, Tables::RECALCULATION_ITEMS ) => self::items_schema( Tables::name( $wpdb, Tables::RECALCULATION_ITEMS ), $charset_collate ),
			Tables::name( $wpdb, Tables::PAYOUT_ADJUSTMENTS ) => self::adjustments_schema( Tables::name( $wpdb, Tables::PAYOUT_ADJUSTMENTS ), $charset_collate ),
			Tables::name( $wpdb, Tables::PAYOUT_ADJUSTMENT_ROWS ) => self::adjustment_rows_schema( Tables::name( $wpdb, Tables::PAYOUT_ADJUSTMENT_ROWS ), $charset_collate ),
		);

		foreach ( $tables as $table => $schema ) {
			dbDelta( $schema );

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new DatabaseException( 'Could not create the recalculation tables.' );
			}
		}

		$documents = Tables::name( $wpdb, Tables::SETTLEMENT_DOCUMENTS );

		if ( ! self::has_column( $wpdb, $documents ) ) {
			$sql = $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN adjustment_amount decimal(19,4) NOT NULL DEFAULT 0 AFTER commission_amount', $documents );

			if ( is_string( $sql ) ) {
				$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared above; versioned migration.
			}
		}

		if ( ! self::has_column( $wpdb, $documents ) ) {
			throw new DatabaseException( 'Could not add the adjustment column to the settlement documents.' );
		}
	}

	/**
	 * Whether the documents have `adjustment_amount`.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full table name.
	 */
	private static function has_column( wpdb $wpdb, string $table ): bool {
		return 'adjustment_amount' === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'adjustment_amount' ) );
	}

	/**
	 * CREATE TABLE statement for the recalculations in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function recalculations_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
rule varchar(32) NOT NULL,
calculation_version varchar(32) NOT NULL,
status varchar(20) NOT NULL,
fingerprint char(64) NOT NULL,
reopens_id bigint(20) unsigned DEFAULT NULL,
created_by bigint(20) unsigned DEFAULT NULL,
created_at datetime NOT NULL,
decided_by bigint(20) unsigned DEFAULT NULL,
decided_at datetime DEFAULT NULL,
decision_note varchar(500) NOT NULL DEFAULT '',
closed_by bigint(20) unsigned DEFAULT NULL,
closed_at datetime DEFAULT NULL,
close_note varchar(500) NOT NULL DEFAULT '',
PRIMARY KEY  (id),
KEY status (status)
) {$charset_collate};";
	}

	/**
	 * CREATE TABLE statement for the items in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function items_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
recalculation_id bigint(20) unsigned NOT NULL,
creator_id bigint(20) unsigned NOT NULL,
currency char(3) NOT NULL,
category varchar(12) NOT NULL,
reason varchar(32) NOT NULL DEFAULT '',
original_amount decimal(19,4) DEFAULT NULL,
recalculated_amount decimal(19,4) DEFAULT NULL,
difference_amount decimal(19,4) DEFAULT NULL,
row_count int(10) unsigned NOT NULL DEFAULT 0,
detail longtext NOT NULL,
fingerprint char(64) NOT NULL,
status varchar(12) NOT NULL,
failure varchar(32) NOT NULL DEFAULT '',
executed_by bigint(20) unsigned DEFAULT NULL,
executed_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY recalculation_creator (recalculation_id,creator_id,currency,category),
KEY creator_id (creator_id)
) {$charset_collate};";
	}

	/**
	 * CREATE TABLE statement for the adjustments in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function adjustments_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
currency char(3) NOT NULL,
amount decimal(19,4) NOT NULL,
rule varchar(32) NOT NULL,
recalculation_id bigint(20) unsigned NOT NULL,
recalculation_item_id bigint(20) unsigned NOT NULL,
created_by bigint(20) unsigned DEFAULT NULL,
created_at datetime NOT NULL,
settled_document_id bigint(20) unsigned DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY recalculation_item (recalculation_item_id),
KEY creator_currency (creator_id,currency,settled_document_id)
) {$charset_collate};";
	}

	/**
	 * CREATE TABLE statement for the regulated rows in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function adjustment_rows_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
rule varchar(32) NOT NULL,
event_key varchar(64) NOT NULL,
adjustment_id bigint(20) unsigned NOT NULL,
original_amount decimal(19,4) NOT NULL,
recalculated_amount decimal(19,4) NOT NULL,
PRIMARY KEY  (rule,event_key),
KEY adjustment_id (adjustment_id)
) {$charset_collate};";
	}
}
