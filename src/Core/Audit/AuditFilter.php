<?php
/**
 * Audit log filter.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Audit;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Validated filter for reading the audit trail (phase 11): action, creator
 * and period. Times are UTC DATETIME strings ('Y-m-d H:i:s'); the period is
 * [from, until). Construct it only from validated values: the admin screen
 * parses request input, this class re-checks the shapes.
 */
final readonly class AuditFilter {

	/**
	 * Constructor.
	 *
	 * @param AuditAction|null $action     Only this action.
	 * @param int|null         $creator_id Only this creator (positive).
	 * @param string|null      $from       Inclusive lower bound (UTC).
	 * @param string|null      $until      Exclusive upper bound (UTC).
	 * @throws InvalidArgumentException When a value has an invalid shape.
	 */
	public function __construct(
		public ?AuditAction $action = null,
		public ?int $creator_id = null,
		public ?string $from = null,
		public ?string $until = null
	) {
		if ( null !== $creator_id && $creator_id < 1 ) {
			throw new InvalidArgumentException( 'Creator id must be a positive integer.' );
		}

		foreach ( array( $from, $until ) as $time ) {
			if ( null !== $time && 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $time ) ) {
				throw new InvalidArgumentException( 'Times must be UTC DATETIME strings.' );
			}
		}
	}

	/**
	 * Whether any filter is set.
	 */
	public function is_empty(): bool {
		return null === $this->action && null === $this->creator_id && null === $this->from && null === $this->until;
	}
}
