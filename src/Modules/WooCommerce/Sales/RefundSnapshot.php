<?php
/**
 * One refund as the sales planner sees it.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * A WooCommerce refund with its product lines. A refund without product lines
 * (amount only, shipping, fees) has no lines and never reaches the ledger
 * (ADR-037).
 */
final readonly class RefundSnapshot {

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<RefundLineSnapshot> $lines
	 *
	 * @param int                  $refund_id    Refund id.
	 * @param DateTimeImmutable    $date_created When the refund was created (its `occurred_at`).
	 * @param RefundLineSnapshot[] $lines        Product lines.
	 * @throws InvalidArgumentException When the id is not positive or two lines refund the same order line.
	 */
	public function __construct( public int $refund_id, public DateTimeImmutable $date_created, public array $lines ) {
		if ( $refund_id < 1 ) {
			throw new InvalidArgumentException( 'Invalid refund snapshot.' );
		}

		$items = array();

		foreach ( $lines as $line ) {
			if ( 0 !== $line->refunded_item_id && isset( $items[ $line->refunded_item_id ] ) ) {
				throw new InvalidArgumentException( 'A refund refunds an order line twice.' );
			}

			$items[ $line->refunded_item_id ] = true;
		}
	}
}
