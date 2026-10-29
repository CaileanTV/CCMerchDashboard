<?php
/**
 * Form handler of the approval queue.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerReview;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php?action=merchboard_designer_review` (step 30.10): `do` =
 * `approve`, `reject` (with `note`) or `retry` for one `version`.
 * Capability `merchboard_manage_designer` first, then the nonce bound to
 * the version; Post/Redirect/Get to Designs with a result code.
 */
final class DesignerReviewActions {

	public const ACTION = 'merchboard_designer_review';

	/**
	 * Constructor.
	 *
	 * @param DesignerReview $review The decisions.
	 * @param Logger         $logger Technical log.
	 */
	public function __construct( private DesignerReview $review, private Logger $logger ) {
	}

	/**
	 * Nonce action of one version.
	 *
	 * @param int $version_id Version.
	 */
	public static function nonce_action( int $version_id ): string {
		return self::ACTION . '_' . $version_id;
	}

	/**
	 * Hooks the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * Handles the request and redirects.
	 */
	public function handle_request(): void {
		// The nonce is verified in handle() before any input is used.
		wp_safe_redirect( $this->handle( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle(); the note is sanitized by DesignerReview.
		exit;
	}

	/**
	 * Handles the form.
	 *
	 * @param array<mixed> $input Unslashed POST data.
	 * @return string Redirect URL.
	 */
	public function handle( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the product designer.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$text    = static fn( string $key ): string => is_string( $input[ $key ] ?? null ) ? $input[ $key ] : '';
		$version = 1 === preg_match( '/^[1-9]\d{0,18}$/', $text( 'version' ) ) ? (int) $text( 'version' ) : 0;

		if ( 0 === $version || 1 !== wp_verify_nonce( $text( '_wpnonce' ), self::nonce_action( $version ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			switch ( $text( 'do' ) ) {
				case 'approve':
					$this->review->approve( $version );

					return DesignsPage::url( 'approved' );
				case 'reject':
					$this->review->reject( $version, $text( 'note' ) );

					return DesignsPage::url( 'rejected' );
				case 'retry':
					$this->review->retry( $version );

					return DesignsPage::url( 'retried' );
				default:
					return DesignsPage::url( 'error' );
			}
		} catch ( DesignerException $e ) {
			return DesignsPage::url( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A design decision failed.', array_merge( $e->context(), array( 'version_id' => $version ) ) );

			return DesignsPage::url( 'error' );
		}
	}
}
