<?php
/**
 * Storage of payouts, their encrypted details and the settlement start.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes `merchboard_payouts`, `merchboard_payout_secrets` and
 * `merchboard_creators.payout_start_at` (ADR-054–056).
 *
 * Concurrency per creator, as the sales ledger per order (ADR-038 pkt. 18):
 * with_creator_lock() takes a named lock with GET_LOCK() and notes the
 * connection; the caller then runs its reads and writes in the project's
 * Transaction. Every write is guarded by `CONNECTION_ID() = <lock
 * connection>`, so a silent reconnect of wpdb (which loses the lock and the
 * transaction) writes nothing; assert_same_connection() is checked before
 * COMMIT. Writes require the lock of the payout's creator.
 *
 * Payout rows are never deleted. A status changes only from `pending`, with
 * a conditional UPDATE; amount, method and creator never change.
 */
final class PayoutRepository {

	/**
	 * Seconds to wait for the creator's lock.
	 */
	public const LOCK_TIMEOUT = 5;

	/**
	 * Prefix of the lock names.
	 */
	private const LOCK_PREFIX = 'merchboard_payout:';

	/**
	 * Columns of a payout row.
	 */
	private const COLUMNS = 'id, creator_id, currency, amount, price_decimals, method, status, destination_hint, idempotency_key, requested_by, requested_at, decided_by, decided_at, decision_reason, reference, gift_card_provider, gift_card_external_id';

	/**
	 * Creator whose lock this instance holds, or null.
	 *
	 * @var int|null
	 */
	private ?int $locked_creator = null;

	/**
	 * Connection (CONNECTION_ID()) that took the lock.
	 *
	 * @var int
	 */
	private int $lock_connection = 0;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb         WordPress database object.
	 * @param int  $lock_timeout Seconds to wait for a creator lock (≥ 0).
	 */
	public function __construct( private wpdb $wpdb, private int $lock_timeout = self::LOCK_TIMEOUT ) {
	}

	/**
	 * Runs $work while holding the creator's payout lock.
	 *
	 * @template T
	 * @param int           $creator_id Creator.
	 * @param callable(): T $work       Work.
	 * @return T
	 * @throws DatabaseException When the lock cannot be taken or is nested.
	 * @throws PayoutException When another request holds it (`busy`).
	 */
	public function with_creator_lock( int $creator_id, callable $work ): mixed {
		if ( $creator_id < 1 ) {
			throw new PayoutException( PayoutException::NOT_FOUND );
		}

		if ( null !== $this->locked_creator ) {
			throw new DatabaseException( 'Nested payout locks are not supported.' );
		}

		$wpdb = $this->wpdb;
		$name = $this->lock_name( $creator_id );
		// One statement, so the lock and the connection id come from the same session.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d) AS acquired, CONNECTION_ID() AS connection_id', $name, $this->lock_timeout ), ARRAY_A );

		if ( ! is_array( $row ) || ! is_numeric( $row['acquired'] ?? null ) || ! is_numeric( $row['connection_id'] ?? null ) ) {
			throw new DatabaseException( 'Could not take the payout lock.', array( 'creator_id' => $creator_id ) );
		}

		if ( 1 !== (int) $row['acquired'] ) {
			throw new PayoutException( PayoutException::BUSY );
		}

		$this->locked_creator  = $creator_id;
		$this->lock_connection = (int) $row['connection_id'];

		try {
			return $work();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			$this->locked_creator  = null;
			$this->lock_connection = 0;
		}
	}

	/**
	 * Requires the connection that took the creator's lock to still be the
	 * current one and to still hold it. Call before COMMIT.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the connection changed or the lock is lost.
	 */
	public function assert_same_connection( int $creator_id ): void {
		$this->assert_locked( $creator_id );
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT CONNECTION_ID() AS connection_id, IS_USED_LOCK(%s) AS holder', $this->lock_name( $creator_id ) ), ARRAY_A );

		if ( ! is_array( $row ) || (string) ( $row['connection_id'] ?? '' ) !== (string) $this->lock_connection || (string) ( $row['holder'] ?? '' ) !== (string) $this->lock_connection ) {
			throw new DatabaseException( 'The database connection changed while the payout lock was held.', array( 'creator_id' => $creator_id ) );
		}
	}

	/**
	 * The connection that holds the creator's lock, for writes of other
	 * payout data in the same transaction (settlement documents, ADR-060).
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the creator's lock is not held.
	 */
	public function lock_connection( int $creator_id ): int {
		$this->assert_locked( $creator_id );

		return $this->lock_connection;
	}

	/**
	 * Whether the creator has a settlement document (D3: the settlement start
	 * is then locked, ADR-060).
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the read fails.
	 */
	public function has_settlement_documents( int $creator_id ): bool {
		return $this->count( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE creator_id = %d', $this->table( Tables::SETTLEMENT_DOCUMENTS ), $creator_id ) ) > 0;
	}

	/**
	 * A payout by id.
	 *
	 * @param int  $id         Payout id.
	 * @param bool $for_update Lock the row (inside a transaction).
	 * @throws DatabaseException When the read fails or the row is invalid.
	 */
	public function find( int $id, bool $for_update = false ): ?Payout {
		if ( $id < 1 ) {
			return null;
		}

		$sql = $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d' . ( $for_update ? ' FOR UPDATE' : '' ), $this->table( Tables::PAYOUTS ), $id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns and suffix.

		return $this->one( $sql );
	}

	/**
	 * A payout of the creator by the creator's request key.
	 *
	 * @param int    $creator_id      Creator.
	 * @param string $idempotency_key Request key.
	 * @throws DatabaseException When the read fails or the row is invalid.
	 */
	public function find_by_key( int $creator_id, string $idempotency_key ): ?Payout {
		$sql = $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d AND idempotency_key = %s', $this->table( Tables::PAYOUTS ), $creator_id, $idempotency_key ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.

		return $this->one( $sql );
	}

	/**
	 * Number of the creator's payouts, whatever their status.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the read fails.
	 */
	public function count_for_creator( int $creator_id ): int {
		return $this->count( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE creator_id = %d', $this->table( Tables::PAYOUTS ), $creator_id ) );
	}

	/**
	 * A page of the creator's payouts, newest first.
	 *
	 * @param int $creator_id Creator.
	 * @param int $limit      Rows (1 … 100).
	 * @param int $offset     Rows to skip.
	 * @return list<Payout>
	 * @throws DatabaseException When the read fails or a row is invalid.
	 */
	public function page_for_creator( int $creator_id, int $limit, int $offset ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE creator_id = %d ORDER BY requested_at DESC, id DESC LIMIT %d OFFSET %d', $this->table( Tables::PAYOUTS ), $creator_id, max( 1, min( 100, $limit ) ), max( 0, $offset ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the creator\'s payouts.' );
		}

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Number of all creators' payouts in the admin queue (pending) or history
	 * (every other status).
	 *
	 * @param bool $pending Queue (true) or history (false).
	 * @throws DatabaseException When the read fails.
	 */
	public function count_for_admin( bool $pending ): int {
		return $this->count( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . ( $pending ? 'status = %s' : 'status <> %s' ), $this->table( Tables::PAYOUTS ), PayoutStatus::Pending->value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Constant condition with one %s.
	}

	/**
	 * A page of the admin queue (pending, oldest first) or history (newest
	 * decision first).
	 *
	 * @param bool $pending Queue (true) or history (false).
	 * @param int  $limit   Rows (1 … 100).
	 * @param int  $offset  Rows to skip.
	 * @return list<Payout>
	 * @throws DatabaseException When the read fails or a row is invalid.
	 */
	public function page_for_admin( bool $pending, int $limit, int $offset ): array {
		$wpdb  = $this->wpdb;
		$where = $pending ? 'status = %s ORDER BY requested_at ASC, id ASC' : 'status <> %s ORDER BY decided_at DESC, id DESC';
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM %i WHERE {$where} LIMIT %d OFFSET %d", $this->table( Tables::PAYOUTS ), PayoutStatus::Pending->value, max( 1, min( 100, $limit ) ), max( 0, $offset ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Constant columns and condition with one %s.

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the payouts.' );
		}

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Number of the creator's pending payouts.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the read fails.
	 */
	public function count_pending( int $creator_id ): int {
		return $this->count( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE creator_id = %d AND status = %s', $this->table( Tables::PAYOUTS ), $creator_id, PayoutStatus::Pending->value ) );
	}

	/**
	 * Number of the creator's payouts requested at or after a time, whatever
	 * their status.
	 *
	 * @param int               $creator_id Creator.
	 * @param DateTimeImmutable $since      Time.
	 * @throws DatabaseException When the read fails.
	 */
	public function count_requested_since( int $creator_id, DateTimeImmutable $since ): int {
		return $this->count( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE creator_id = %d AND requested_at >= %s', $this->table( Tables::PAYOUTS ), $creator_id, self::utc( $since ) ) );
	}

	/**
	 * Completed and reserved (pending) amounts of the creator per currency.
	 *
	 * @param int $creator_id Creator.
	 * @return array<string, array{completed: int, reserved: int}>
	 * @throws DatabaseException When the read fails or a row is invalid.
	 */
	public function totals( int $creator_id ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT currency, SUM( CASE WHEN status = %s THEN amount ELSE 0 END ) AS completed, SUM( CASE WHEN status = %s THEN amount ELSE 0 END ) AS reserved FROM %i WHERE creator_id = %d AND status IN ( %s, %s ) GROUP BY currency ORDER BY currency',
				PayoutStatus::Completed->value,
				PayoutStatus::Pending->value,
				$this->table( Tables::PAYOUTS ),
				$creator_id,
				PayoutStatus::Completed->value,
				PayoutStatus::Pending->value
			),
			ARRAY_A
		);

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new DatabaseException( 'Could not read the creator\'s payouts.' );
		}

		$result = array();

		foreach ( $rows as $row ) {
			try {
				$result[ (string) $row['currency'] ] = array(
					'completed' => SalesAmount::parse( (string) $row['completed'] ),
					'reserved'  => SalesAmount::parse( (string) $row['reserved'] ),
				);
			} catch ( Throwable ) {
				throw new DatabaseException( 'A payout row does not match the schema.' );
			}
		}

		return $result;
	}

	/**
	 * The payout a gift card is registered on, if any (ADR-057).
	 *
	 * @param string $provider    Provider id.
	 * @param string $external_id The card's id in the provider.
	 * @throws DatabaseException When the read fails or the row is invalid.
	 */
	public function find_by_gift_card( string $provider, string $external_id ): ?Payout {
		$sql = $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE gift_card_provider = %s AND gift_card_external_id = %s', $this->table( Tables::PAYOUTS ), $provider, $external_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant columns.

		return $this->one( $sql );
	}

	/**
	 * Inserts a pending payout with its encrypted details (none for a gift
	 * card). Requires the creator's lock, inside a transaction.
	 *
	 * @param int               $creator_id       Creator.
	 * @param string            $currency         Currency.
	 * @param int               $amount_units     Amount in units.
	 * @param int               $price_decimals   Store price decimals.
	 * @param PayoutMethod      $method           Method.
	 * @param string            $destination_hint Masked destination.
	 * @param string            $idempotency_key  Request key.
	 * @param int|null          $requested_by     WordPress user.
	 * @param DateTimeImmutable $now              Request time.
	 * @param string|null       $ciphertext       Encrypted details, null for none.
	 * @param string            $key_id           Key id of the encryption.
	 * @return int Payout id.
	 * @throws DatabaseException When the creator's lock is not held or a write fails.
	 */
	public function insert_pending( int $creator_id, string $currency, int $amount_units, int $price_decimals, PayoutMethod $method, string $destination_hint, string $idempotency_key, ?int $requested_by, DateTimeImmutable $now, ?string $ciphertext, string $key_id ): int {
		$this->assert_locked( $creator_id );
		$wpdb = $this->wpdb;
		$time = self::utc( $now );

		$this->guarded(
			$wpdb->prepare(
				'INSERT INTO %i (creator_id, currency, amount, price_decimals, method, status, destination_hint, idempotency_key, requested_by, requested_at, updated_at) SELECT %d, %s, %s, %d, %s, %s, %s, %s, NULLIF(%d, 0), %s, %s FROM DUAL WHERE CONNECTION_ID() = %d',
				$this->table( Tables::PAYOUTS ),
				$creator_id,
				$currency,
				SalesAmount::format( $amount_units ),
				$price_decimals,
				$method->value,
				PayoutStatus::Pending->value,
				$destination_hint,
				$idempotency_key,
				(int) $requested_by,
				$time,
				$time,
				$this->lock_connection
			)
		);
		$id = (int) $wpdb->insert_id;

		if ( $id < 1 ) {
			throw new DatabaseException( 'Could not store the payout.' );
		}

		if ( null === $ciphertext ) {
			return $id;
		}

		$this->guarded(
			$wpdb->prepare(
				'INSERT INTO %i (payout_id, kind, ciphertext, key_id, created_at) SELECT %d, %s, %s, %s, %s FROM DUAL WHERE CONNECTION_ID() = %d',
				$this->table( Tables::PAYOUT_SECRETS ),
				$id,
				$method->value,
				$ciphertext,
				$key_id,
				$time,
				$this->lock_connection
			)
		);

		return $id;
	}

	/**
	 * Moves a pending payout to a final status and empties its encrypted
	 * details, both guarded. The caller has read the row FOR UPDATE in the
	 * same transaction and checked that it is pending. Requires the creator's
	 * lock.
	 *
	 * @param Payout            $payout     The pending payout.
	 * @param PayoutStatus      $status     Completed, rejected or cancelled.
	 * @param int|null          $decided_by WordPress user.
	 * @param DateTimeImmutable $now        Decision time.
	 * @param string|null       $reason     Rejection reason.
	 * @param string|null       $reference  Completion reference.
	 * @throws DatabaseException When the lock is not held, the payout is not pending, or a write fails.
	 */
	public function finish( Payout $payout, PayoutStatus $status, ?int $decided_by, DateTimeImmutable $now, ?string $reason, ?string $reference ): void {
		$this->assert_locked( $payout->creator_id );

		if ( PayoutStatus::Pending === $status ) {
			throw new DatabaseException( 'A payout can only leave the pending status.' );
		}

		$wpdb = $this->wpdb;
		$time = self::utc( $now );

		$this->guarded(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, decided_by = NULLIF(%d, 0), decided_at = %s, decision_reason = NULLIF(%s, ''), reference = NULLIF(%s, ''), updated_at = %s WHERE id = %d AND creator_id = %d AND status = %s AND CONNECTION_ID() = %d",
				$this->table( Tables::PAYOUTS ),
				$status->value,
				(int) $decided_by,
				$time,
				(string) $reason,
				(string) $reference,
				$time,
				$payout->id,
				$payout->creator_id,
				PayoutStatus::Pending->value,
				$this->lock_connection
			)
		);

		$this->purge_secret( $payout->id, $now );
	}

	/**
	 * Completes a pending gift card payout with the registered card, guarded
	 * as finish(). `UNIQUE(gift_card_provider, gift_card_external_id)` makes
	 * a card that another payout already has a refusal, also between two
	 * creators' locks. Requires the creator's lock.
	 *
	 * @param Payout            $payout      The pending payout, read FOR UPDATE.
	 * @param string            $provider    Provider id.
	 * @param string            $external_id The card's id in the provider.
	 * @param int|null          $decided_by  WordPress user.
	 * @param DateTimeImmutable $now         Decision time.
	 * @throws PayoutException When the card is registered on another payout.
	 * @throws DatabaseException When the lock is not held, the payout is not pending, or a write fails.
	 */
	public function complete_gift_card( Payout $payout, string $provider, string $external_id, ?int $decided_by, DateTimeImmutable $now ): void {
		$this->assert_locked( $payout->creator_id );
		$wpdb = $this->wpdb;
		$time = self::utc( $now );
		$sql  = $wpdb->prepare(
			'UPDATE %i SET status = %s, decided_by = NULLIF(%d, 0), decided_at = %s, gift_card_provider = %s, gift_card_external_id = %s, updated_at = %s WHERE id = %d AND creator_id = %d AND method = %s AND status = %s AND CONNECTION_ID() = %d',
			$this->table( Tables::PAYOUTS ),
			PayoutStatus::Completed->value,
			(int) $decided_by,
			$time,
			$provider,
			$external_id,
			$time,
			$payout->id,
			$payout->creator_id,
			PayoutMethod::GiftCard->value,
			PayoutStatus::Pending->value,
			$this->lock_connection
		);

		if ( null === $sql ) {
			throw new DatabaseException( 'Could not prepare the payout write.' );
		}

		// A duplicate is an expected refusal, not an error to display.
		$suppressed = $wpdb->suppress_errors( true );

		try {
			$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}

		if ( false === $result && str_contains( $wpdb->last_error, 'Duplicate' ) ) {
			throw new PayoutException( PayoutException::GIFT_CARD_LINKED );
		}

		if ( false === $result || '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not write the payout.' );
		}

		if ( 1 !== $result ) {
			throw new DatabaseException( 'The payout write changed no row; the connection or the row changed.' );
		}
	}

	/**
	 * The encrypted details of a payout, or null when there are none (left).
	 *
	 * @param int $payout_id Payout.
	 * @return array{ciphertext: string, key_id: string}|null
	 * @throws DatabaseException When the read fails.
	 */
	public function secret( int $payout_id ): ?array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT ciphertext, key_id FROM %i WHERE payout_id = %d AND ciphertext IS NOT NULL', $this->table( Tables::PAYOUT_SECRETS ), $payout_id ), ARRAY_A );

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the payout details.' );
		}

		return is_array( $row ) && is_string( $row['ciphertext'] ?? null ) && is_string( $row['key_id'] ?? null )
			? array(
				'ciphertext' => $row['ciphertext'],
				'key_id'     => $row['key_id'],
			)
			: null;
	}

	/**
	 * The creator's settlement start (null = the whole history).
	 *
	 * @param int  $creator_id Creator.
	 * @param bool $for_update Lock the creator row (inside a transaction).
	 * @throws PayoutException When the creator does not exist.
	 * @throws DatabaseException When the read fails or the value is invalid.
	 */
	public function settlement_start( int $creator_id, bool $for_update = false ): ?DateTimeImmutable {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT id, payout_start_at FROM %i WHERE id = %d' . ( $for_update ? ' FOR UPDATE' : '' ), $this->table( Tables::CREATORS ), $creator_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant suffix.

		if ( '' !== $wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the settlement start.' );
		}

		if ( ! is_array( $row ) ) {
			throw new PayoutException( PayoutException::NOT_FOUND );
		}

		return null === $row['payout_start_at'] ? null : self::time( $row['payout_start_at'] );
	}

	/**
	 * Stores the creator's settlement start. Requires the creator's lock.
	 *
	 * @param int                    $creator_id Creator.
	 * @param DateTimeImmutable|null $start      Start, null for the whole history.
	 * @param DateTimeImmutable      $now        Change time.
	 * @throws DatabaseException When the lock is not held or the write fails.
	 */
	public function set_settlement_start( int $creator_id, ?DateTimeImmutable $start, DateTimeImmutable $now ): void {
		$this->assert_locked( $creator_id );
		$wpdb = $this->wpdb;

		$this->guarded(
			$wpdb->prepare(
				"UPDATE %i SET payout_start_at = NULLIF(%s, ''), updated_at = %s WHERE id = %d AND CONNECTION_ID() = %d",
				$this->table( Tables::CREATORS ),
				null === $start ? '' : self::utc( $start ),
				self::utc( $now ),
				$creator_id,
				$this->lock_connection
			)
		);
	}

	/**
	 * Empties the encrypted details of a payout (planning decision 6: they
	 * exist only while it is pending). A payout without details is fine.
	 *
	 * @param int               $payout_id Payout.
	 * @param DateTimeImmutable $now       Time.
	 * @throws DatabaseException When the write fails or the connection changed.
	 */
	private function purge_secret( int $payout_id, DateTimeImmutable $now ): void {
		$wpdb = $this->wpdb;
		$sql  = $wpdb->prepare(
			'UPDATE %i SET ciphertext = NULL, purged_at = %s WHERE payout_id = %d AND ciphertext IS NOT NULL AND CONNECTION_ID() = %d',
			$this->table( Tables::PAYOUT_SECRETS ),
			self::utc( $now ),
			$payout_id,
			$this->lock_connection
		);

		if ( null === $sql || false === $wpdb->query( $sql ) || '' !== $wpdb->last_error ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			throw new DatabaseException( 'Could not remove the payout details.' );
		}
	}

	/**
	 * Runs a guarded write that must change exactly one row. Zero rows means
	 * the connection is not the one holding the lock (or, for an UPDATE, the
	 * row is no longer as the caller read it under the lock).
	 *
	 * @param string|null $sql Result of $wpdb->prepare().
	 * @throws DatabaseException When it fails or changes no row.
	 */
	private function guarded( ?string $sql ): void {
		if ( null === $sql ) {
			throw new DatabaseException( 'Could not prepare the payout write.' );
		}

		$result = $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the result of $wpdb->prepare().

		if ( false === $result || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not write the payout.' );
		}

		if ( 1 !== $result ) {
			throw new DatabaseException( 'The payout write changed no row; the connection or the row changed.' );
		}
	}

	/**
	 * Requires the creator's lock to be held by this instance.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When it is not.
	 */
	private function assert_locked( int $creator_id ): void {
		if ( $this->locked_creator !== $creator_id ) {
			throw new DatabaseException( 'The payout lock of the creator is not held.', array( 'creator_id' => $creator_id ) );
		}
	}

	/**
	 * Name of the creator's lock, unique per database and table prefix.
	 *
	 * @param int $creator_id Creator.
	 */
	private function lock_name( int $creator_id ): string {
		$database = defined( 'DB_NAME' ) ? (string) constant( 'DB_NAME' ) : '';

		return self::LOCK_PREFIX . substr( md5( $database . '|' . $this->wpdb->prefix ), 0, 12 ) . ':' . $creator_id;
	}

	/**
	 * Runs a prepared COUNT.
	 *
	 * @param string|null $sql Result of $wpdb->prepare().
	 * @throws DatabaseException When the read fails.
	 */
	private function count( ?string $sql ): int {
		$value = null === $sql ? null : $this->wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Callers pass the result of $wpdb->prepare().

		if ( '' !== $this->wpdb->last_error || ! is_numeric( $value ) ) {
			throw new DatabaseException( 'Could not count the creator\'s payouts.' );
		}

		return (int) $value;
	}

	/**
	 * Runs a prepared SELECT of at most one payout.
	 *
	 * @param string|null $sql Result of $wpdb->prepare().
	 * @throws DatabaseException When the read fails or the row is invalid.
	 */
	private function one( ?string $sql ): ?Payout {
		$row = null === $sql ? null : $this->wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Callers pass the result of $wpdb->prepare().

		if ( null === $sql || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not read the payout.' );
		}

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * A payout from a row.
	 *
	 * @param array<mixed> $row Row.
	 * @throws DatabaseException When the row is invalid.
	 */
	private static function hydrate( array $row ): Payout {
		try {
			$method = PayoutMethod::tryFrom( (string) $row['method'] );
			$status = PayoutStatus::tryFrom( (string) $row['status'] );

			if ( null === $method || null === $status ) {
				throw new DatabaseException( 'Invalid payout row.' );
			}

			return new Payout(
				(int) $row['id'],
				(int) $row['creator_id'],
				(string) $row['currency'],
				SalesAmount::parse( (string) $row['amount'] ),
				(int) $row['price_decimals'],
				$method,
				$status,
				(string) $row['destination_hint'],
				(string) $row['idempotency_key'],
				null === $row['requested_by'] ? null : (int) $row['requested_by'],
				self::time( $row['requested_at'] ),
				null === $row['decided_by'] ? null : (int) $row['decided_by'],
				null === $row['decided_at'] ? null : self::time( $row['decided_at'] ),
				null === $row['decision_reason'] ? null : (string) $row['decision_reason'],
				null === $row['reference'] ? null : (string) $row['reference'],
				null === $row['gift_card_provider'] ? null : (string) $row['gift_card_provider'],
				null === $row['gift_card_external_id'] ? null : (string) $row['gift_card_external_id']
			);
		} catch ( DatabaseException $e ) {
			throw $e;
		} catch ( Throwable ) {
			throw new DatabaseException( 'Invalid payout row.' );
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
			throw new DatabaseException( 'Invalid payout time.' );
		}

		return $time;
	}

	/**
	 * A time as stored (UTC).
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function utc( DateTimeImmutable $time ): string {
		return $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
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
