<?php
/**
 * Storage of settlement documents, their rows and number counters.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Settlements;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and inserts `merchboard_settlement_documents`,
 * `merchboard_settlement_document_rows` and `merchboard_settlement_counters`
 * (ADR-060). Documents and rows are only inserted, never updated or
 * deleted. Every write runs inside the caller's transaction under the
 * creator's payout lock and is guarded by `CONNECTION_ID() = <lock
 * connection>` (as PayoutRepository), so a silent reconnect writes nothing.
 */
final class SettlementRepository {

	/**
	 * Columns of a document row.
	 */
	private const COLUMNS = 'id, document_number, document_type, creator_id, payout_id, corrects_document_id, currency, period_from, period_to, profit_amount, commission_amount, opening_balance, payout_amount, closing_balance, vat_registered, calculation_version, template_version, snapshot, issued_by, issued_at';

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * Takes the next number of a series: the counter row is locked and
	 * increased in the caller's transaction, so concurrent documents never
	 * get the same number, and a rollback gives the number back.
	 *
	 * @param string $series     Series, e.g. AFR.
	 * @param int    $connection The lock's connection.
	 * @throws DatabaseException When the counter cannot be read or written.
	 */
	public function next_number( string $series, int $connection ): int {
		$wpdb  = $this->wpdb;
		$table = $this->table( Tables::SETTLEMENT_COUNTERS );
		$this->write( $wpdb->prepare( 'INSERT IGNORE INTO %i (series, next_value) SELECT %s, 1 FROM DUAL WHERE CONNECTION_ID() = %d', $table, $series, $connection ), false );
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT next_value FROM %i WHERE series = %s FOR UPDATE', $table, $series ) );

		if ( '' !== $wpdb->last_error || ! is_numeric( $value ) || (int) $value < 1 ) {
			throw new DatabaseException( 'Could not read the document counter.' );
		}

		$this->write( $wpdb->prepare( 'UPDATE %i SET next_value = next_value + 1 WHERE series = %s AND next_value = %d AND CONNECTION_ID() = %d', $table, $series, (int) $value, $connection ), true );

		return (int) $value;
	}

	/**
	 * Inserts a document.
	 *
	 * @param array<string, mixed> $values     Column values (amounts in units, times as DateTimeImmutable).
	 * @param int                  $connection The lock's connection.
	 * @return int Document id.
	 * @throws DatabaseException When the insert fails.
	 */
	public function insert_document( array $values, int $connection ): int {
		$wpdb = $this->wpdb;

		try {
			$snapshot = json_encode( $values['snapshot'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- JSON_THROW_ON_ERROR.
		} catch ( JsonException ) {
			throw new DatabaseException( 'Could not encode the document snapshot.' );
		}

		$this->write(
			$wpdb->prepare(
				"INSERT INTO %i (document_number, document_series, document_type, creator_id, payout_id, settlement_payout_id, corrects_document_id, currency, period_from, period_to, profit_amount, commission_amount, opening_balance, payout_amount, closing_balance, vat_registered, calculation_version, template_version, snapshot, issued_by, issued_at)
				SELECT %s, %s, %s, %d, NULLIF(%d, 0), NULLIF(%d, 0), NULLIF(%d, 0), %s, NULLIF(%s, ''), %s, %s, %s, %s, %s, %s, NULLIF(%d, -1), %s, %d, %s, NULLIF(%d, 0), %s FROM DUAL WHERE CONNECTION_ID() = %d",
				$this->table( Tables::SETTLEMENT_DOCUMENTS ),
				(string) $values['number'],
				(string) $values['series'],
				(string) $values['type'],
				(int) $values['creator_id'],
				(int) $values['payout_id'],
				(int) $values['settlement_payout_id'],
				(int) $values['corrects_document_id'],
				(string) $values['currency'],
				null === $values['period_from'] ? '' : self::utc( $values['period_from'] ),
				self::utc( $values['period_to'] ),
				SalesAmount::format( (int) $values['profit_units'] ),
				SalesAmount::format( (int) $values['commission_units'] ),
				SalesAmount::format( (int) $values['opening_units'] ),
				SalesAmount::format( (int) $values['payout_units'] ),
				SalesAmount::format( (int) $values['closing_units'] ),
				null === $values['vat_registered'] ? -1 : (int) $values['vat_registered'],
				(string) $values['calculation_version'],
				(int) $values['template_version'],
				(string) $snapshot,
				(int) $values['issued_by'],
				self::utc( $values['issued_at'] ),
				$connection
			),
			true
		);
		$id = (int) $wpdb->insert_id;

		if ( $id < 1 ) {
			throw new DatabaseException( 'Could not store the settlement document.' );
		}

		return $id;
	}

	/**
	 * Inserts one settled row; `UNIQUE(event_key)` refuses a row that another
	 * document already settled.
	 *
	 * @param int                  $document_id Document.
	 * @param array<string, mixed> $row         A row of LedgerCreatorPayouts::unsettled_rows().
	 * @param int                  $connection  The lock's connection.
	 * @throws DatabaseException When the insert fails.
	 */
	public function insert_row( int $document_id, array $row, int $connection ): void {
		$wpdb = $this->wpdb;
		$this->write(
			$wpdb->prepare(
				'INSERT INTO %i (event_key, document_id, order_id, order_item_id, product_id, variation_id, item_name, type, quantity, net_amount, tax_amount, share_bps, vat_registered, price_decimals, profit_amount, payout_amount, occurred_at)
				SELECT %s, %d, %d, %d, %d, %d, %s, %s, %d, %s, %s, %d, %d, %d, %s, %s, %s FROM DUAL WHERE CONNECTION_ID() = %d',
				$this->table( Tables::SETTLEMENT_ROWS ),
				(string) $row['event_key'],
				$document_id,
				(int) $row['order_id'],
				(int) $row['order_item_id'],
				(int) $row['product_id'],
				(int) $row['variation_id'],
				(string) $row['item_name'],
				(string) $row['type'],
				(int) $row['quantity'],
				SalesAmount::format( (int) $row['net_units'] ),
				SalesAmount::format( (int) $row['tax_units'] ),
				(int) $row['share_bps'],
				(int) (bool) $row['vat_registered'],
				(int) $row['price_decimals'],
				SalesAmount::format( (int) $row['profit_units'] ),
				SalesAmount::format( (int) $row['payout_units'] ),
				(string) $row['occurred_at'],
				$connection
			),
			true
		);
	}

	/**
	 * A document by id.
	 *
	 * @param int  $id         Document id.
	 * @param bool $for_update Lock the row (inside a transaction).
	 * @throws DatabaseException When the read fails or the row is invalid.
	 */
	public function find( int $id, bool $for_update = false ): ?SettlementDocument {
		return $this->one( $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d' . ( $for_update ? ' FOR UPDATE' : '' ), $this->table( Tables::SETTLEMENT_DOCUMENTS ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns and suffix.
	}

	/**
	 * The settlement document of a payout, if any.
	 *
	 * @param int $payout_id Payout.
	 * @throws DatabaseException When the read fails or the row is invalid.
	 */
	public function find_by_payout( int $payout_id ): ?SettlementDocument {
		return $this->one( $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE settlement_payout_id = %d', $this->table( Tables::SETTLEMENT_DOCUMENTS ), $payout_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * The document that corrects a document, if any.
	 *
	 * @param int $document_id Document.
	 * @throws DatabaseException When the read fails or the row is invalid.
	 */
	public function correction_of( int $document_id ): ?SettlementDocument {
		return $this->one( $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE corrects_document_id = %d', $this->table( Tables::SETTLEMENT_DOCUMENTS ), $document_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * The creator's latest settlement document in a currency (the chain of
	 * carried balances), if any.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $currency   Currency.
	 * @throws DatabaseException When the read fails or the row is invalid.
	 */
	public function latest_settlement( int $creator_id, string $currency ): ?SettlementDocument {
		return $this->one( $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d AND currency = %s AND document_type = %s ORDER BY id DESC LIMIT 1', $this->table( Tables::SETTLEMENT_DOCUMENTS ), $creator_id, $currency, SettlementDocument::TYPE_SETTLEMENT ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.
	}

	/**
	 * The settled rows of a document, oldest first.
	 *
	 * @param int $document_id Document.
	 * @return list<array<string, mixed>>
	 * @throws DatabaseException When the read fails.
	 */
	public function rows( int $document_id ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT event_key, order_id, order_item_id, product_id, variation_id, item_name, type, quantity, net_amount, tax_amount, share_bps, vat_registered, price_decimals, profit_amount, payout_amount, occurred_at FROM %i WHERE document_id = %d ORDER BY occurred_at, event_key', $this->table( Tables::SETTLEMENT_ROWS ), $document_id ), ARRAY_A );

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the settlement rows.' );
		}

		return $rows;
	}

	/**
	 * Document numbers of the settlement documents of payouts.
	 *
	 * @phpstan-param list<int> $payout_ids
	 *
	 * @param int[] $payout_ids Payouts.
	 * @return array<int, string> Number by payout id.
	 * @throws DatabaseException When the read fails.
	 */
	public function numbers_for_payouts( array $payout_ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $payout_ids ), static fn( int $id ): bool => $id > 0 ) );

		if ( array() === $ids ) {
			return array();
		}

		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT settlement_payout_id, document_number FROM %i WHERE settlement_payout_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')', $this->table( Tables::SETTLEMENT_DOCUMENTS ), ...$ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One %d per id.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the settlement documents.' );
		}

		$numbers = array();

		foreach ( $rows as $row ) {
			$numbers[ (int) $row['settlement_payout_id'] ] = (string) $row['document_number'];
		}

		return $numbers;
	}

	/**
	 * Runs a guarded write.
	 *
	 * @param string|null $sql         Result of $wpdb->prepare().
	 * @param bool        $exactly_one Whether it must change exactly one row.
	 * @throws DatabaseException When it fails (or changes no row).
	 */
	private function write( ?string $sql, bool $exactly_one ): void {
		if ( null === $sql ) {
			throw new DatabaseException( 'Could not prepare the settlement write.' );
		}

		$result = $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the result of $wpdb->prepare().

		if ( false === $result || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not write the settlement document.' );
		}

		if ( $exactly_one && 1 !== $result ) {
			throw new DatabaseException( 'The settlement write changed no row; the connection or the row changed.' );
		}
	}

	/**
	 * Runs a prepared SELECT of at most one document.
	 *
	 * @param string|null $sql Result of $wpdb->prepare().
	 * @throws DatabaseException When the read fails or the row is invalid.
	 */
	private function one( ?string $sql ): ?SettlementDocument {
		$row = null === $sql ? null : $this->wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Callers pass the result of $wpdb->prepare().

		if ( null === $sql || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the settlement document.' );
		}

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * A document from a row.
	 *
	 * @param array<mixed> $row Row.
	 * @throws DatabaseException When the row is invalid.
	 */
	private static function hydrate( array $row ): SettlementDocument {
		try {
			$snapshot = json_decode( (string) $row['snapshot'], true, 16, JSON_THROW_ON_ERROR );

			return new SettlementDocument(
				(int) $row['id'],
				(string) $row['document_number'],
				(string) $row['document_type'],
				(int) $row['creator_id'],
				null === $row['payout_id'] ? null : (int) $row['payout_id'],
				null === $row['corrects_document_id'] ? null : (int) $row['corrects_document_id'],
				(string) $row['currency'],
				null === $row['period_from'] ? null : self::time( $row['period_from'] ),
				self::time( $row['period_to'] ),
				SalesAmount::parse( (string) $row['profit_amount'] ),
				SalesAmount::parse( (string) $row['commission_amount'] ),
				SalesAmount::parse( (string) $row['opening_balance'] ),
				SalesAmount::parse( (string) $row['payout_amount'] ),
				SalesAmount::parse( (string) $row['closing_balance'] ),
				null === $row['vat_registered'] ? null : '1' === (string) $row['vat_registered'],
				(string) $row['calculation_version'],
				(int) $row['template_version'],
				is_array( $snapshot ) ? $snapshot : array(),
				null === $row['issued_by'] ? null : (int) $row['issued_by'],
				self::time( $row['issued_at'] )
			);
		} catch ( DatabaseException $e ) {
			throw $e;
		} catch ( Throwable ) {
			throw new DatabaseException( 'Invalid settlement document row.' );
		}
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
			throw new DatabaseException( 'Invalid settlement time.' );
		}

		return $time;
	}

	/**
	 * A time as stored (UTC).
	 *
	 * @param mixed $time Time.
	 */
	private static function utc( mixed $time ): string {
		return $time instanceof DateTimeImmutable ? $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : '';
	}

	/**
	 * Full table name.
	 *
	 * @param string $table Tables constant.
	 */
	private function table( string $table ): string {
		return Tables::name( $this->wpdb, $table );
	}
}
