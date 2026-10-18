<?php
/**
 * WooCommerce hooks of purchase alerts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * An order is paid when it reaches `processing` or `completed`, as in
 * MerchAlert (ADR-065 pkt. 2). Both hooks fire from WooCommerce' status
 * change for HPOS and legacy storage alike; an order that goes from
 * processing to completed reaches the router twice, and the unique key
 * keeps one alert per line and creator. The handler only maps the id; the
 * router never throws.
 */
final class AlertHooks {

	/**
	 * Constructor.
	 *
	 * @param AlertRouter $router Router.
	 */
	public function __construct( private AlertRouter $router ) {
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_processing', array( $this, 'order_paid' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'order_paid' ), 10, 1 );
	}

	/**
	 * `woocommerce_order_status_processing` and `woocommerce_order_status_completed`.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function order_paid( mixed $order_id ): void {
		if ( is_numeric( $order_id ) ) {
			$this->router->order_paid( (int) $order_id );
		}
	}
}
