<?php
/**
 * The digitization fees of embroidery files.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Fees;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_designer_fees` and `merchboard_designer_fee_versions` (ADR-086).
 * A fee is `waiting` until the first sale of a version that uses its file, and
 * `charged` after. A cancelled fee waits again. Changes of status run on the
 * connection that holds the creator's payout lock.
 */
class DesignerFeeRepository {

	public const WAITING = 'waiting';

	public const CHARGED = 'charged';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Ties an approved version to its file's fee. A creator's file has one
	 * fee, whichever product it is on: a known file keeps its fee and amount.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $hash       SHA-256 of the file.
	 * @param string $amount     The fee (decimal).
	 * @param string $currency   Currency.
	 * @param string $side       Side.
	 * @param string $title      Product title.
	 * @param int    $version_id Version.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function register( int $creator_id, string $hash, string $amount, string $currency, string $side, string $title, int $version_id ): void {
		$wpdb = $this->wpdb;

		$this->write( $wpdb->prepare( 'INSERT IGNORE INTO %i (creator_id, file_hash, amount, currency, side, title, status, created_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', $this->table( Tables::DESIGNER_FEES ), $creator_id, $hash, $amount, $currency, $side, mb_substr( $title, 0, 191 ), self::WAITING, gmdate( 'Y-m-d H:i:s' ) ) );

		$fee = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE creator_id = %d AND file_hash = %s', $this->table( Tables::DESIGNER_FEES ), $creator_id, $hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table; never cached.

		if ( $fee < 1 ) {
			throw new DatabaseException( 'Could not store the digitization fee.' );
		}

		$this->write( $wpdb->prepare( 'INSERT IGNORE INTO %i (fee_id, version_id) VALUES (%d, %d)', $this->table( Tables::DESIGNER_FEE_VERSIONS ), $fee, $version_id ) );
	}

	/**
	 * The ids of the waiting fees of a version.
	 *
	 * @param int $version_id Version.
	 * @return list<int>
	 * @throws DatabaseException When the read fails.
	 */
	public function waiting_of_version( int $version_id ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT f.id FROM %i f JOIN %i l ON l.fee_id = f.id WHERE l.version_id = %d AND f.status = %s ORDER BY f.id', $this->table( Tables::DESIGNER_FEES ), $this->table( Tables::DESIGNER_FEE_VERSIONS ), $version_id, self::WAITING ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own tables; never cached.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the digitization fees.' );
		}

		return array_map( 'intval', $rows );
	}

	/**
	 * A fee, or null.
	 *
	 * @param int $id Fee.
	 * @return array<string, mixed>|null
	 * @throws DatabaseException When the read fails.
	 */
	public function find( int $id ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table( Tables::DESIGNER_FEES ), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table; never cached.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the digitization fee.' );
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Moves a waiting fee to charged on the lock's connection.
	 *
	 * @param int    $id         Fee.
	 * @param int    $adjustment The adjustment that charged it.
	 * @param int    $order_id   The order.
	 * @param string $at         Time (UTC).
	 * @param int    $connection The lock's connection.
	 * @return bool False when it was not waiting.
	 * @throws DatabaseException When the write fails.
	 */
	public function mark_charged( int $id, int $adjustment, int $order_id, string $at, int $connection ): bool {
		$wpdb = $this->wpdb;

		return 1 === $this->write( $wpdb->prepare( 'UPDATE %i SET status = %s, adjustment_id = %d, order_id = %d, charged_at = %s WHERE id = %d AND status = %s AND CONNECTION_ID() = %d', $this->table( Tables::DESIGNER_FEES ), self::CHARGED, $adjustment, $order_id, $at, $id, self::WAITING, $connection ) );
	}

	/**
	 * Moves a charged fee back to waiting on the lock's connection.
	 *
	 * @param int $id         Fee.
	 * @param int $connection The lock's connection.
	 * @return bool False when it was not charged.
	 * @throws DatabaseException When the write fails.
	 */
	public function mark_waiting( int $id, int $connection ): bool {
		$wpdb = $this->wpdb;

		return 1 === $this->write( $wpdb->prepare( 'UPDATE %i SET status = %s, adjustment_id = NULL, order_id = NULL, charged_at = NULL WHERE id = %d AND status = %s AND CONNECTION_ID() = %d', $this->table( Tables::DESIGNER_FEES ), self::WAITING, $id, self::CHARGED, $connection ) );
	}

	/**
	 * The newest fees with their creators, for the administrator.
	 *
	 * @param int $limit Most rows.
	 * @return list<array<string, mixed>>
	 * @throws DatabaseException When the read fails.
	 */
	public function recent( int $limit ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT f.*, c.display_name FROM %i f LEFT JOIN %i c ON c.id = f.creator_id ORDER BY f.id DESC LIMIT %d', $this->table( Tables::DESIGNER_FEES ), Tables::name( $wpdb, Tables::CREATORS ), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The designer's own table; never cached.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the digitization fees.' );
		}

		return $rows;
	}

	/**
	 * Runs a write.
	 *
	 * @param string|null $sql Prepared statement.
	 * @return int Rows changed.
	 * @throws DatabaseException When the write fails.
	 */
	private function write( ?string $sql ): int {
		$done = null === $sql ? false : $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the callers.

		if ( false === $done || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not store the digitization fee.' );
		}

		return (int) $done;
	}

	/**
	 * A table's name.
	 *
	 * @param string $table Table.
	 */
	private function table( string $table ): string {
		return Tables::name( $this->wpdb, $table );
	}
}
