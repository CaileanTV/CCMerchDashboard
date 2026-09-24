<?php
/**
 * Records audit events.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Audit;

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

		return $this->repository->append(
			$action,
			$actor > 0 ? $actor : null,
			$creator_id,
			LogContext::sanitize( $context ),
			current_time( 'mysql', true )
		);
	}
}
