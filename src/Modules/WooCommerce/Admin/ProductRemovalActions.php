<?php
/**
 * Admin decisions on requests to remove a legacy product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\WooCommerce\ProductRemovals\ProductRemovalException;
use MerchBoard\Modules\WooCommerce\ProductRemovals\ProductRemovals;

defined( 'ABSPATH' ) || exit;

/**
 * The admin-post handlers of the "Removal requests" page (ADR-083): close a
 * request as handled or decline it with a note, with a nonce bound to the
 * request. POST only; capability → nonce → ProductRemovals. Post/Redirect/Get
 * with a whitelisted outcome code; technical failures are logged and shown as
 * a neutral notice. Nothing here touches a product.
 */
final class ProductRemovalActions {

	public const COMPLETE = 'merchboard_complete_product_removal';
	public const DECLINE  = 'merchboard_decline_product_removal';

	/**
	 * The field with the note on a decline.
	 */
	public const NOTE_FIELD = 'note';

	/**
	 * Constructor.
	 *
	 * @param ProductRemovals $removals Requests.
	 * @param Logger          $logger   Technical log.
	 */
	public function __construct( private ProductRemovals $removals, private Logger $logger ) {
	}

	/**
	 * The nonce action of a decision on one request.
	 *
	 * @param string $action     COMPLETE or DECLINE.
	 * @param int    $request_id Request.
	 */
	public static function nonce_action( string $action, int $request_id ): string {
		return $action . '_' . $request_id;
	}

	/**
	 * Registers the handlers.
	 */
	public function register(): void {
		foreach ( array( self::COMPLETE, self::DECLINE ) as $action ) {
			add_action( 'admin_post_' . $action, array( $this, 'handle_request' ) );
		}
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_request(): void {
		wp_safe_redirect( $this->process( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in process().
		exit;
	}

	/**
	 * Decides the request of the form.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function process( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$action = self::scalar( $input, 'action' );
		$id     = absint( self::scalar( $input, 'request_id' ) );
		$nonce  = self::scalar( $input, '_wpnonce' );

		if ( ! in_array( $action, array( self::COMPLETE, self::DECLINE ), true ) || '' === $nonce || false === wp_verify_nonce( $nonce, self::nonce_action( $action, $id ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			if ( self::DECLINE === $action ) {
				$this->removals->decline( $id, self::scalar( $input, self::NOTE_FIELD ) );

				return self::result( 'declined' );
			}

			$request = $this->removals->complete( $id );

			return self::result( ProductRemovalsPage::in_shop( $request->product_id ) ? 'completed_in_shop' : 'completed' );
		} catch ( ProductRemovalException $e ) {
			return self::result( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Deciding a product removal request failed.', array_merge( $e->context(), array( 'id' => $id ) ) );

			return self::result( 'error' );
		}
	}

	/**
	 * The page with an outcome code.
	 *
	 * @param string $code Outcome code.
	 */
	private static function result( string $code ): string {
		return ProductRemovalsPage::url( array( ProductRemovalsPage::RESULT_ARG => $code ) );
	}

	/**
	 * A scalar request value as a string ('' otherwise).
	 *
	 * @param array<mixed> $input Request data.
	 * @param string       $key   Key.
	 */
	private static function scalar( array $input, string $key ): string {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
	}
}
