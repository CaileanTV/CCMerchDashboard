<?php
/**
 * The stored decision for one order and one creator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\CreatorCodes;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * A row of `merchboard_order_exclusions` (ADR-068): the decision that the
 * order used the creator's own discount code, so that creator's lines of the
 * order do not count (other creators' lines count normally). It holds the
 * registration whose code the order used, its code, whether the coupon
 * deviated from the registration at the decision, and the order's
 * WooCommerce creation time (the time the registration's period was checked
 * against).
 */
final readonly class OrderExclusion {

	/**
	 * Reason of every decision so far.
	 */
	public const REASON_CREATOR_CODE = 'creator_code';

	/**
	 * Constructor.
	 *
	 * @param int                  $order_id         WooCommerce order.
	 * @param OrderExclusionStatus $status           Decision.
	 * @param int                  $code_id          Registration.
	 * @param int                  $creator_id       The code's creator, whose lines are excluded.
	 * @param string               $code             Normalized code.
	 * @param bool                 $coupon_deviation Whether the coupon deviated from the registration at the decision.
	 * @param DateTimeImmutable    $order_created_at Order's WooCommerce creation (UTC).
	 * @param DateTimeImmutable    $assessed_at      Decision time (UTC).
	 */
	public function __construct(
		public int $order_id,
		public OrderExclusionStatus $status,
		public int $code_id,
		public int $creator_id,
		public string $code,
		public bool $coupon_deviation,
		public DateTimeImmutable $order_created_at,
		public DateTimeImmutable $assessed_at
	) {
	}
}
