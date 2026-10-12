<?php
/**
 * Schema version 10: the gift card of a payout (phase 23, ADR-057).
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
 * Adds `gift_card_provider` and `gift_card_external_id` to
 * `merchboard_payouts` (NULL for bank and PayPal) and
 * `UNIQUE(gift_card_provider, gift_card_external_id)`, so one card is never
 * registered on two payouts, also by concurrent requests. Only the provider
 * id and the card's id in the provider are stored, never its code.
 * Existing rows are not changed. Idempotent: each column and the index only
 * when missing.
 */
final class AddPayoutGiftCards implements Migration {

	/**
	 * Name of the unique index.
	 */
	public const INDEX = 'gift_card';

	/**
	 * Applies the migration.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 * @throws DatabaseException When a column or the index does not exist afterwards.
	 */
	public function up( wpdb $wpdb ): void {
		$table = Tables::name( $wpdb, Tables::PAYOUTS );
		$ddl   = array(
			'gift_card_provider'    => 'ALTER TABLE %i ADD COLUMN gift_card_provider varchar(32) DEFAULT NULL',
			'gift_card_external_id' => 'ALTER TABLE %i ADD COLUMN gift_card_external_id varchar(64) DEFAULT NULL',
		);

		foreach ( $ddl as $column => $statement ) {
			if ( ! self::has_column( $wpdb, $table, $column ) ) {
				$sql = $wpdb->prepare( $statement, $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant statement.

				if ( is_string( $sql ) ) {
					$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared above; versioned migration.
				}
			}

			if ( ! self::has_column( $wpdb, $table, $column ) ) {
				throw new DatabaseException( 'Could not add the payout gift card columns.' );
			}
		}

		if ( ! self::has_index( $wpdb, $table ) ) {
			$sql = $wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY %i (gift_card_provider, gift_card_external_id)', $table, self::INDEX );

			if ( is_string( $sql ) ) {
				$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared above; versioned migration.
			}
		}

		if ( ! self::has_index( $wpdb, $table ) ) {
			throw new DatabaseException( 'Could not add the payout gift card index.' );
		}
	}

	/**
	 * Whether the table has a column.
	 *
	 * @param wpdb   $wpdb   WordPress database object.
	 * @param string $table  Full table name.
	 * @param string $column Column.
	 */
	private static function has_column( wpdb $wpdb, string $table, string $column ): bool {
		return $column === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );
	}

	/**
	 * Whether the table has the unique index.
	 *
	 * @param wpdb   $wpdb  WordPress database object.
	 * @param string $table Full table name.
	 */
	private static function has_index( wpdb $wpdb, string $table ): bool {
		return null !== $wpdb->get_row( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, self::INDEX ) );
	}
}
