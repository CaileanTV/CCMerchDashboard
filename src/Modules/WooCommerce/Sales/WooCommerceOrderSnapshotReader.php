<?php
/**
 * Reads WooCommerce orders for the sales planner.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CouponCode;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use OverflowException;
use WC_DateTime;
use WC_Order;
use WC_Order_Item_Coupon;
use WC_Order_Item_Product;
use WC_Order_Refund;

defined( 'ABSPATH' ) || exit;

/**
 * Builds an OrderSnapshot from WooCommerce CRUD only (no SQL against
 * WooCommerce tables), for HPOS and legacy storage alike.
 *
 * - Values are read with the `edit` context, so filters cannot change them;
 *   amounts and quantities go through WooCommerceAmount (ADR-038, Q6).
 * - A line's creator is the product's current link from ProductCreatorLink;
 *   the planner decides whether it matters (ADR-038, B2/B3). For a variation
 *   the line's product id is its parent.
 * - Refunds are queried with `wc_get_orders()`, not `$order->get_refunds()`,
 *   whose list cache can still hold a deleted refund (ADR-038, B6).
 * - Dates become UTC DateTimeImmutable from their Unix timestamp.
 * - Tax (ADR-048): the line's and the refund line's own tax
 *   (`get_total_tax( 'edit' )`, after discounts), never a rate or a
 *   recalculation; a value that is not a plain decimal is unknown (null).
 */
final class WooCommerceOrderSnapshotReader implements OrderSnapshotReader {

	/**
	 * Refund line meta pointing to the refunded order line.
	 */
	public const REFUNDED_ITEM_META = '_refunded_item_id';

	/**
	 * Constructor.
	 *
	 * @param ProductCreatorLink   $links    Product → creator relation.
	 * @param CountedOrderStatuses $statuses Counted statuses.
	 */
	public function __construct( private ProductCreatorLink $links, private CountedOrderStatuses $statuses ) {
	}

	/**
	 * Whether the order counts; null when it is not synchronizable.
	 *
	 * @param int $order_id Order id.
	 */
	public function counts( int $order_id ): ?bool {
		$order = $this->order( $order_id );

		return null === $order ? null : $this->statuses->counts( (string) $order->get_status( 'edit' ) );
	}

	/**
	 * The order as the planner sees it.
	 *
	 * @param int      $order_id          Order id.
	 * @param int|null $deleted_refund_id Refund known to be deleted.
	 * @throws InvalidArgumentException When a value cannot be converted deterministically.
	 */
	public function read( int $order_id, ?int $deleted_refund_id = null ): ?OrderSnapshot {
		$order = $this->order( $order_id );

		if ( null === $order ) {
			return null;
		}

		$lines = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$lines[] = $this->line( $item );
			}
		}

		$date_created = $order->get_date_created( 'edit' );
		$date_paid    = $order->get_date_paid( 'edit' );

		return new OrderSnapshot(
			$order_id,
			true,
			$this->statuses->counts( (string) $order->get_status( 'edit' ) ),
			(string) $order->get_currency( 'edit' ),
			$date_created instanceof WC_DateTime ? self::utc( $date_created ) : null,
			$date_paid instanceof WC_DateTime ? self::utc( $date_paid ) : null,
			$lines,
			array_map( array( $this, 'refund' ), $this->refunds( $order_id ) ),
			null !== $deleted_refund_id && $deleted_refund_id > 0 ? $deleted_refund_id : null,
			self::coupon_codes( $order )
		);
	}

	/**
	 * The order's coupon codes, normalized and unique (ADR-068). A coupon
	 * item that is not a WC_Order_Item_Coupon is unknown data: the snapshot
	 * fails rather than leave a code out.
	 *
	 * @param WC_Order $order Order.
	 * @return list<string>
	 * @throws InvalidArgumentException When a coupon item cannot be read.
	 */
	private static function coupon_codes( WC_Order $order ): array {
		$codes = array();

		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Coupon ) {
				throw new InvalidArgumentException( 'Unreadable coupon item.' );
			}

			$code = CouponCode::normalize( (string) $item->get_code( 'edit' ) );

			if ( '' !== $code ) {
				$codes[ $code ] = $code;
			}
		}

		return array_values( $codes );
	}

	/**
	 * A synchronizable order: a `shop_order` that is not in the trash.
	 *
	 * @param int $order_id Order id.
	 */
	private function order( int $order_id ): ?WC_Order {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() || 'trash' === $order->get_status( 'edit' ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * An order line.
	 *
	 * @param WC_Order_Item_Product $item Line.
	 * @throws InvalidArgumentException When the quantity or amount is not supported.
	 */
	private function line( WC_Order_Item_Product $item ): OrderLineSnapshot {
		$product_id = (int) $item->get_product_id( 'edit' );

		return new OrderLineSnapshot(
			(int) $item->get_id(),
			$product_id,
			(int) $item->get_variation_id( 'edit' ),
			(string) $item->get_name( 'edit' ),
			WooCommerceAmount::quantity( $item->get_quantity( 'edit' ) ),
			WooCommerceAmount::units( $item->get_total( 'edit' ) ),
			$product_id > 0 ? $this->links->creator_id( $product_id ) : null,
			self::tax( $item ),
			self::subtotal( $item )
		);
	}

	/**
	 * The order's refunds, straight from the query (no refund list cache).
	 *
	 * @param int $order_id Order id.
	 * @return list<WC_Order_Refund>
	 */
	private function refunds( int $order_id ): array {
		$found = wc_get_orders(
			array(
				'type'   => 'shop_order_refund',
				'parent' => $order_id,
				'limit'  => -1,
			)
		);

		return array_values( array_filter( is_array( $found ) ? $found : array(), static fn( mixed $refund ): bool => $refund instanceof WC_Order_Refund ) );
	}

	/**
	 * A refund with its product lines.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 * @throws InvalidArgumentException When the refund has no creation date or a value is not supported.
	 */
	private function refund( WC_Order_Refund $refund ): RefundSnapshot {
		$date = $refund->get_date_created( 'edit' );

		if ( ! $date instanceof WC_DateTime ) {
			throw new InvalidArgumentException( 'Refund without a creation date.' );
		}

		$lines = array();

		foreach ( $refund->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$refunded = $item->get_meta( self::REFUNDED_ITEM_META, true, 'edit' );
			$lines[]  = new RefundLineSnapshot(
				// A missing link becomes 0: the planner reports unresolved_refund_item.
				is_numeric( $refunded ) && (int) $refunded > 0 ? (int) $refunded : 0,
				WooCommerceAmount::quantity( $item->get_quantity( 'edit' ) ),
				WooCommerceAmount::units( $item->get_total( 'edit' ) ),
				self::tax( $item )
			);
		}

		return new RefundSnapshot( (int) $refund->get_id(), self::utc( $date ), $lines );
	}

	/**
	 * A line's actual tax in units, or null when WooCommerce' value is not a
	 * plain decimal (unknown, never guessed).
	 *
	 * @param WC_Order_Item_Product $item Line.
	 */
	private static function tax( WC_Order_Item_Product $item ): ?int {
		// WooCommerce stores a decimal string; anything else (e.g. a float) is unknown.
		$tax = $item->get_total_tax( 'edit' );

		try {
			return WooCommerceAmount::units( '' === $tax ? '0' : $tax );
		} catch ( InvalidArgumentException ) {
			return null;
		}
	}

	/**
	 * The line's subtotal before coupons incl. tax in 1/10000 units, or null
	 * when WooCommerce' values are not decimal strings.
	 *
	 * @param WC_Order_Item_Product $item Line.
	 */
	private static function subtotal( WC_Order_Item_Product $item ): ?int {
		$net = $item->get_subtotal( 'edit' );
		$tax = $item->get_subtotal_tax( 'edit' );

		try {
			return SalesAmount::add( WooCommerceAmount::units( '' === $net ? '0' : $net ), WooCommerceAmount::units( '' === $tax ? '0' : $tax ) );
		} catch ( InvalidArgumentException | OverflowException ) {
			return null;
		}
	}

	/**
	 * A WooCommerce date as UTC.
	 *
	 * @param WC_DateTime $date Date.
	 */
	private static function utc( WC_DateTime $date ): DateTimeImmutable {
		return ( new DateTimeImmutable( '@' . $date->getTimestamp() ) )->setTimezone( new DateTimeZone( 'UTC' ) );
	}
}
