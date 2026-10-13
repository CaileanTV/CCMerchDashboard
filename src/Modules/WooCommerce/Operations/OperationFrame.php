<?php
/**
 * The E3 frame for registered state mutations of an order.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Operations;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\ConnectionChangedException;
use MerchBoard\Core\Database\DatabaseErrno;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\RepeatableReadTransaction;
use MerchBoard\Core\Database\SchemaVersionRow;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\WooCommerce\Payouts\CreatorRowLocks;
use MerchBoard\Modules\WooCommerce\Payouts\G1\G1AssessmentRow;
use MerchBoard\Modules\WooCommerce\Payouts\G1\G1StateRow;
use MerchBoard\Modules\WooCommerce\Sales\SalesConnectionChangedException;
use MerchBoard\Modules\WooCommerce\Sales\SalesLockTimeoutException;
use MerchBoard\Modules\WooCommerce\Sales\SalesRepository;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * E3.2 steps 1-14 with F-5's registry (E3 T4). Nothing calls it yet: no
 * container service, flow, UI, REST or WP-CLI.
 *
 * Under the order's lock (N1), before START: on MariaDB no open transaction
 * (step 2a); the G1 status from the version and activation rows, the
 * order's creators and whether its G1 assessment exists (2b, 2c); the
 * lock's connection (3). RepeatableReadTransaction sets the timeout and
 * REPEATABLE READ and starts the transaction (4-6). Inside: R0 (version
 * and activation rows shared; a G1 status other than in 2b gives `busy`),
 * R1 (the creators' rows), R2 (only the G1 assessment, B-4, and only if it
 * exists), then F-5's pre-check of the registry, the caller's `$admit`
 * (the expected prior state, read after the last lock), the registry row
 * as the first write, the caller's `$mutation`, the audit record with the
 * operation id, the order lock check and COMMIT; after COMMIT the
 * connection id is checked again (E3.5(d)).
 *
 * The callbacks get the context array; they must throw right after a failed
 * statement (so its error number is read here) and write only guarded by
 * `connection_id`. Classification follows E3.6 and F-5 (classify()); a
 * duplicate registry id or an unreadable error number is reconciled at once
 * in a new transaction under the same order lock. $transaction must be the
 * instance $frame runs on, and $sales the instance whose order lock is
 * taken here.
 *
 * @phpstan-type Context array{order_id: int, connection_id: int, creator_ids: list<int>, g1_active: bool, assessment: array{status: string, reason_code: string|null, updated_at: string}|null}
 */
final class OperationFrame {

	/**
	 * Default seconds an InnoDB row lock is waited for.
	 */
	public const LOCK_WAIT_TIMEOUT = 5;

	/**
	 * Decision of classify(): reconcile in a new transaction.
	 */
	public const RECONCILE = 'reconcile';

	/**
	 * Schema version that created the G1 tables.
	 */
	private const G1_SCHEMA_VERSION = 12;

	/**
	 * Error numbers of a lock conflict (D-1).
	 */
	private const LOCK_CONFLICTS = array( 1205, 1213 );

	/**
	 * Constructor.
	 *
	 * @param wpdb                      $wpdb              WordPress database object.
	 * @param SalesRepository           $sales             Holder of the order locks.
	 * @param Transaction               $transaction       The transaction $frame runs on.
	 * @param RepeatableReadTransaction $frame             Timeout and isolation around Transaction::run().
	 * @param CreatorRowLocks           $creator_locks     R1.
	 * @param AuditTrail                $audit             Audit trail.
	 * @param Logger                    $logger            Technical log.
	 * @param int                       $lock_wait_timeout Seconds an InnoDB row lock is waited for (≥ 1).
	 */
	public function __construct(
		private wpdb $wpdb,
		private SalesRepository $sales,
		private Transaction $transaction,
		private RepeatableReadTransaction $frame,
		private CreatorRowLocks $creator_locks,
		private AuditTrail $audit,
		private Logger $logger,
		private int $lock_wait_timeout = self::LOCK_WAIT_TIMEOUT
	) {
	}

	/**
	 * Applies the operation once (F-5 section 1).
	 *
	 * @phpstan-param callable(Context): bool $admit
	 * @phpstan-param callable(Context): void $mutation
	 * @param Operation            $operation  The operation.
	 * @param callable             $admit      Whether the expected prior state holds (reads only).
	 * @param callable             $mutation   The state change (guarded writes only).
	 * @param AuditAction          $action     Audit action.
	 * @param int|null             $creator_id Affected creator of the audit record.
	 * @param array<string, mixed> $context    Audit context (the operation id is added).
	 */
	public function execute( Operation $operation, callable $admit, callable $mutation, AuditAction $action, ?int $creator_id, array $context ): OperationResult {
		return $this->under_order_lock( $operation, fn (): OperationResult => $this->attempt( $operation, $admit, $mutation, $action, $creator_id, $context ), false );
	}

	/**
	 * Reconciles an earlier attempt without writing (F-5 section 2).
	 *
	 * @phpstan-param callable(Context): bool $admit
	 * @param Operation $operation The operation.
	 * @param callable  $admit     Whether the expected prior state holds (reads only).
	 */
	public function reconcile( Operation $operation, callable $admit ): OperationResult {
		return $this->under_order_lock( $operation, fn (): OperationResult => $this->attempt( $operation, $admit, null, null, null, array() ), true );
	}

	/**
	 * Maps a failed attempt or reconciliation to its result (E3.6, F-5,
	 * D-1 to D-5). Pure; runs no SQL.
	 *
	 * @param bool           $reconciling      Whether the failure is a reconciliation's.
	 * @param bool           $entered          Whether the transaction's work started.
	 * @param bool           $finished         Whether the work returned (a failure is then COMMIT's).
	 * @param bool           $rollback_failed  Whether this run's ROLLBACK failed.
	 * @param bool           $changed          Whether the connection or the order lock was lost.
	 * @param int|null|false $errno            Error number of the failed statement; null: unreadable; false: none failed.
	 * @param string|null    $statement        Which statement failed ('registry_insert' matters).
	 * @return string A result code or RECONCILE.
	 */
	public static function classify( bool $reconciling, bool $entered, bool $finished, bool $rollback_failed, bool $changed, int|null|false $errno, ?string $statement ): string {
		if ( $entered && $rollback_failed ) {
			return OperationResult::OUTCOME_UNKNOWN;
		}

		if ( $changed || $finished ) {
			return OperationResult::OUTCOME_UNKNOWN;
		}

		if ( is_int( $errno ) && in_array( $errno, self::LOCK_CONFLICTS, true ) ) {
			return OperationResult::BUSY;
		}

		if ( $reconciling ) {
			return OperationResult::OUTCOME_UNKNOWN;
		}

		if ( $entered && null === $errno ) {
			return self::RECONCILE;
		}

		if ( 'registry_insert' === $statement && 1062 === $errno ) {
			return self::RECONCILE;
		}

		return OperationResult::ERROR;
	}

	/**
	 * Runs $work under the order's lock (E3.2 step 1).
	 *
	 * @param Operation                   $operation   The operation.
	 * @param callable(): OperationResult $work     Work under the lock.
	 * @param bool                        $reconciling Whether this is a reconciliation.
	 */
	private function under_order_lock( Operation $operation, callable $work, bool $reconciling ): OperationResult {
		try {
			return $this->sales->with_order_lock( $operation->order_id, $work );
		} catch ( SalesLockTimeoutException ) {
			return OperationResult::of( OperationResult::BUSY );
		} catch ( Throwable ) {
			return $reconciling ? OperationResult::of( OperationResult::OUTCOME_UNKNOWN ) : OperationResult::error( 'order_lock' );
		}
	}

	/**
	 * One attempt (with $mutation) or reconciliation (without), under the order's lock.
	 *
	 * @phpstan-param callable(Context): bool      $admit
	 * @phpstan-param (callable(Context): void)|null $mutation
	 * @param Operation            $operation  The operation.
	 * @param callable             $admit      Expected prior state.
	 * @param callable|null        $mutation   State change; null to reconcile.
	 * @param AuditAction|null     $action     Audit action (with $mutation).
	 * @param int|null             $creator_id Audit creator.
	 * @param array<string, mixed> $context    Audit context.
	 */
	private function attempt( Operation $operation, callable $admit, ?callable $mutation, ?AuditAction $action, ?int $creator_id, array $context ): OperationResult {
		$reconciling = null === $mutation;
		$order_id    = $operation->order_id;

		try {
			$before = $this->before_start( $order_id );
		} catch ( Throwable ) {
			return $reconciling ? OperationResult::of( OperationResult::OUTCOME_UNKNOWN ) : OperationResult::error( 'db_error' );
		}

		if ( null === $before ) {
			return $reconciling ? OperationResult::of( OperationResult::OUTCOME_UNKNOWN ) : OperationResult::error( 'open_transaction' );
		}

		$errno     = false;
		$statement = null;
		$entered   = false;
		$finished  = false;

		try {
			$result = $this->frame->run(
				function () use ( $operation, $admit, $mutation, $action, $creator_id, $context, $before, &$errno, &$statement, &$entered, &$finished ): OperationResult {
					$entered  = true;
					$result   = $this->work( $operation, $admit, $mutation, $action, $creator_id, $context, $before, $errno, $statement );
					$finished = true;

					return $result;
				},
				$this->lock_wait_timeout
			);
		} catch ( Throwable $e ) {
			$changed  = $e instanceof ConnectionChangedException || $e instanceof SalesConnectionChangedException;
			$failed   = $entered && $this->transaction->rollback_failed();
			$decision = self::classify( $reconciling, $entered, $finished, $failed, $changed, $errno, $statement );

			if ( $failed ) {
				$this->logger->error( 'An order operation could not be rolled back; its outcome is unknown.', array( 'severity' => 'critical' ) );
			}

			if ( self::RECONCILE === $decision ) {
				return $this->attempt( $operation, $admit, null, null, null, array() );
			}

			return OperationResult::ERROR === $decision ? OperationResult::error( 'db_error' ) : OperationResult::of( $decision );
		}

		if ( ! $reconciling && OperationResult::COMPLETED === $result->code && $finished && (string) $before['connection_id'] !== (string) $this->wpdb->get_var( 'SELECT CONNECTION_ID()' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The session's id; never cached.
			// After COMMIT: another session means the outcome is unknown (E3.5(d)).
			return OperationResult::of( OperationResult::OUTCOME_UNKNOWN );
		}

		return $result;
	}

	/**
	 * E3.2 steps 2-3, in autocommit under the order's lock.
	 *
	 * @param int $order_id Order.
	 * @return array{schema_g1: bool, g1_active: bool, creator_ids: list<int>, assessment_exists: bool, connection_id: int}|null Null when a transaction is open (MariaDB).
	 * @throws DatabaseException When a read fails or a row is missing.
	 */
	private function before_start( int $order_id ): ?array {
		$wpdb = $this->wpdb;

		// 2a: MariaDB only; MySQL 8.0 has no verified check (B B).
		if ( str_contains( strtolower( (string) $wpdb->get_var( 'SELECT VERSION()' ) ), 'mariadb' ) && '1' === (string) $wpdb->get_var( 'SELECT @@in_transaction' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session state; never cached.
			return null;
		}

		// 2b: the version row directly, never the option cache; then the activation row.
		$schema_g1 = (int) SchemaVersionRow::read( $wpdb ) >= self::G1_SCHEMA_VERSION;
		$g1_active = $schema_g1 && self::active( G1StateRow::read( $wpdb ) );

		// 2c.
		return array(
			'schema_g1'         => $schema_g1,
			'g1_active'         => $g1_active,
			'creator_ids'       => $this->sales->creator_ids_for_order( $order_id ),
			'assessment_exists' => $schema_g1 && G1AssessmentRow::exists( $wpdb, $order_id ),
			'connection_id'     => $this->sales->lock_connection( $order_id ),
		);
	}

	/**
	 * The transaction's work (E3.2 steps 7-12).
	 *
	 * @phpstan-param callable(Context): bool      $admit
	 * @phpstan-param (callable(Context): void)|null $mutation
	 * @param Operation                                                                                                    $operation  The operation.
	 * @param callable                                                                                                     $admit      Expected prior state.
	 * @param callable|null                                                                                                $mutation   State change; null to reconcile.
	 * @param AuditAction|null                                                                                             $action     Audit action.
	 * @param int|null                                                                                                     $creator_id Audit creator.
	 * @param array<string, mixed>                                                                                         $context    Audit context.
	 * @param array{schema_g1: bool, g1_active: bool, creator_ids: list<int>, assessment_exists: bool, connection_id: int} $before     Steps 2-3.
	 * @param int|null|false                                                                                               $errno      Set to the failed statement's error number.
	 * @param string|null                                                                                                  $statement  Set to the failed statement's name.
	 * @throws DatabaseException When a statement fails ($errno is set first).
	 * @throws ConnectionChangedException When a guarded write ran on another connection (SalesConnectionChangedException when the order lock was lost before COMMIT).
	 */
	private function work( Operation $operation, callable $admit, ?callable $mutation, ?AuditAction $action, ?int $creator_id, array $context, array $before, int|null|false &$errno, ?string &$statement ): OperationResult {
		$wpdb     = $this->wpdb;
		$order_id = $operation->order_id;

		// R0: a G1 status other than before START waits for no one: busy.
		$schema_g1 = (int) $this->statement( 'version', static fn (): string => SchemaVersionRow::read_shared( $wpdb ), $errno, $statement ) >= self::G1_SCHEMA_VERSION;
		$g1_active = $schema_g1 && self::active( $this->statement( 'activation', static fn (): ?array => G1StateRow::read_shared( $wpdb ), $errno, $statement ) );

		if ( $schema_g1 !== $before['schema_g1'] || $g1_active !== $before['g1_active'] ) {
			return OperationResult::of( OperationResult::BUSY );
		}

		// R1.
		$this->statement( 'creators', fn (): array => $this->creator_locks->acquire_for_order( $this->sales, $order_id, $before['creator_ids'] ), $errno, $statement );

		// R2 (B-4): the G1 assessment, only when it exists (I4).
		$assessment = null;

		if ( $before['assessment_exists'] ) {
			$assessment = $this->statement( 'assessment', static fn (): ?array => G1AssessmentRow::lock( $wpdb, $order_id ), $errno, $statement );

			if ( null === $assessment ) {
				// I5 broken: only an order-locked path may change it, and none deletes it.
				$this->logger->error( 'The G1 assessment disappeared under the order lock.', array( 'order_id' => $order_id ) );

				return OperationResult::error( 'assessment_missing' );
			}
		}

		$state = array(
			'order_id'      => $order_id,
			'connection_id' => $before['connection_id'],
			'creator_ids'   => $before['creator_ids'],
			'g1_active'     => $g1_active,
			'assessment'    => $assessment,
		);

		// F-5's pre-check.
		$registered = $this->statement( 'registry_read', static fn (): ?array => OperationRegistry::find_shared( $wpdb, $operation->id ), $errno, $statement );

		if ( null !== $registered ) {
			return OperationRegistry::matches( $registered, $operation ) ? OperationResult::of( OperationResult::COMPLETED ) : OperationResult::of( OperationResult::OPERATION_MISMATCH );
		}

		$admitted = (bool) $this->statement( 'admit', static fn (): bool => (bool) $admit( $state ), $errno, $statement );

		if ( null === $mutation || null === $action ) {
			return OperationResult::of( $admitted ? OperationResult::RETRY_SAFE : OperationResult::CONFLICT );
		}

		if ( ! $admitted ) {
			return OperationResult::of( OperationResult::CONFLICT );
		}

		// The registry row is the first write.
		$actor    = get_current_user_id();
		$inserted = $this->statement( 'registry_insert', static fn (): int => OperationRegistry::insert( $wpdb, $operation, $actor > 0 ? $actor : null, current_time( 'mysql', true ), $before['connection_id'] ), $errno, $statement );

		if ( 1 !== $inserted ) {
			// (h): 0 rows on another connection is an unknown outcome; on this one an error.
			if ( (string) $before['connection_id'] !== (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The session's id; never cached.
				throw new ConnectionChangedException( 'The database connection changed before the operation was registered.' );
			}

			$errno     = 0;
			$statement = 'registry_insert';
			throw new DatabaseException( 'The operation was not registered.' );
		}

		$this->statement(
			'mutation',
			static function () use ( $mutation, $state ): bool {
				$mutation( $state );

				return true;
			},
			$errno,
			$statement
		);
		$this->statement( 'audit', fn (): int => $this->audit->record_on_connection( $action, $creator_id, array_merge( $context, array( 'operation_id' => $operation->id ) ), $before['connection_id'] ), $errno, $statement );

		// Step 12: the order lock is still this connection's.
		$this->sales->assert_order_lock( $order_id );

		return OperationResult::of( OperationResult::COMPLETED );
	}

	/**
	 * Runs one statement; on a DatabaseException records which statement
	 * failed and its error number before passing the exception on.
	 *
	 * @template T
	 * @param string         $name      Statement name.
	 * @param callable(): T  $run       The statement.
	 * @param int|null|false $errno     Set on failure (null: unreadable).
	 * @param string|null    $statement Set on failure.
	 * @return T
	 * @throws DatabaseException When the statement fails.
	 */
	private function statement( string $name, callable $run, int|null|false &$errno, ?string &$statement ): mixed {
		try {
			return $run();
		} catch ( DatabaseException $e ) {
			$errno     = DatabaseErrno::last( $this->wpdb );
			$statement = $name;
			throw $e;
		}
	}

	/**
	 * Whether an activation row is active.
	 *
	 * @param array{cutoff_utc: string|null, activated_at: string|null, activated_by: int|null}|null $row Row; null when missing.
	 * @throws DatabaseException When the row is missing (fail closed, E3.3).
	 */
	private static function active( ?array $row ): bool {
		if ( null === $row ) {
			throw new DatabaseException( 'The G1 activation row is missing.' );
		}

		return null !== $row['activated_at'];
	}
}
