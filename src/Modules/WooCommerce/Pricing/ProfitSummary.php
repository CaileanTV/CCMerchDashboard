<?php
/**
 * Realized creator profit of a group of ledger rows.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

defined( 'ABSPATH' ) || exit;

/**
 * The profit of a group of ledger rows in one currency (ADR-050): the sum of
 * the rows whose profit is known (RealizedProfit, ADR-049) and how many rows
 * are known and unknown. The sum is only an answer when every row is known:
 *
 * - `complete`: no row is unknown; the amount is the group's profit;
 * - `incomplete`: some rows are known, some unknown; no amount is given,
 *   because the known part is not a lower or upper bound (refunds and
 *   reversals are negative);
 * - `unknown`: no row is known; no amount.
 *
 * An unknown profit is never 0. A group without rows does not exist: the
 * caller has no summary then ("no sales").
 */
final readonly class ProfitSummary {

	public const COMPLETE = 'complete';

	public const INCOMPLETE = 'incomplete';

	public const UNKNOWN = 'unknown';

	/**
	 * Constructor.
	 *
	 * @param string $currency     Currency of the rows.
	 * @param int    $known_units  Sum of the known rows' profit in units (1/10 000).
	 * @param int    $known_rows   Rows with a known profit.
	 * @param int    $unknown_rows Rows with an unknown profit.
	 * @throws InvalidArgumentException When the counts are invalid or the group is empty.
	 */
	public function __construct(
		public string $currency,
		public int $known_units,
		public int $known_rows,
		public int $unknown_rows
	) {
		Currency::assert_valid( $currency );

		if ( $known_rows < 0 || $unknown_rows < 0 || 0 === $known_rows + $unknown_rows ) {
			throw new InvalidArgumentException( 'A profit summary needs at least one row.' );
		}

		if ( 0 === $known_rows && 0 !== $known_units ) {
			throw new InvalidArgumentException( 'Without known rows there is no known profit.' );
		}
	}

	/**
	 * `complete`, `incomplete` or `unknown`.
	 */
	public function status(): string {
		if ( 0 === $this->unknown_rows ) {
			return self::COMPLETE;
		}

		return 0 === $this->known_rows ? self::UNKNOWN : self::INCOMPLETE;
	}

	/**
	 * The profit in units, only when complete.
	 */
	public function amount_units(): ?int {
		return self::COMPLETE === $this->status() ? $this->known_units : null;
	}

	/**
	 * This group and another of the same currency together.
	 *
	 * @param ProfitSummary $other Other group.
	 * @throws InvalidArgumentException When the currencies differ (never summed across currencies).
	 */
	public function plus( ProfitSummary $other ): self {
		if ( $other->currency !== $this->currency ) {
			throw new InvalidArgumentException( 'Profits of different currencies are never added.' );
		}

		return new self(
			$this->currency,
			SalesAmount::add( $this->known_units, $other->known_units ),
			$this->known_rows + $other->known_rows,
			$this->unknown_rows + $other->unknown_rows
		);
	}

	/**
	 * JSON schema of to_array(), shared by the creator endpoints.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'type'       => 'object',
			'readonly'   => true,
			'properties' => array(
				'currency' => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'status'   => array(
					'type'     => 'string',
					'enum'     => array( self::COMPLETE, self::INCOMPLETE, self::UNKNOWN ),
					'readonly' => true,
				),
				'amount'   => array(
					'type'     => array( 'string', 'null' ),
					'readonly' => true,
				),
			),
		);
	}

	/**
	 * The API representation: the amount as a decimal string with four
	 * decimals, or null unless complete.
	 *
	 * @return array{currency: string, status: string, amount: string|null}
	 */
	public function to_array(): array {
		$amount = $this->amount_units();

		return array(
			'currency' => $this->currency,
			'status'   => $this->status(),
			'amount'   => null === $amount ? null : SalesAmount::format( $amount ),
		);
	}
}
