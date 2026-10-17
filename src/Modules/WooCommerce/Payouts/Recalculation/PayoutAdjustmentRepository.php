<?php
/**
 * Executed payout adjustments (ADR-063).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Recalculation;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_payout_adjustments` and `merchboard_payout_adjustment_rows`.
 *
 * An adjustment is written once, when an approved recalculation item is
 * executed, and is only ever changed by the settlement document that settles
 * it (`settled_document_id`). It counts in the creator's balance from its
 * execution (PayoutBalances) and appears on the next settlement document
 * (SettlementDocuments). `UNIQUE(recalculation_item_id)` executes an item at
 * most once; the primary key of the adjustment rows regulates a settled row
 * at most once per rule. Writes run on the connection that holds the
 * creator's payout lock, inside the caller's transaction.
 */
final class PayoutAdjustmentRepository {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * The sum of a creator's executed adjustments per currency (units).
	 *
	 * @param int $creator_id Creator.
	 * @return array<string, int>
	 * @throws DatabaseException When the read fails.
	 */
	public function totals( int $creator_id ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT currency, SUM(amount) AS total FROM %i WHERE creator_id = %d GROUP BY currency', $this->table( Tables::PAYOUT_ADJUSTMENTS ), $creator_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Balances are never cached.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the payout adjustments.' );
		}

		$totals = array();

		try {
			foreach ( $rows as $row ) {
				$totals[ (string) $row['currency'] ] = SalesAmount::parse( (string) $row['total'] );
			}
		} catch ( Throwable ) {
			throw new DatabaseException( 'A payout adjustment does not match the schema.' );
		}

		return $totals;
	}

	/**
	 * The adjustments of a creator and currency that no settlement document
	 * has settled yet, oldest first, locked for the caller's transaction.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $currency   Currency.
	 * @return list<array{id: int, amount_units: int, recalculation_id: int}>
	 * @throws DatabaseException When the read fails.
	 */
	public function unsettled_for_update( int $creator_id, string $currency ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, amount, recalculation_id FROM %i WHERE creator_id = %d AND currency = %s AND settled_document_id IS NULL ORDER BY id FOR UPDATE', $this->table( Tables::PAYOUT_ADJUSTMENTS ), $creator_id, $currency ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A locking read inside the caller's transaction.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the unsettled payout adjustments.' );
		}

		try {
			return array_map(
				static fn( array $row ): array => array(
					'id'               => (int) $row['id'],
					'amount_units'     => SalesAmount::parse( (string) $row['amount'] ),
					'recalculation_id' => (int) $row['recalculation_id'],
				),
				$rows
			);
		} catch ( Throwable ) {
			throw new DatabaseException( 'A payout adjustment does not match the schema.' );
		}
	}

	/**
	 * Marks adjustments as settled by a document, on the lock's connection.
	 *
	 * @phpstan-param list<int> $ids
	 *
	 * @param int[] $ids         Adjustments.
	 * @param int   $document_id Settlement document.
	 * @param int   $connection  The lock's connection.
	 * @throws DatabaseException When not exactly these adjustments were marked.
	 */
	public function mark_settled( array $ids, int $document_id, int $connection ): void {
		foreach ( $ids as $id ) {
			$this->write( $this->wpdb->prepare( 'UPDATE %i SET settled_document_id = %d WHERE id = %d AND settled_document_id IS NULL AND CONNECTION_ID() = %d', $this->table( Tables::PAYOUT_ADJUSTMENTS ), $document_id, $id, $connection ) );
		}
	}

	/**
	 * Inserts an executed adjustment, on the lock's connection.
	 *
	 * @param int               $creator_id       Creator.
	 * @param string            $currency         Currency.
	 * @param int               $amount_units     Signed amount.
	 * @param int               $recalculation_id Recalculation.
	 * @param int               $item_id          Recalculation item (UNIQUE).
	 * @param int|null          $created_by       Acting user.
	 * @param DateTimeImmutable $now              Time of execution.
	 * @param int               $connection       The lock's connection.
	 * @return int Adjustment id.
	 * @throws DatabaseException When the insert fails, also for an item that was already executed.
	 */
	public function insert( int $creator_id, string $currency, int $amount_units, int $recalculation_id, int $item_id, ?int $created_by, DateTimeImmutable $now, int $connection ): int {
		$wpdb = $this->wpdb;
		$this->write(
			$wpdb->prepare(
				'INSERT INTO %i (creator_id, currency, amount, rule, recalculation_id, recalculation_item_id, created_by, created_at) SELECT %d, %s, %s, %s, %d, %d, NULLIF(%d, 0), %s FROM DUAL WHERE CONNECTION_ID() = %d',
				$this->table( Tables::PAYOUT_ADJUSTMENTS ),
				$creator_id,
				$currency,
				SalesAmount::format( $amount_units ),
				RecalculationRule::RULE,
				$recalculation_id,
				$item_id,
				$created_by ?? 0,
				$now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				$connection
			)
		);
		$id = (int) $wpdb->insert_id;

		if ( $id < 1 ) {
			throw new DatabaseException( 'Could not store the payout adjustment.' );
		}

		return $id;
	}

	/**
	 * Records a settled row as regulated by an adjustment, on the lock's
	 * connection. The primary key refuses a row that is already regulated
	 * under the rule (duplicate control across recalculations).
	 *
	 * @param string $event_key          Settled ledger row.
	 * @param int    $adjustment_id      Adjustment.
	 * @param int    $original_units     Payout as settled.
	 * @param int    $recalculated_units Payout as recalculated.
	 * @param int    $connection         The lock's connection.
	 * @throws DatabaseException When the insert fails, also for a row already regulated.
	 */
	public function insert_row( string $event_key, int $adjustment_id, int $original_units, int $recalculated_units, int $connection ): void {
		$this->write(
			$this->wpdb->prepare(
				'INSERT INTO %i (rule, event_key, adjustment_id, original_amount, recalculated_amount) SELECT %s, %s, %d, %s, %s FROM DUAL WHERE CONNECTION_ID() = %d',
				$this->table( Tables::PAYOUT_ADJUSTMENT_ROWS ),
				RecalculationRule::RULE,
				$event_key,
				$adjustment_id,
				SalesAmount::format( $original_units ),
				SalesAmount::format( $recalculated_units ),
				$connection
			)
		);
	}

	/**
	 * Runs a guarded write that must change exactly one row.
	 *
	 * @param string|null $sql Result of $wpdb->prepare().
	 * @throws DatabaseException When it fails or changes no row.
	 */
	private function write( ?string $sql ): void {
		if ( null === $sql ) {
			throw new DatabaseException( 'Could not prepare the payout adjustment write.' );
		}

		$wpdb   = $this->wpdb;
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the result of $wpdb->prepare(); a guarded write.

		if ( false === $result || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not write the payout adjustment.' );
		}

		if ( 1 !== $result ) {
			throw new DatabaseException( 'The payout adjustment write changed no row; the connection or the row changed.' );
		}
	}

	/**
	 * A full table name.
	 *
	 * @param string $table Tables constant.
	 */
	private function table( string $table ): string {
		return Tables::name( $this->wpdb, $table );
	}
}
