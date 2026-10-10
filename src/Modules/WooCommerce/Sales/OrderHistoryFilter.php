<?php
/**
 * Filter of the admin order history.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Pricing\ProfitSummary;

defined( 'ABSPATH' ) || exit;

/**
 * Optional order id, creator and profit status of the admin order history
 * (ADR-051). Validated values only; null means "no filter".
 */
final readonly class OrderHistoryFilter {

	/**
	 * Orders with at least one row with profit data (complete or incomplete):
	 * the page's default, which hides orders from before the profit data was
	 * stored without deleting anything.
	 */
	public const WITH_PROFIT_DATA = 'with_data';

	/**
	 * Profit statuses that can be filtered.
	 */
	public const STATUSES = array( self::WITH_PROFIT_DATA, ProfitSummary::COMPLETE, ProfitSummary::INCOMPLETE, ProfitSummary::UNKNOWN );

	/**
	 * Constructor.
	 *
	 * @param int|null    $order_id   Order id.
	 * @param int|null    $creator_id Creator id: only that creator's ledger rows.
	 * @param string|null $status     Profit status of the order (over the shown rows).
	 * @throws InvalidArgumentException When a value is invalid.
	 */
	public function __construct(
		public ?int $order_id = null,
		public ?int $creator_id = null,
		public ?string $status = null
	) {
		if ( ( null !== $order_id && $order_id < 1 ) || ( null !== $creator_id && $creator_id < 1 ) ) {
			throw new InvalidArgumentException( 'Ids must be positive.' );
		}

		if ( null !== $status && ! in_array( $status, self::STATUSES, true ) ) {
			throw new InvalidArgumentException( 'Unknown profit status.' );
		}
	}
}
