<?php
/**
 * An order as the sales planner sees it.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Normalized WooCommerce order data, the planner's only view of WooCommerce
 * (ADR-038). The reader of ROADMAP trin 5 builds it from CRUD data.
 *
 * - `is_counted`: whether the status counts (the list of statuses belongs to
 *   the caller, not to the planner).
 * - `deleted_refund_id`: a refund a delete hook reported as deleted. The
 *   planner treats it as absent even when WooCommerce' caches still list it
 *   (ADR-038, B6).
 * - A deleted order (deleted()) has no WooCommerce data; the planner then
 *   works from the ledger alone (ADR-038 pkt. 21).
 * - `coupon_codes`: the order's coupon codes, normalized (CouponCode), for
 *   the creator code exclusion (ADR-068); not used by the planner. Null when
 *   not read, which the exclusion treats as unknown, never as "no codes".
 */
final readonly class OrderSnapshot {

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<OrderLineSnapshot> $lines
	 * @phpstan-param list<RefundSnapshot> $refunds
	 * @phpstan-param list<string>|null $coupon_codes
	 *
	 * @param int                    $order_id          Order id.
	 * @param bool                   $exists            False for a permanently deleted order.
	 * @param bool                   $is_counted        Whether the status counts.
	 * @param string                 $currency          ISO 4217 code ('' only for a deleted order).
	 * @param DateTimeImmutable|null $date_created      Order creation (required for an existing order).
	 * @param DateTimeImmutable|null $date_paid         Payment date, if any.
	 * @param OrderLineSnapshot[]    $lines             Product lines.
	 * @param RefundSnapshot[]       $refunds           Refunds.
	 * @param int|null               $deleted_refund_id Refund known to be deleted, or null.
	 * @param string[]|null          $coupon_codes      Normalized coupon codes, null when not read.
	 * @throws InvalidArgumentException When the data is inconsistent.
	 */
	public function __construct(
		public int $order_id,
		public bool $exists,
		public bool $is_counted,
		public string $currency,
		public ?DateTimeImmutable $date_created,
		public ?DateTimeImmutable $date_paid,
		public array $lines,
		public array $refunds,
		public ?int $deleted_refund_id = null,
		public ?array $coupon_codes = null
	) {
		if ( $order_id < 1 || ( null !== $deleted_refund_id && $deleted_refund_id < 1 ) ) {
			throw new InvalidArgumentException( 'Invalid order snapshot id.' );
		}

		if ( $exists && ( 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) || null === $date_created ) ) {
			throw new InvalidArgumentException( 'An existing order needs a currency and a creation date.' );
		}

		if ( ! $exists && ( $is_counted || array() !== $lines || array() !== $refunds ) ) {
			throw new InvalidArgumentException( 'A deleted order has no status, lines or refunds.' );
		}

		$items = array_map( static fn( OrderLineSnapshot $line ): int => $line->item_id, $lines );
		$ids   = array_map( static fn( RefundSnapshot $refund ): int => $refund->refund_id, $refunds );

		if ( count( $items ) !== count( array_unique( $items ) ) || count( $ids ) !== count( array_unique( $ids ) ) ) {
			throw new InvalidArgumentException( 'Duplicate order line or refund.' );
		}
	}

	/**
	 * A permanently deleted order: the planner closes its cycle from the ledger alone.
	 *
	 * @param int $order_id Order id.
	 */
	public static function deleted( int $order_id ): self {
		return new self( $order_id, false, false, '', null, null, array(), array() );
	}

	/**
	 * Lines by item id, ascending (independent of WooCommerce' order).
	 *
	 * @return array<int, OrderLineSnapshot>
	 */
	public function lines_by_item(): array {
		$lines = array();

		foreach ( $this->lines as $line ) {
			$lines[ $line->item_id ] = $line;
		}

		ksort( $lines );

		return $lines;
	}

	/**
	 * Refunds that exist, by refund id ascending; the known deleted refund is left out.
	 *
	 * @return array<int, RefundSnapshot>
	 */
	public function current_refunds(): array {
		$refunds = array();

		foreach ( $this->refunds as $refund ) {
			if ( $refund->refund_id !== $this->deleted_refund_id ) {
				$refunds[ $refund->refund_id ] = $refund;
			}
		}

		ksort( $refunds );

		return $refunds;
	}
}
