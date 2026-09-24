<?php
/**
 * Audit entry.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Audit;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * One stored audit record (read model).
 */
final readonly class AuditEntry {

	/**
	 * Constructor.
	 *
	 * @param int                  $id            Record id.
	 * @param AuditAction          $action        What happened.
	 * @param int|null             $actor_user_id WordPress user who did it; null for system actions.
	 * @param int|null             $creator_id    Affected creator, if any.
	 * @param array<string, mixed> $context       Sanitized context.
	 * @param DateTimeImmutable    $created_at    When (UTC).
	 */
	public function __construct(
		public int $id,
		public AuditAction $action,
		public ?int $actor_user_id,
		public ?int $creator_id,
		public array $context,
		public DateTimeImmutable $created_at
	) {
	}
}
