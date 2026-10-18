<?php
/**
 * Records audit events.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Audit;

use MerchBoard\Core\Database\ConnectionChangedException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\LogContext;

defined( 'ABSPATH' ) || exit;

/**
 * The only way application code writes audit records (ADR-023).
 *
 * Actor and timestamp are determined here, server-side: the actor is the
 * current WordPress user (null when there is none, e.g. a future system job),
 * never a value from the request. Context is passed through the same
 * sanitizer as technical logs as a last line of defence; callers pass only
 * minimal, non-personal data (field names, ids, statuses).
 */
final class AuditTrail {

	/**
	 * Constructor.
	 *
	 * @param AuditRepository $repository Storage.
	 */
	public function __construct( private AuditRepository $repository ) {
	}

	/**
	 * Appends an audit record.
	 *
	 * @param AuditAction          $action     What happened.
	 * @param int|null             $creator_id Affected creator.
	 * @param array<string, mixed> $context    Minimal context (no personal data).
	 * @return int Record id.
	 * @throws DatabaseException When the record cannot be written.
	 */
	public function record( AuditAction $action, ?int $creator_id, array $context = array() ): int {
		$actor = get_current_user_id();

		return $this->append( $action, $actor > 0 ? $actor : null, $creator_id, $context );
	}

	/**
	 * Appends a record for a system action: the actor is always NULL, even if
	 * a user is logged in (e.g. WordPress deleting a user fires a hook in the
	 * deleting admin's request, but the change is WordPress' consequence, not
	 * that admin's creator decision).
	 *
	 * @param AuditAction          $action     What happened.
	 * @param int|null             $creator_id Affected creator.
	 * @param array<string, mixed> $context    Minimal context (no personal data).
	 * @return int Record id.
	 * @throws DatabaseException When the record cannot be written.
	 */
	public function record_system( AuditAction $action, ?int $creator_id, array $context = array() ): int {
		return $this->append( $action, null, $creator_id, $context );
	}

	/**
	 * Appends an audit record like record(), written only on the connection
	 * that holds the caller's lock (E3.5(c)); only for E3's own frames, while
	 * their transaction runs (E3.5(i)).
	 *
	 * @param AuditAction          $action        What happened.
	 * @param int|null             $creator_id    Affected creator.
	 * @param array<string, mixed> $context       Minimal context (no personal data).
	 * @param int                  $connection_id Connection that holds the caller's lock.
	 * @return int Record id.
	 * @throws ConnectionChangedException When the record was not written because the connection changed.
	 * @throws DatabaseException When the record cannot be written.
	 */
	public function record_on_connection( AuditAction $action, ?int $creator_id, array $context, int $connection_id ): int {
		$actor = get_current_user_id();

		return $this->repository->insert_on_connection(
			$action,
			$actor > 0 ? $actor : null,
			$creator_id,
			LogContext::sanitize( $context ),
			current_time( 'mysql', true ),
			$connection_id
		);
	}

	/**
	 * Appends a record for a system action like record_system(), written only
	 * on the connection that holds the caller's lock, like
	 * record_on_connection(); e.g. an order exclusion decided by the sales
	 * ledger synchronization (ADR-068), whose request may belong to the
	 * customer who placed the order.
	 *
	 * @param AuditAction          $action        What happened.
	 * @param int|null             $creator_id    Affected creator.
	 * @param array<string, mixed> $context       Minimal context (no personal data).
	 * @param int                  $connection_id Connection that holds the caller's lock.
	 * @return int Record id.
	 * @throws ConnectionChangedException When the record was not written because the connection changed.
	 * @throws DatabaseException When the record cannot be written.
	 */
	public function record_system_on_connection( AuditAction $action, ?int $creator_id, array $context, int $connection_id ): int {
		return $this->repository->insert_on_connection(
			$action,
			null,
			$creator_id,
			LogContext::sanitize( $context ),
			current_time( 'mysql', true ),
			$connection_id
		);
	}

	/**
	 * Writes the record with server-side time and sanitized context.
	 *
	 * @param AuditAction          $action     What happened.
	 * @param int|null             $actor      Actor or null.
	 * @param int|null             $creator_id Affected creator.
	 * @param array<string, mixed> $context    Context.
	 * @throws DatabaseException When the record cannot be written.
	 */
	private function append( AuditAction $action, ?int $actor, ?int $creator_id, array $context ): int {
		return $this->repository->append(
			$action,
			$actor,
			$creator_id,
			LogContext::sanitize( $context ),
			current_time( 'mysql', true )
		);
	}
}
