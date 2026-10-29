<?php
/**
 * Which of the company's campaigns cover an order's designer lines.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\WooCommerce\Sales\OrderSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * The company's own campaigns (ADR-080 pkt. 9), implemented by the
 * ProductDesigner module, so the WooCommerce module does not depend on it.
 * Asked once per synchronization, under the order's lock; the ledger stores
 * the answer with a line's first designer row, and every later row of that
 * line keeps it.
 */
interface DesignerCampaignSource {

	/**
	 * The campaign that covers each designer line of the order; lines that no
	 * campaign covers, and every legacy line, are left out.
	 *
	 * @param OrderSnapshot $order Order as read now.
	 * @return array<int, int> Campaign id by order item.
	 * @throws DatabaseException When the campaigns or snapshots cannot be read.
	 * @throws InvalidArgumentException When the order's coupon data is unknown (never read as "no coupon").
	 */
	public function campaigns( OrderSnapshot $order ): array;
}
