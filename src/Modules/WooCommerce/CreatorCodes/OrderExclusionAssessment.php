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
 * An order that gets no new ledger rows because it is excluded (ADR-068):
 * its decision (stored earlier or now) and, for a decision made now, the
 * creators whose existing rows of the order it takes out, whose cached
 * statistics must be invalidated after COMMIT.
 */
final readonly class OrderExclusionAssessment {

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<int> $invalidate
	 *
	 * @param OrderExclusion $exclusion  The order's decision.
	 * @param bool           $decided    Whether it was decided in this synchronization.
	 * @param int[]          $invalidate Creators whose cached statistics change.
	 */
	public function __construct( public OrderExclusion $exclusion, public bool $decided, public array $invalidate = array() ) {
	}
}
