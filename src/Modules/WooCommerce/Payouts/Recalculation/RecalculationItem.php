<?php
/**
 * An item of a recalculation (ADR-063).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Recalculation;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * One creator, currency and group of a recalculation, as previewed. Amounts
 * are units; they are null for an item that needs a manual assessment.
 *
 * Groups (ADR-063 pkt. 3):
 * - `active`: an active creator; the difference can be approved and executed
 *   as an adjustment of the balance (a negative one is offset against what
 *   is available and the rest is carried forward as outstanding);
 * - `former`: a creator who is no longer active; reported only, never paid
 *   or offset automatically;
 * - `manual`: the stored data cannot be recalculated safely; reported for a
 *   manual assessment, never executed.
 */
final readonly class RecalculationItem {

	public const ACTIVE = 'active';

	public const FORMER = 'former';

	public const MANUAL = 'manual';

	/**
	 * An active item waiting for execution.
	 */
	public const PROPOSED = 'proposed';

	public const EXECUTED = 'executed';

	/**
	 * An execution of the item failed; nothing of it was written.
	 */
	public const FAILED = 'failed';

	/**
	 * A former creator's item: reported, never executed.
	 */
	public const REPORTED = 'reported';

	/**
	 * Needs a manual assessment; never executed.
	 */
	public const NEEDS_REVIEW = 'manual';

	/**
	 * A settled row did not reproduce from its stored inputs.
	 */
	public const REASON_INCONSISTENT_ROW = 'inconsistent_row';

	/**
	 * Completed payouts without a settlement document (before ADR-060): their
	 * rows are not known.
	 */
	public const REASON_UNDOCUMENTED_PAYOUTS = 'undocumented_payouts';

	/**
	 * Constructor.
	 *
	 * @param int                    $id                 Id.
	 * @param int                    $recalculation_id   Recalculation.
	 * @param int                    $creator_id         Creator.
	 * @param string                 $currency           Currency.
	 * @param string                 $category           ACTIVE, FORMER or MANUAL.
	 * @param string                 $reason             Reason of a MANUAL item, else ''.
	 * @param int|null               $original_units     Payout as settled.
	 * @param int|null               $recalculated_units Payout without the deduction.
	 * @param int|null               $difference_units   Recalculated − original.
	 * @param int                    $row_count          Settled rows.
	 * @param array<mixed>           $detail             Rows and documents as previewed.
	 * @param string                 $fingerprint        SHA-256 of the item as previewed.
	 * @param string                 $status             PROPOSED, EXECUTED, FAILED, REPORTED or NEEDS_REVIEW.
	 * @param string                 $failure            Reason of the last failed execution.
	 * @param int|null               $executed_by        User who executed it.
	 * @param DateTimeImmutable|null $executed_at        Time of execution (UTC).
	 */
	public function __construct(
		public int $id,
		public int $recalculation_id,
		public int $creator_id,
		public string $currency,
		public string $category,
		public string $reason,
		public ?int $original_units,
		public ?int $recalculated_units,
		public ?int $difference_units,
		public int $row_count,
		public array $detail,
		public string $fingerprint,
		public string $status,
		public string $failure,
		public ?int $executed_by,
		public ?DateTimeImmutable $executed_at
	) {
	}

	/**
	 * The item's key within a recalculation.
	 */
	public function key(): string {
		return self::key_of( $this->creator_id, $this->currency, $this->category );
	}

	/**
	 * The key of a creator, currency and group.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $currency   Currency.
	 * @param string $category   Group.
	 */
	public static function key_of( int $creator_id, string $currency, string $category ): string {
		return $creator_id . ':' . $currency . ':' . $category;
	}
}
