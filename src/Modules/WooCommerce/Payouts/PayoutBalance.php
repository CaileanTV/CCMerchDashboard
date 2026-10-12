<?php
/**
 * A creator's payout balance in one currency.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

defined( 'ABSPATH' ) || exit;

/**
 * Balance = earned − completed − reserved (ADR-054 pkt. 5), in units. When
 * the earned payout is unknown (a ledger row in the window cannot be
 * calculated), the balance and the available amount are unknown (null),
 * never 0, and nothing can be requested. A negative balance is outstanding
 * and is offset against future earnings (planning decision 12).
 */
final readonly class PayoutBalance {

	public const COMPLETE = 'complete';

	public const UNKNOWN = 'unknown';

	/**
	 * Constructor.
	 *
	 * @param string   $currency        Currency.
	 * @param int|null $earned_units    Earned payout, null when unknown.
	 * @param int      $unknown_rows    Ledger rows in the window whose payout is unknown.
	 * @param int      $completed_units Completed payouts.
	 * @param int      $reserved_units  Pending payouts.
	 */
	public function __construct(
		public string $currency,
		public ?int $earned_units,
		public int $unknown_rows,
		public int $completed_units,
		public int $reserved_units
	) {
	}

	/**
	 * `complete` or `unknown`.
	 */
	public function status(): string {
		return null === $this->earned_units ? self::UNKNOWN : self::COMPLETE;
	}

	/**
	 * Earned − completed − reserved, or null when unknown.
	 */
	public function balance_units(): ?int {
		return null === $this->earned_units ? null : $this->earned_units - $this->completed_units - $this->reserved_units;
	}

	/**
	 * What can be requested: the balance when positive, else 0; null when unknown.
	 */
	public function available_units(): ?int {
		$balance = $this->balance_units();

		return null === $balance ? null : max( 0, $balance );
	}

	/**
	 * The negative balance as a positive amount, else 0; null when unknown.
	 */
	public function outstanding_units(): ?int {
		$balance = $this->balance_units();

		return null === $balance ? null : max( 0, -$balance );
	}
}
