<?php
/**
 * Outcome of assessing an order against the creator codes.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

defined( 'ABSPATH' ) || exit;

/**
 * The exclusions of an order (ADR-068): every decision, stored earlier or
 * now, whose creator's lines get no new ledger rows, and, for decisions made
 * now, the creators whose existing rows of the order they take out, whose
 * cached statistics must be invalidated after COMMIT.
 */
final readonly class OrderExclusionAssessment {

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<OrderExclusion> $exclusions
	 * @phpstan-param list<int> $invalidate
	 *
	 * @param OrderExclusion[] $exclusions The order's decisions (at least one).
	 * @param int[]            $invalidate Creators whose cached statistics change.
	 */
	public function __construct( public array $exclusions, public array $invalidate = array() ) {
	}

	/**
	 * The creators whose lines of the order are excluded, ascending.
	 *
	 * @return list<int>
	 */
	public function creator_ids(): array {
		$ids = array_values( array_unique( array_map( static fn( OrderExclusion $exclusion ): int => $exclusion->creator_id, $this->exclusions ) ) );
		sort( $ids );

		return $ids;
	}
}
