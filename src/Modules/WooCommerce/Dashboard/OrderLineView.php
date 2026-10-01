<?php
/**
 * One order line in the creator dashboard.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Dashboard;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * The fields of an order line that `GET /me/orders` may show (ADR-039 P2,
 * P3, P4, P5, P6): no customer data, no amounts and no order, product,
 * variation or creator ids. `line_id` is the order item id, the line's key.
 */
final readonly class OrderLineView {

	/**
	 * Constructor.
	 *
	 * @param int               $line_id      Order item id.
	 * @param string            $order_number WooCommerce order number, or the order id for a deleted order.
	 * @param DateTimeImmutable $date         Date of the line's first sale row (UTC).
	 * @param string            $status       WooCommerce status, `trashed` or `deleted`.
	 * @param string            $status_label Status label, plain text.
	 * @param string            $product_name Current product name, or the ledger snapshot, plain text.
	 * @param int               $quantity     Ledger balance of the line.
	 * @param bool              $refunded     A refund still applies.
	 * @param bool              $counted      The order counts now.
	 */
	public function __construct(
		public int $line_id,
		public string $order_number,
		public DateTimeImmutable $date,
		public string $status,
		public string $status_label,
		public string $product_name,
		public int $quantity,
		public bool $refunded,
		public bool $counted
	) {
	}

	/**
	 * The REST representation; the date in ISO 8601 UTC.
	 *
	 * @return array{line_id: int, order_number: string, date: string, status: string, status_label: string, product_name: string, quantity: int, refunded: bool, counted: bool}
	 */
	public function to_array(): array {
		return array(
			'line_id'      => $this->line_id,
			'order_number' => $this->order_number,
			'date'         => $this->date->format( 'Y-m-d\TH:i:s\Z' ),
			'status'       => $this->status,
			'status_label' => $this->status_label,
			'product_name' => $this->product_name,
			'quantity'     => $this->quantity,
			'refunded'     => $this->refunded,
			'counted'      => $this->counted,
		);
	}
}
