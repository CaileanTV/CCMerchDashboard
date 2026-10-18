<?php
/**
 * One purchase alert.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * A row of `merchboard_alert_events` (ADR-065): one paid order line for one
 * creator, with what the alert shows as it was when the order was paid.
 */
final readonly class AlertEvent {

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<int> $category_ids
	 *
	 * @param int               $creator_id          Creator.
	 * @param int               $order_id            Order.
	 * @param string            $order_number        Order number as shown to the customer.
	 * @param int               $order_item_id       Order line.
	 * @param int               $product_id          Parent product.
	 * @param int               $variation_id        Variation, 0 for none.
	 * @param string            $product_name        Line name as sold.
	 * @param string            $variation           The variation's attributes, '' for none.
	 * @param int               $quantity            Quantity.
	 * @param int               $image_id            Image attachment, 0 for none.
	 * @param int[]             $category_ids        The product's direct `product_cat` terms.
	 * @param string            $customer_first_name Billing first name, '' for none.
	 * @param string            $customer_username   Voluntary username, '' for none.
	 * @param DateTimeImmutable $created_at          When the alert was recorded (UTC).
	 * @param int               $id                  Row id, 0 before it is stored.
	 */
	public function __construct(
		public int $creator_id,
		public int $order_id,
		public string $order_number,
		public int $order_item_id,
		public int $product_id,
		public int $variation_id,
		public string $product_name,
		public string $variation,
		public int $quantity,
		public int $image_id,
		public array $category_ids,
		public string $customer_first_name,
		public string $customer_username,
		public DateTimeImmutable $created_at,
		public int $id = 0
	) {
	}
}
