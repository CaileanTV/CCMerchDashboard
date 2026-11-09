<?php
/**
 * Admin decisions on submitted master data.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\BillingDetailsReview;

defined( 'ABSPATH' ) || exit;

/**
 * The admin-post handlers of the "Master data" tab (ADR-104): confirm or ask
 * for corrections, with the version of the submission that was shown. POST
 * only; capability → nonce (bound to the creator) → BillingDetailsReview.
 * Post/Redirect/Get with a whitelisted outcome code; technical failures are
 * logged without values.
 */
final class BillingDetailsReviewActions {

	public const CONFIRM         = 'merchboard_confirm_billing_details';
	public const REQUEST_CHANGES = 'merchboard_request_billing_changes';

	/**
	 * The field with the shown submission's version.
	 */
	public const VERSION_FIELD = 'version';

	/**
	 * The field with the note on corrections.
	 */
	public const NOTE_FIELD = 'note';

	/**
	 * Constructor.
	 *
	 * @param BillingDetailsReview $review Master data and their review.
	 * @param Logger               $logger Technical log.
	 */
	public function __construct( private BillingDetailsReview $review, private Logger $logger ) {
	}

	/**
	 * The nonce action of a decision.
	 *
	 * @param string $action     CONFIRM or REQUEST_CHANGES.
	 * @param int    $creator_id Creator.
	 */
	public static function nonce_action( string $action, int $creator_id ): string {
		return $action . '_' . $creator_id;
	}

	/**
	 * Registers the handlers.
	 */
	public function register(): void {
		foreach ( array( self::CONFIRM, self::REQUEST_CHANGES ) as $action ) {
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
	 * Decides the submission of the form.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function process( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage payouts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$action  = self::scalar( $input, 'action' );
		$creator = absint( self::scalar( $input, 'creator_id' ) );
		$nonce   = self::scalar( $input, '_wpnonce' );

		if ( ! in_array( $action, array( self::CONFIRM, self::REQUEST_CHANGES ), true ) || '' === $nonce || false === wp_verify_nonce( $nonce, self::nonce_action( $action, $creator ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$version = self::scalar( $input, self::VERSION_FIELD );

		try {
			if ( self::CONFIRM === $action ) {
				$this->review->confirm( $creator, $version );

				return self::result( 'confirmed' );
			}

			$this->review->request_changes( $creator, $version, self::scalar( $input, self::NOTE_FIELD ) );

			return self::result( 'changes_requested' );
		} catch ( PayoutException $e ) {
			return self::result( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Deciding submitted master data failed.', array_merge( $e->context(), array( 'creator_id' => $creator ) ) );

			return self::result( 'error' );
		}
	}

	/**
	 * The tab with an outcome code.
	 *
	 * @param string $code Outcome code.
	 */
	private static function result( string $code ): string {
		return BillingDetailsReviewPage::url( array( BillingDetailsReviewPage::RESULT_ARG => $code ) );
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
