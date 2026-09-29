<?php
/**
 * WooCommerce hooks of the sales ledger.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use WC_Order;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Turns WooCommerce and WordPress hooks into synchronizations (ADR-038
 * pkt. 21, B6 and Q1). Every handler only maps ids; the synchronizer never
 * throws, so no hook flow is interrupted.
 *
 * - `woocommerce_after_order_object_save`: the common trigger, only for
 *   `shop_order` and never for the trash (refund objects are skipped: the
 *   parent order is saved after a refund).
 * - Order deletion: `woocommerce_before_delete_order` and `before_delete_post`
 *   (priority 5, before WooCommerce deletes the lines at 10) for `shop_order`;
 *   both close from the ledger alone, the second call is a no-op.
 * - Refund deletion: `deleted_post` for `shop_order_refund` (legacy and HPOS
 *   with synchronization) and `woocommerce_refund_deleted` (WooCommerce admin,
 *   also HPOS without synchronization), both with the deleted refund id.
 *   `woocommerce_delete_order_refund` is not used (ADR-038, Q1).
 */
final class SalesLedgerHooks {

	/**
	 * Priority of `before_delete_post`: before WooCommerce deletes the order lines (10).
	 */
	public const DELETE_POST_PRIORITY = 5;

	/**
	 * Constructor.
	 *
	 * @param SalesSync $synchronizer Synchronization.
	 */
	public function __construct( private SalesSync $synchronizer ) {
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_after_order_object_save', array( $this, 'order_saved' ), 10, 1 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'order_deleting' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'post_deleting' ), self::DELETE_POST_PRIORITY, 2 );
		add_action( 'deleted_post', array( $this, 'post_deleted' ), 10, 2 );
		add_action( 'woocommerce_refund_deleted', array( $this, 'refund_deleted' ), 10, 2 );
	}

	/**
	 * `woocommerce_after_order_object_save`.
	 *
	 * @param mixed $order Saved order object.
	 */
	public function order_saved( mixed $order ): void {
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() || 'trash' === $order->get_status( 'edit' ) ) {
			return;
		}

		$this->synchronizer->sync( (int) $order->get_id() );
	}

	/**
	 * `woocommerce_before_delete_order`.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function order_deleting( mixed $order_id ): void {
		if ( is_numeric( $order_id ) ) {
			$this->synchronizer->order_deleting( (int) $order_id );
		}
	}

	/**
	 * `before_delete_post` for `shop_order`.
	 *
	 * @param mixed $post_id Post id.
	 * @param mixed $post    Post.
	 */
	public function post_deleting( mixed $post_id, mixed $post = null ): void {
		if ( $post instanceof WP_Post && 'shop_order' === $post->post_type ) {
			$this->synchronizer->order_deleting( (int) $post->ID );
		}
	}

	/**
	 * `deleted_post` for `shop_order_refund`.
	 *
	 * @param mixed $post_id Post id.
	 * @param mixed $post    Post.
	 */
	public function post_deleted( mixed $post_id, mixed $post = null ): void {
		if ( $post instanceof WP_Post && 'shop_order_refund' === $post->post_type && $post->post_parent > 0 ) {
			$this->synchronizer->sync( (int) $post->post_parent, (int) $post->ID );
		}
	}

	/**
	 * `woocommerce_refund_deleted`.
	 *
	 * @param mixed $refund_id Refund id.
	 * @param mixed $order_id  Order id.
	 */
	public function refund_deleted( mixed $refund_id, mixed $order_id ): void {
		if ( is_numeric( $refund_id ) && is_numeric( $order_id ) ) {
			$this->synchronizer->sync( (int) $order_id, (int) $refund_id );
		}
	}
}
