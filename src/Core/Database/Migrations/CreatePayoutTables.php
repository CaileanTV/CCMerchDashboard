<?php
/**
 * Schema version 9: payouts, their encrypted details and the settlement start (phase 23).
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
 * - `merchboard_payouts` (ADR-055): one row per payout request; amount,
 *   method and creator never change, the status only moves forward from
 *   `pending`. `UNIQUE(creator_id, idempotency_key)` makes a resent request
 *   return the stored one. The gift card columns follow in migration 10
 *   (AddPayoutGiftCards, ADR-057).
 * - `merchboard_payout_secrets` (ADR-056): the encrypted bank or PayPal
 *   details of a payout, emptied (`purged_at`) when it is no longer pending.
 * - `merchboard_creators.payout_start_at` (D6): the creator's settlement
 *   start; NULL = the whole history (ADR-054).
 *
 * The ledger, the snapshots and the stored tax are not touched. Idempotent:
 * dbDelta() for the tables, the column only when missing.
 */
final class CreatePayoutTables implements Migration {

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
		$payouts         = Tables::name( $wpdb, Tables::PAYOUTS );
		$secrets         = Tables::name( $wpdb, Tables::PAYOUT_SECRETS );
		$creators        = Tables::name( $wpdb, Tables::CREATORS );

		dbDelta( self::payouts_schema( $payouts, $charset_collate ) );
		dbDelta( self::secrets_schema( $secrets, $charset_collate ) );

		foreach ( array( $payouts, $secrets ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new DatabaseException( 'Could not create the payout tables.' );
			}
		}

		if ( ! self::has_start_column( $wpdb, $creators ) ) {
			$sql = $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN payout_start_at datetime DEFAULT NULL', $creators );

			if ( is_string( $sql ) ) {
				$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared above; versioned migration.
			}
		}

		if ( ! self::has_start_column( $wpdb, $creators ) ) {
			throw new DatabaseException( 'Could not add the settlement start column.' );
		}
	}

	/**
	 * CREATE TABLE statement for the payouts in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function payouts_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
creator_id bigint(20) unsigned NOT NULL,
currency char(3) NOT NULL,
amount decimal(19,4) NOT NULL,
price_decimals tinyint(3) unsigned NOT NULL,
method varchar(20) NOT NULL,
status varchar(20) NOT NULL,
destination_hint varchar(191) NOT NULL DEFAULT '',
idempotency_key varchar(64) NOT NULL,
requested_by bigint(20) unsigned DEFAULT NULL,
requested_at datetime NOT NULL,
decided_by bigint(20) unsigned DEFAULT NULL,
decided_at datetime DEFAULT NULL,
decision_reason varchar(500) DEFAULT NULL,
reference varchar(191) DEFAULT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY creator_idempotency (creator_id,idempotency_key),
KEY creator_status (creator_id,status,currency),
KEY status_requested (status,requested_at)
) {$charset_collate};";
	}

	/**
	 * CREATE TABLE statement for the encrypted payout details in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function secrets_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
payout_id bigint(20) unsigned NOT NULL,
kind varchar(20) NOT NULL,
ciphertext text DEFAULT NULL,
key_id varchar(32) NOT NULL,
created_at datetime NOT NULL,
purged_at datetime DEFAULT NULL,
PRIMARY KEY  (payout_id)
) {$charset_collate};";
	}

	/**
	 * Whether the creators table has `payout_start_at`.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full table name.
	 */
	private static function has_start_column( wpdb $wpdb, string $table ): bool {
		return 'payout_start_at' === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'payout_start_at' ) );
	}
}
