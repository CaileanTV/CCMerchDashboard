<?php
/**
 * The sales ledger failure marker as WooCommerce order meta.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;
use DateTimeZone;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Stores `_merchboard_ledger_failure` through WooCommerce CRUD (ADR-038
 * pkt. 20, B7): `{v: 1, first_at, last_at, code, count}`, times as UTC
 * ISO 8601. The first failure sets `first_at` = `last_at` and `count` = 1; a
 * later one keeps `first_at`, updates `last_at` and `code` and increments
 * `count`. Success removes the meta.
 *
 * Only the meta is saved (`save_meta_data()`), never the whole order. Under
 * HPOS, WooCommerce would still save the order after a meta change, which
 * fires `woocommerce_after_order_object_save` again; like WooCommerce itself,
 * the save is wrapped in `woocommerce_orders_table_datastore_should_save_after_meta_change`
 * → false, removed in `finally`.
 */
final class WooCommerceSalesFailureMarker implements SalesFailureMarker {

	/**
	 * Order meta key (also listed for opt-in uninstall in Uninstaller).
	 */
	public const META_KEY = '_merchboard_ledger_failure';

	/**
	 * Format version.
	 */
	public const VERSION = 1;

	/**
	 * WooCommerce' HPOS filter deciding whether the order is saved after a meta change.
	 */
	public const SAVE_FILTER = 'woocommerce_orders_table_datastore_should_save_after_meta_change';

	/**
	 * Sets or updates the marker.
	 *
	 * @param int               $order_id Order id.
	 * @param SalesFailureCode  $code     Failure code.
	 * @param DateTimeImmutable $at       When it failed.
	 */
	public function record( int $order_id, SalesFailureCode $code, DateTimeImmutable $at ): void {
		$order = $this->order( $order_id );

		if ( null === $order ) {
			return;
		}

		$now     = $at->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
		$current = $order->get_meta( self::META_KEY, true, 'edit' );
		$keep    = is_array( $current )
			&& self::VERSION === ( $current['v'] ?? null )
			&& is_string( $current['first_at'] ?? null )
			&& is_int( $current['count'] ?? null )
			&& $current['count'] > 0;

		$order->update_meta_data(
			self::META_KEY,
			array(
				'v'        => self::VERSION,
				'first_at' => $keep ? $current['first_at'] : $now,
				'last_at'  => $now,
				'code'     => $code->value,
				'count'    => $keep ? $current['count'] + 1 : 1,
			)
		);
		$this->save( $order );
	}

	/**
	 * Removes the marker, if there is one.
	 *
	 * @param int $order_id Order id.
	 */
	public function clear( int $order_id ): void {
		$order = $this->order( $order_id );

		if ( null === $order || in_array( $order->get_meta( self::META_KEY, true, 'edit' ), array( '', null, false ), true ) ) {
			return;
		}

		$order->delete_meta_data( self::META_KEY );
		$this->save( $order );
	}

	/**
	 * Saves the meta only, without letting HPOS save the whole order.
	 *
	 * @param WC_Order $order Order.
	 */
	private function save( WC_Order $order ): void {
		add_filter( self::SAVE_FILTER, '__return_false' );

		try {
			$order->save_meta_data();
		} finally {
			remove_filter( self::SAVE_FILTER, '__return_false' );
		}
	}

	/**
	 * The order, if it is a `shop_order`.
	 *
	 * @param int $order_id Order id.
	 */
	private function order( int $order_id ): ?WC_Order {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

		return $order instanceof WC_Order && 'shop_order' === $order->get_type() ? $order : null;
	}
}
