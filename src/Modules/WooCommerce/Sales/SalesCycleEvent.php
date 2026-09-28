<?php
/**
 * One sales cycle event.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * A row of `merchboard_sales_cycles` (ADR-038 pkt. 14), either to be written
 * (`id` and `created_at` null) or as stored. (order, cycle, event) is unique.
 */
final readonly class SalesCycleEvent {

	/**
	 * Constructor.
	 *
	 * @param int                    $order_id    WooCommerce order.
	 * @param int                    $cycle       Cycle number (≥ 1).
	 * @param SalesCycleEventType    $event       Open or close.
	 * @param DateTimeImmutable      $occurred_at When it happened (stored as UTC).
	 * @param int|null               $id          Row id; null before insert.
	 * @param DateTimeImmutable|null $created_at  Insert time (UTC); null before insert.
	 * @throws InvalidArgumentException When an id or the cycle is not positive.
	 */
	public function __construct(
		public int $order_id,
		public int $cycle,
		public SalesCycleEventType $event,
		public DateTimeImmutable $occurred_at,
		public ?int $id = null,
		public ?DateTimeImmutable $created_at = null
	) {
		if ( $order_id < 1 || $cycle < 1 || ( null !== $id && $id < 1 ) ) {
			throw new InvalidArgumentException( 'Sales cycle ids and cycle must be positive integers.' );
		}
	}
}
