<?php
/**
 * Request handler for deleting an order's stored history.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php?action=merchboard_delete_order_history` (ADR-052), POST
 * only, in this order: capability (403), a nonce bound to the order (403),
 * the typed order id as confirmation, then OrderHistoryEraser. Always ends
 * with a redirect (Post/Redirect/Get) to the order history: the list after a
 * deletion, the order otherwise. No AJAX, REST or `nopriv` route.
 */
final class OrderHistoryActions {

	public const ACTION = 'merchboard_delete_order_history';

	/**
	 * Outcome codes in the redirect (besides the eraser's).
	 */
	public const NOT_CONFIRMED = 'not_confirmed';

	/**
	 * Constructor.
	 *
	 * @param OrderHistoryEraser $eraser Deletion.
	 */
	public function __construct( private OrderHistoryEraser $eraser ) {
	}

	/**
	 * Nonce action of one order.
	 *
	 * @param int $order_id Order id.
	 */
	public static function nonce_action( int $order_id ): string {
		return self::ACTION . '_' . $order_id;
	}

	/**
	 * Hooks the handler (logged-in users only).
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * Handles the current request and redirects.
	 */
	public function handle_request(): void {
		// The nonce is verified in handle() before any input is used.
		wp_safe_redirect( $this->handle( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field validated in handle().
		exit;
	}

	/**
	 * Handles a request.
	 *
	 * @param array<mixed> $input Unslashed POST data.
	 * @return string Redirect URL.
	 */
	public function handle( array $input ): string {
		if ( ! current_user_can( Capabilities::DELETE_ORDER_HISTORY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to delete order history.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$order_id = self::id( $input, 'order_id' );
		$nonce    = is_string( $input['_wpnonce'] ?? null ) ? $input['_wpnonce'] : '';

		if ( null === $order_id || '' === $nonce || false === wp_verify_nonce( $nonce, self::nonce_action( $order_id ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		if ( self::id( $input, 'confirm_order_id' ) !== $order_id ) {
			return OrderHistoryPage::url(
				array(
					'order'       => $order_id,
					'erase_error' => self::NOT_CONFIRMED,
				)
			);
		}

		try {
			$this->eraser->erase( $order_id );
		} catch ( OrderHistoryEraseException $e ) {
			return OrderHistoryPage::url(
				array(
					'order'       => $order_id,
					'erase_error' => $e->reason(),
				)
			);
		}

		return OrderHistoryPage::url( array( 'erased' => $order_id ) );
	}

	/**
	 * A positive id from the input, or null.
	 *
	 * @param array<mixed> $input Input.
	 * @param string       $key   Key.
	 */
	private static function id( array $input, string $key ): ?int {
		$raw = is_scalar( $input[ $key ] ?? null ) ? trim( (string) $input[ $key ] ) : '';

		return 1 === preg_match( '/^[1-9]\d{0,18}$/', $raw ) ? (int) $raw : null;
	}
}
