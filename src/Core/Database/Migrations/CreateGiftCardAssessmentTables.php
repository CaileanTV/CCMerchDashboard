<?php
/**
 * Schema version 12: the G1 gift card assessment tables (E3 T2).
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
 * - `merchboard_order_gift_card_assessments`: one row per assessed order
 *   (PK `order_id`), its current G1 status.
 * - `merchboard_order_gift_card_payouts`: the payouts whose gift card paid
 *   an order, by reference only (payout, provider and the card's id in the
 *   provider, as on `merchboard_payouts`); a gift card code is never stored,
 *   neither plain, encrypted nor hashed.
 * - `merchboard_order_gift_card_attempts`: diagnostic data of failed
 *   assessments; it decides nothing.
 * - `merchboard_g1_state`: the single activation row (`id` = 1), created
 *   inactive (every other column NULL).
 *
 * Nothing reads or writes these tables yet; G1 stays inactive. Safe to run
 * again: dbDelta() only adds what is missing, and the activation row is
 * inserted only when it does not exist, so an existing row (also an active
 * one kept by the opt-in uninstall and found again on reinstall) is never
 * changed. The Migrator stores the schema version only after up() returned.
 */
final class CreateGiftCardAssessmentTables implements Migration {

	/**
	 * Id of the single activation row.
	 */
	public const STATE_ROW = 1;

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a table or the activation row does not exist afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset_collate = $wpdb->get_charset_collate();
		$assessments     = Tables::name( $wpdb, Tables::ORDER_GIFT_CARD_ASSESSMENTS );
		$payouts         = Tables::name( $wpdb, Tables::ORDER_GIFT_CARD_PAYOUTS );
		$attempts        = Tables::name( $wpdb, Tables::ORDER_GIFT_CARD_ATTEMPTS );
		$state           = Tables::name( $wpdb, Tables::G1_STATE );

		dbDelta( self::assessments_schema( $assessments, $charset_collate ) );
		dbDelta( self::payouts_schema( $payouts, $charset_collate ) );
		dbDelta( self::attempts_schema( $attempts, $charset_collate ) );
		dbDelta( self::state_schema( $state, $charset_collate ) );

		foreach ( array( $assessments, $payouts, $attempts, $state ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new DatabaseException( 'Could not create the gift card assessment tables.' );
			}
		}

		self::ensure_state_row( $wpdb, $state );
	}

	/**
	 * Inserts the inactive activation row when it is missing; an existing row
	 * is left as it is. A concurrent migration may insert it first: whatever
	 * the insert did, the row must exist afterwards.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full name of the state table.
	 * @throws DatabaseException When the row does not exist afterwards.
	 */
	private static function ensure_state_row( wpdb $wpdb, string $table ): void {
		$sql = $wpdb->prepare( 'INSERT INTO %i (id) SELECT %d FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM %i WHERE id = %d)', $table, self::STATE_ROW, $table, self::STATE_ROW );

		if ( is_string( $sql ) ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		}

		if ( null === $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $table, self::STATE_ROW ) ) ) {
			throw new DatabaseException( 'Could not create the G1 activation row.' );
		}
	}

	/**
	 * CREATE TABLE statement for the assessments in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function assessments_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
order_id bigint(20) unsigned NOT NULL,
status varchar(16) NOT NULL,
reason_code varchar(32) DEFAULT NULL,
source varchar(12) DEFAULT NULL,
assessed_at datetime DEFAULT NULL,
assessed_by bigint(20) unsigned DEFAULT NULL,
decision_reason varchar(500) DEFAULT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (order_id),
KEY status_updated (status,updated_at)
) {$charset_collate};";
	}

	/**
	 * CREATE TABLE statement for the paying payouts in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function payouts_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
order_id bigint(20) unsigned NOT NULL,
payout_id bigint(20) unsigned NOT NULL,
creator_id bigint(20) unsigned NOT NULL,
gift_card_provider varchar(32) NOT NULL,
gift_card_external_id varchar(64) NOT NULL,
recorded_at datetime NOT NULL,
PRIMARY KEY  (order_id,payout_id),
KEY creator_id (creator_id),
KEY payout_id (payout_id)
) {$charset_collate};";
	}

	/**
	 * CREATE TABLE statement for the attempt data in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function attempts_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
order_id bigint(20) unsigned NOT NULL,
attempts int(10) unsigned NOT NULL,
last_attempt_at datetime NOT NULL,
last_error_code varchar(32) DEFAULT NULL,
PRIMARY KEY  (order_id)
) {$charset_collate};";
	}

	/**
	 * CREATE TABLE statement for the activation row in dbDelta() format.
	 *
	 * @param string $table           Full table name from Tables::name().
	 * @param string $charset_collate `$wpdb->get_charset_collate()`.
	 */
	public static function state_schema( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
id tinyint(3) unsigned NOT NULL,
cutoff_utc datetime DEFAULT NULL,
activated_at datetime DEFAULT NULL,
activated_by bigint(20) unsigned DEFAULT NULL,
PRIMARY KEY  (id)
) {$charset_collate};";
	}
}
