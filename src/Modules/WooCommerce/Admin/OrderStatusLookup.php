<?php
/**
 * Current WooCommerce state of ledger orders for the admin order history.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Order number, status label and edit link through WooCommerce CRUD
 * (`wc_get_order()`), so HPOS and legacy storage both work and no
 * WooCommerce table is queried directly. No customer data is read. A missing
 * order is reported as deleted; the ledger keeps its history (ADR-037 pkt. 8).
 */
class OrderStatusLookup {

	/**
	 * The order's current state.
	 *
	 * @param int $order_id Order id.
	 * @return array{exists: bool, number: string, status: string, edit_url: string|null}
	 */
	public function find( int $order_id ): array {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			return array(
				'exists'   => false,
				'number'   => (string) $order_id,
				'status'   => __( 'Deleted in WooCommerce', 'merchboard' ),
				'edit_url' => null,
			);
		}

		$status = (string) $order->get_status( 'edit' );

		return array(
			'exists'   => true,
			'number'   => (string) $order->get_order_number(),
			'status'   => 'trash' === $status ? __( 'Trash', 'merchboard' ) : wp_strip_all_tags( (string) wc_get_order_status_name( $status ) ),
			'edit_url' => $order->get_edit_order_url(),
		);
	}

	/**
	 * Whether Merch Dashboard's history of the order may be deleted (ADR-052):
	 * `trash` when the WooCommerce order is in the trash, `deleted` when it no
	 * longer exists, null otherwise. The synchronization skips both states
	 * (ADR-038 Q8), so an erased history is not registered again unless the
	 * order is restored from the trash.
	 *
	 * @param int $order_id Order id.
	 * @return 'trash'|'deleted'|null
	 */
	public function erasable_state( int $order_id ): ?string {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			return 'deleted';
		}

		return 'trash' === $order->get_status( 'edit' ) ? 'trash' : null;
	}
}
