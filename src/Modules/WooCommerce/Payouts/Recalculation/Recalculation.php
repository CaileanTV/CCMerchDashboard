<?php
/**
 * A recalculation of settled payouts (ADR-063).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Recalculation;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * A row of `merchboard_payout_recalculations`. Its status moves only
 * forward, each step by a separate, audited action of an administrator:
 *
 * Preview → approved → executed | partially_executed → closed;
 * preview | approved → rejected (a rejected recalculation is never reopened
 * automatically; reopening creates a new recalculation that refers to it).
 */
final readonly class Recalculation {

	public const PREVIEW = 'preview';

	public const APPROVED = 'approved';

	public const REJECTED = 'rejected';

	public const PARTIALLY_EXECUTED = 'partially_executed';

	public const EXECUTED = 'executed';

	public const CLOSED = 'closed';

	/**
	 * Statuses in which a recalculation is open (at most one at a time).
	 */
	public const OPEN = array( self::PREVIEW, self::APPROVED, self::PARTIALLY_EXECUTED );

	/**
	 * Constructor.
	 *
	 * @param int                    $id                  Id.
	 * @param string                 $rule                RecalculationRule::RULE.
	 * @param string                 $calculation_version Calculation of the recalculated amounts.
	 * @param string                 $status              One of the class constants.
	 * @param string                 $fingerprint         SHA-256 of the items as previewed.
	 * @param int|null               $reopens_id          The rejected recalculation it reopens.
	 * @param int|null               $created_by          User who made the preview.
	 * @param DateTimeImmutable      $created_at          Time of the preview (UTC).
	 * @param int|null               $decided_by          User who approved or rejected it.
	 * @param DateTimeImmutable|null $decided_at          Time of the decision (UTC).
	 * @param string                 $decision_note       Note of the decision.
	 * @param int|null               $closed_by           User who closed it.
	 * @param DateTimeImmutable|null $closed_at           Time of closing (UTC).
	 * @param string                 $close_note          Note of the closing.
	 */
	public function __construct(
		public int $id,
		public string $rule,
		public string $calculation_version,
		public string $status,
		public string $fingerprint,
		public ?int $reopens_id,
		public ?int $created_by,
		public DateTimeImmutable $created_at,
		public ?int $decided_by,
		public ?DateTimeImmutable $decided_at,
		public string $decision_note,
		public ?int $closed_by,
		public ?DateTimeImmutable $closed_at,
		public string $close_note
	) {
	}

	/**
	 * The unique reference shown to administrators and on settlement documents.
	 *
	 * @param int $id Recalculation id.
	 */
	public static function reference_of( int $id ): string {
		return 'R2-' . str_pad( (string) $id, 6, '0', STR_PAD_LEFT );
	}

	/**
	 * The unique reference of this recalculation.
	 */
	public function reference(): string {
		return self::reference_of( $this->id );
	}

	/**
	 * Whether it is open (preview, approved or partly executed).
	 */
	public function is_open(): bool {
		return in_array( $this->status, self::OPEN, true );
	}
}
