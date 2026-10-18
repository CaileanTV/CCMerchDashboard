<?php
/**
 * A paid order, as alerts see it.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * The order data alerts use: the order number, the customer's first name
 * and voluntary username (ADR-065 pkt. 9), and the product lines.
 */
final readonly class PaidOrder {

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<PaidOrderLine> $lines
	 *
	 * @param int             $order_id     Order id.
	 * @param string          $order_number Order number as shown to the customer.
	 * @param string          $first_name   Billing first name, '' when none.
	 * @param string          $username     Voluntary username from checkout, '' when none.
	 * @param PaidOrderLine[] $lines        Product lines with a product and a positive quantity.
	 */
	public function __construct(
		public int $order_id,
		public string $order_number,
		public string $first_name,
		public string $username,
		public array $lines
	) {
	}
}
