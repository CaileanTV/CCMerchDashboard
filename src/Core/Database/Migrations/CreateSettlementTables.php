<?php
/**
 * Schema version 11: settlement documents (phase 23, ADR-060).
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
 * - `merchboard_settlement_documents`: one immutable document per completed
 *   payout (`UNIQUE(settlement_payout_id)`, D8) or a correction of an
 *   earlier one (`UNIQUE(corrects_document_id)`, D11); `UNIQUE(document_number)`.
 * - `merchboard_settlement_document_rows`: the ledger rows a document
 *   settled, with their values as a snapshot; `UNIQUE(event_key)` settles a
 *   ledger row at most once.
 * - `merchboard_settlement_counters`: the next number per series (D9).
 *
 * Nothing existing is changed; no document is created for earlier payouts
 * (060-8). Idempotent: dbDelta().
 */
final class CreateSettlementTables implements Migration {

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
		$documents       = Tables::name( $wpdb, Tables::SETTLEMENT_DOCUMENTS );
		$rows            = Tables::name( $wpdb, Tables::SETTLEMENT_ROWS );
		$counters        = Tables::name( $wpdb, Tables::SETTLEMENT_COUNTERS );

		dbDelta( self::documents_schema( $documents, $charset_collate ) );
		dbDelta( self::rows_schema( $rows, $charset_collate ) );
		dbDelta( self::counters_schema( $counters, $charset_collate ) );

		foreach ( array( $documents, $rows, $counters ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new DatabaseException( 'Could not create the settlement tables.' );
			}
		}
	}

	/**
	 * CREATE TABLE statement for the documents in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function documents_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
document_number varchar(32) NOT NULL,
document_series varchar(10) NOT NULL,
document_type varchar(12) NOT NULL,
creator_id bigint(20) unsigned NOT NULL,
payout_id bigint(20) unsigned DEFAULT NULL,
settlement_payout_id bigint(20) unsigned DEFAULT NULL,
corrects_document_id bigint(20) unsigned DEFAULT NULL,
currency char(3) NOT NULL,
period_from datetime DEFAULT NULL,
period_to datetime NOT NULL,
profit_amount decimal(19,4) NOT NULL,
commission_amount decimal(19,4) NOT NULL,
opening_balance decimal(19,4) NOT NULL,
payout_amount decimal(19,4) NOT NULL,
closing_balance decimal(19,4) NOT NULL,
vat_registered tinyint(1) unsigned DEFAULT NULL,
calculation_version varchar(32) NOT NULL,
template_version smallint(5) unsigned NOT NULL,
snapshot longtext NOT NULL,
issued_by bigint(20) unsigned DEFAULT NULL,
issued_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY document_number (document_number),
UNIQUE KEY settlement_payout (settlement_payout_id),
UNIQUE KEY corrects_document (corrects_document_id),
KEY creator_currency (creator_id,currency,id),
KEY payout_id (payout_id)
) {$charset_collate};";
	}

	/**
	 * CREATE TABLE statement for the settled rows in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function rows_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
event_key varchar(64) NOT NULL,
document_id bigint(20) unsigned NOT NULL,
order_id bigint(20) unsigned NOT NULL,
order_item_id bigint(20) unsigned NOT NULL,
product_id bigint(20) unsigned NOT NULL,
variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
item_name varchar(191) NOT NULL DEFAULT '',
type varchar(10) NOT NULL,
quantity int(11) NOT NULL,
net_amount decimal(19,4) NOT NULL,
tax_amount decimal(19,4) NOT NULL,
share_bps smallint(5) unsigned NOT NULL,
vat_registered tinyint(1) unsigned NOT NULL,
price_decimals tinyint(3) unsigned NOT NULL,
profit_amount decimal(19,4) NOT NULL,
payout_amount decimal(19,4) NOT NULL,
occurred_at datetime NOT NULL,
PRIMARY KEY  (event_key),
KEY document_id (document_id),
KEY order_id (order_id)
) {$charset_collate};";
	}

	/**
	 * CREATE TABLE statement for the number counters in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function counters_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
series varchar(10) NOT NULL,
next_value bigint(20) unsigned NOT NULL,
PRIMARY KEY  (series)
) {$charset_collate};";
	}
}
