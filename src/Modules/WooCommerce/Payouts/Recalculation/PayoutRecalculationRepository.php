<?php
/**
 * Storage of recalculations and their items (ADR-063).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Recalculation;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutStatus;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocument;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_payout_recalculations` and `merchboard_payout_recalculation_items`,
 * plus the read of the settled rows a recalculation is made from. A
 * recalculation and its items are never deleted; a status changes only from
 * an expected status (conditional UPDATE), so two administrators cannot both
 * make the same step.
 */
final class PayoutRecalculationRepository {

	private const RUN_COLUMNS = 'id, rule, calculation_version, status, fingerprint, reopens_id, created_by, created_at, decided_by, decided_at, decision_note, closed_by, closed_at, close_note';

	private const ITEM_COLUMNS = 'id, recalculation_id, creator_id, currency, category, reason, original_amount, recalculated_amount, difference_amount, row_count, detail, fingerprint, status, failure, executed_by, executed_at';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * The settled rows of documents issued with ADR-049's deduction for a
	 * creator who was not VAT registered, that no adjustment has regulated
	 * yet under the rule; ordered by creator, currency, document and row.
	 *
	 * @return list<array<mixed>>
	 * @throws DatabaseException When the read fails.
	 */
	public function candidate_rows(): array {
		$wpdb = $this->wpdb;
		$sql  = $wpdb->prepare(
			'SELECT d.creator_id, d.currency, d.id AS document_id, d.document_number, r.event_key, r.order_id, r.type, r.occurred_at, r.net_amount, r.tax_amount, r.share_bps, r.price_decimals, r.profit_amount, r.payout_amount
			FROM %i r INNER JOIN %i d ON d.id = r.document_id
			LEFT JOIN %i a ON a.rule = %s AND a.event_key = r.event_key
			WHERE d.document_type = %s AND d.calculation_version = %s AND r.vat_registered = 0 AND a.event_key IS NULL
			ORDER BY d.creator_id, d.currency, d.id, r.occurred_at, r.event_key',
			$this->table( Tables::SETTLEMENT_ROWS ),
			$this->table( Tables::SETTLEMENT_DOCUMENTS ),
			$this->table( Tables::PAYOUT_ADJUSTMENT_ROWS ),
			RecalculationRule::RULE,
			SettlementDocument::TYPE_SETTLEMENT,
			RecalculationRule::LEGACY_CALCULATION
		);

		return $this->rows( $sql );
	}

	/**
	 * Completed payouts without a settlement document (completed before
	 * ADR-060), per creator and currency: their settled rows are not known.
	 *
	 * @return list<array{creator_id: int, currency: string, payouts: int}>
	 * @throws DatabaseException When the read fails.
	 */
	public function undocumented_payouts(): array {
		$wpdb = $this->wpdb;
		$sql  = $wpdb->prepare(
			'SELECT p.creator_id, p.currency, COUNT(*) AS payouts FROM %i p LEFT JOIN %i d ON d.settlement_payout_id = p.id WHERE p.status = %s AND d.id IS NULL GROUP BY p.creator_id, p.currency ORDER BY p.creator_id, p.currency',
			$this->table( Tables::PAYOUTS ),
			$this->table( Tables::SETTLEMENT_DOCUMENTS ),
			PayoutStatus::Completed->value
		);

		return array_map(
			static fn( array $row ): array => array(
				'creator_id' => (int) $row['creator_id'],
				'currency'   => (string) $row['currency'],
				'payouts'    => (int) $row['payouts'],
			),
			$this->rows( $sql )
		);
	}

	/**
	 * The open recalculation, if any (the newest when, against the rule,
	 * several are open).
	 *
	 * @throws DatabaseException When the read fails.
	 */
	public function open(): ?Recalculation {
		$wpdb = $this->wpdb;

		return $this->one_run( $wpdb->prepare( 'SELECT ' . self::RUN_COLUMNS . ' FROM %i WHERE status IN (%s, %s, %s) ORDER BY id DESC LIMIT 1', $this->table( Tables::RECALCULATIONS ), Recalculation::PREVIEW, Recalculation::APPROVED, Recalculation::PARTIALLY_EXECUTED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * A recalculation.
	 *
	 * @param int  $id         Id.
	 * @param bool $for_update Lock the row for the caller's transaction.
	 * @throws DatabaseException When the read fails.
	 */
	public function find( int $id, bool $for_update = false ): ?Recalculation {
		$wpdb = $this->wpdb;

		return $this->one_run( $wpdb->prepare( 'SELECT ' . self::RUN_COLUMNS . ' FROM %i WHERE id = %d' . ( $for_update ? ' FOR UPDATE' : '' ), $this->table( Tables::RECALCULATIONS ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * Recalculations, newest first.
	 *
	 * @param int $limit  Rows.
	 * @param int $offset Offset.
	 * @return list<Recalculation>
	 * @throws DatabaseException When the read fails.
	 */
	public function page( int $limit, int $offset ): array {
		$wpdb = $this->wpdb;

		return array_map( array( self::class, 'run' ), $this->rows( $wpdb->prepare( 'SELECT ' . self::RUN_COLUMNS . ' FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $this->table( Tables::RECALCULATIONS ), $limit, $offset ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * Number of recalculations.
	 *
	 * @throws DatabaseException When the read fails.
	 */
	public function count(): int {
		$wpdb  = $this->wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table( Tables::RECALCULATIONS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Never cached.

		if ( '' !== $wpdb->last_error || ! is_numeric( $value ) ) {
			throw new DatabaseException( 'Could not count the recalculations.' );
		}

		return (int) $value;
	}

	/**
	 * The items of a recalculation, by group, creator and currency.
	 *
	 * @param int $recalculation_id Recalculation.
	 * @return list<RecalculationItem>
	 * @throws DatabaseException When the read fails.
	 */
	public function items( int $recalculation_id ): array {
		$wpdb = $this->wpdb;

		return array_map( array( self::class, 'item' ), $this->rows( $wpdb->prepare( 'SELECT ' . self::ITEM_COLUMNS . ' FROM %i WHERE recalculation_id = %d ORDER BY FIELD(category, %s, %s, %s), creator_id, currency', $this->table( Tables::RECALCULATION_ITEMS ), $recalculation_id, RecalculationItem::ACTIVE, RecalculationItem::FORMER, RecalculationItem::MANUAL ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * Inserts a recalculation as a preview.
	 *
	 * @param string            $fingerprint SHA-256 of its items.
	 * @param int|null          $reopens_id  The rejected recalculation it reopens.
	 * @param int|null          $created_by  Acting user.
	 * @param DateTimeImmutable $now         Time.
	 * @return int Id.
	 * @throws DatabaseException When the insert fails.
	 */
	public function insert( string $fingerprint, ?int $reopens_id, ?int $created_by, DateTimeImmutable $now ): int {
		$wpdb = $this->wpdb;
		$this->write( $wpdb->prepare( 'INSERT INTO %i (rule, calculation_version, status, fingerprint, reopens_id, created_by, created_at) VALUES (%s, %s, %s, %s, NULLIF(%d, 0), NULLIF(%d, 0), %s)', $this->table( Tables::RECALCULATIONS ), RecalculationRule::RULE, PayoutRecalculations::CALCULATION_VERSION, Recalculation::PREVIEW, $fingerprint, $reopens_id ?? 0, $created_by ?? 0, self::utc( $now ) ) );
		$id = (int) $wpdb->insert_id;

		if ( $id < 1 ) {
			throw new DatabaseException( 'Could not store the recalculation.' );
		}

		return $id;
	}

	/**
	 * Inserts an item of a recalculation.
	 *
	 * @param int          $recalculation_id Recalculation.
	 * @param array<mixed> $item             Item as computed (PayoutRecalculations::compute()).
	 * @param string       $status           Initial status.
	 * @throws DatabaseException When the insert fails.
	 */
	public function insert_item( int $recalculation_id, array $item, string $status ): void {
		try {
			$detail = json_encode( $item['detail'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- JSON_THROW_ON_ERROR.
		} catch ( JsonException ) {
			throw new DatabaseException( 'Could not encode the recalculation item.' );
		}

		$amount = static fn( mixed $units ): string => is_int( $units ) ? SalesAmount::format( $units ) : '';
		$this->write(
			$this->wpdb->prepare(
				"INSERT INTO %i (recalculation_id, creator_id, currency, category, reason, original_amount, recalculated_amount, difference_amount, row_count, detail, fingerprint, status) VALUES (%d, %d, %s, %s, %s, NULLIF(%s, ''), NULLIF(%s, ''), NULLIF(%s, ''), %d, %s, %s, %s)",
				$this->table( Tables::RECALCULATION_ITEMS ),
				$recalculation_id,
				(int) $item['creator_id'],
				(string) $item['currency'],
				(string) $item['category'],
				(string) $item['reason'],
				$amount( $item['original_units'] ),
				$amount( $item['recalculated_units'] ),
				$amount( $item['difference_units'] ),
				(int) $item['row_count'],
				(string) $detail,
				(string) $item['fingerprint'],
				$status
			)
		);
	}

	/**
	 * Moves a recalculation from one of the expected statuses to another.
	 *
	 * @phpstan-param list<string> $from
	 *
	 * @param int                   $id     Recalculation.
	 * @param string[]              $from   Expected statuses.
	 * @param string                $to     New status.
	 * @param array<string, string> $fields Further columns: decided_by/closed_by (ids), decided_at/closed_at (UTC), decision_note/close_note.
	 * @return bool Whether it moved (false: its status was not one of $from).
	 * @throws DatabaseException When the write fails.
	 */
	public function transition( int $id, array $from, string $to, array $fields = array() ): bool {
		$allowed = array( 'decided_by', 'decided_at', 'decision_note', 'closed_by', 'closed_at', 'close_note' );
		$sets    = array( 'status = %s' );
		$values  = array( $to );

		foreach ( $fields as $column => $value ) {
			if ( ! in_array( $column, $allowed, true ) ) {
				throw new DatabaseException( 'Unknown recalculation column.' );
			}

			$sets[]   = str_ends_with( $column, '_by' ) ? $column . ' = NULLIF(%d, 0)' : $column . ' = %s';
			$values[] = str_ends_with( $column, '_by' ) ? (int) $value : $value;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $from ), '%s' ) );
		$wpdb         = $this->wpdb;
		$sql          = $wpdb->prepare( 'UPDATE %i SET ' . implode( ', ', $sets ) . " WHERE id = %d AND status IN ({$placeholders})", $this->table( Tables::RECALCULATIONS ), ...array_merge( $values, array( $id ), $from ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Columns from the allowed list; placeholders only.

		return 1 === $this->write( $sql, false );
	}

	/**
	 * Moves an item from one of the expected statuses to another.
	 *
	 * @phpstan-param list<string> $from
	 *
	 * @param int                    $id          Item.
	 * @param string[]               $from        Expected statuses.
	 * @param string                 $to          New status.
	 * @param string                 $failure     Failure reason ('' when executed).
	 * @param int|null               $executed_by Acting user (executed).
	 * @param DateTimeImmutable|null $executed_at Time (executed).
	 * @return bool Whether it moved.
	 * @throws DatabaseException When the write fails.
	 */
	public function transition_item( int $id, array $from, string $to, string $failure, ?int $executed_by, ?DateTimeImmutable $executed_at ): bool {
		$placeholders = implode( ', ', array_fill( 0, count( $from ), '%s' ) );
		$wpdb         = $this->wpdb;
		$sql          = $wpdb->prepare( "UPDATE %i SET status = %s, failure = %s, executed_by = NULLIF(%d, 0), executed_at = NULLIF(%s, '') WHERE id = %d AND status IN ({$placeholders})", $this->table( Tables::RECALCULATION_ITEMS ), $to, $failure, $executed_by ?? 0, null === $executed_at ? '' : self::utc( $executed_at ), $id, ...$from ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders only.

		return 1 === $this->write( $sql, false );
	}

	/**
	 * A UTC DATETIME string.
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	public static function utc( DateTimeImmutable $time ): string {
		return $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Runs a write; with $exactly_one it must change exactly one row.
	 *
	 * @param string|null $sql         Result of $wpdb->prepare().
	 * @param bool        $exactly_one Whether one row must change.
	 * @return int Rows changed.
	 * @throws DatabaseException When it fails.
	 */
	private function write( ?string $sql, bool $exactly_one = true ): int {
		if ( null === $sql ) {
			throw new DatabaseException( 'Could not prepare the recalculation write.' );
		}

		$wpdb   = $this->wpdb;
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the result of $wpdb->prepare().

		if ( false === $result || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not write the recalculation.' );
		}

		if ( $exactly_one && 1 !== $result ) {
			throw new DatabaseException( 'The recalculation write changed no row.' );
		}

		return (int) $result;
	}

	/**
	 * Rows of a prepared SELECT.
	 *
	 * @param string|null $sql Result of $wpdb->prepare().
	 * @return list<array<mixed>>
	 * @throws DatabaseException When the read fails.
	 */
	private function rows( ?string $sql ): array {
		$wpdb = $this->wpdb;
		$rows = null === $sql ? null : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Callers pass the result of $wpdb->prepare(); never cached.

		if ( null === $sql || '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the recalculations.' );
		}

		return $rows;
	}

	/**
	 * At most one recalculation of a prepared SELECT.
	 *
	 * @param string|null $sql Result of $wpdb->prepare().
	 * @throws DatabaseException When the read fails or the row is invalid.
	 */
	private function one_run( ?string $sql ): ?Recalculation {
		$rows = $this->rows( $sql );

		return array() === $rows ? null : self::run( $rows[0] );
	}

	/**
	 * A recalculation from a row.
	 *
	 * @param array<mixed> $row Row.
	 * @throws DatabaseException When the row is invalid.
	 */
	private static function run( array $row ): Recalculation {
		try {
			return new Recalculation(
				(int) $row['id'],
				(string) $row['rule'],
				(string) $row['calculation_version'],
				(string) $row['status'],
				(string) $row['fingerprint'],
				self::nullable_id( $row['reopens_id'] ),
				self::nullable_id( $row['created_by'] ),
				self::time( $row['created_at'] ),
				self::nullable_id( $row['decided_by'] ),
				null === $row['decided_at'] ? null : self::time( $row['decided_at'] ),
				(string) $row['decision_note'],
				self::nullable_id( $row['closed_by'] ),
				null === $row['closed_at'] ? null : self::time( $row['closed_at'] ),
				(string) $row['close_note']
			);
		} catch ( Throwable ) {
			throw new DatabaseException( 'Invalid recalculation row.' );
		}
	}

	/**
	 * An item from a row.
	 *
	 * @param array<mixed> $row Row.
	 * @throws DatabaseException When the row is invalid.
	 */
	private static function item( array $row ): RecalculationItem {
		try {
			$detail = json_decode( (string) $row['detail'], true, 16, JSON_THROW_ON_ERROR );
			$amount = static fn( mixed $value ): ?int => null === $value ? null : SalesAmount::parse( (string) $value );

			return new RecalculationItem(
				(int) $row['id'],
				(int) $row['recalculation_id'],
				(int) $row['creator_id'],
				(string) $row['currency'],
				(string) $row['category'],
				(string) $row['reason'],
				$amount( $row['original_amount'] ),
				$amount( $row['recalculated_amount'] ),
				$amount( $row['difference_amount'] ),
				(int) $row['row_count'],
				is_array( $detail ) ? $detail : array(),
				(string) $row['fingerprint'],
				(string) $row['status'],
				(string) $row['failure'],
				self::nullable_id( $row['executed_by'] ),
				null === $row['executed_at'] ? null : self::time( $row['executed_at'] )
			);
		} catch ( Throwable ) {
			throw new DatabaseException( 'Invalid recalculation item row.' );
		}
	}

	/**
	 * A nullable id column.
	 *
	 * @param mixed $value Column value.
	 */
	private static function nullable_id( mixed $value ): ?int {
		return null === $value ? null : (int) $value;
	}

	/**
	 * A stored UTC time.
	 *
	 * @param mixed $value Column value.
	 * @throws DatabaseException When it is not a valid time.
	 */
	private static function time( mixed $value ): DateTimeImmutable {
		$time = is_string( $value ) ? DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) ) : false;

		if ( false === $time ) {
			throw new DatabaseException( 'Invalid time in a recalculation.' );
		}

		return $time;
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
