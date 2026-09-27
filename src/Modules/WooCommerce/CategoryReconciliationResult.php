<?php
/**
 * Counts of a category reconciliation run.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable counts per ReconciliationOutcome.
 */
final class CategoryReconciliationResult {

	/**
	 * Constructor.
	 *
	 * @param array<string, int> $counts Count per outcome value.
	 */
	private function __construct( private readonly array $counts ) {
	}

	/**
	 * A result with every count at zero.
	 */
	public static function empty(): self {
		return new self( array_fill_keys( array_map( static fn( ReconciliationOutcome $o ): string => $o->value, ReconciliationOutcome::cases() ), 0 ) );
	}

	/**
	 * Rebuilds counts from to_array() (job payloads): every outcome must be
	 * present as a non-negative integer, nothing else.
	 *
	 * @param array<mixed> $counts Counts.
	 * @throws InvalidArgumentException When the data is not a result.
	 */
	public static function from_array( array $counts ): self {
		$result = self::empty()->counts;

		if ( array_keys( $counts ) !== array_keys( $result ) ) {
			throw new InvalidArgumentException( 'Invalid reconciliation counts.' );
		}

		foreach ( $counts as $key => $count ) {
			if ( ! is_int( $count ) || $count < 0 ) {
				throw new InvalidArgumentException( 'Invalid reconciliation counts.' );
			}

			$result[ $key ] = $count;
		}

		return new self( $result );
	}

	/**
	 * A new result with one more product of this outcome.
	 *
	 * @param ReconciliationOutcome $outcome Outcome.
	 */
	public function with( ReconciliationOutcome $outcome ): self {
		$counts                    = $this->counts;
		$counts[ $outcome->value ] = ( $counts[ $outcome->value ] ?? 0 ) + 1;

		return new self( $counts );
	}

	/**
	 * Products with this outcome.
	 *
	 * @param ReconciliationOutcome $outcome Outcome.
	 */
	public function count( ReconciliationOutcome $outcome ): int {
		return $this->counts[ $outcome->value ] ?? 0;
	}

	/**
	 * All counts: linked, moved, unlinked, unchanged, skipped_manual,
	 * ambiguous, skipped_inactive, errors.
	 *
	 * @return array<string, int>
	 */
	public function to_array(): array {
		return $this->counts;
	}

	/**
	 * Products looked at.
	 */
	public function total(): int {
		return array_sum( $this->counts );
	}
}
