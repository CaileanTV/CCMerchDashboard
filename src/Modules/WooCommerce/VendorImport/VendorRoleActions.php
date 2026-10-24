<?php
/**
 * Admin-post handler that removes Product Vendors' roles.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\VendorImport;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The admin-post handler of step 26.3 (ADR-074 pkt. 8). Order of checks:
 * capability `merchboard_manage_creators` → nonce → the confirmation
 * checkbox → the removal, which also requires `promote_users` and only
 * changes the confirmed users that are still migrated users with a
 * Product Vendors role. Post/Redirect/Get to the page with an outcome code.
 */
final class VendorRoleActions {

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'merchboard_vendor_roles';

	/**
	 * Outcome: the roles were removed.
	 */
	public const DONE = 'vendor_roles_removed';

	/**
	 * Outcome: the confirmation was not ticked.
	 */
	public const NOT_CONFIRMED = 'vendor_roles_not_confirmed';

	/**
	 * Outcome: a technical error stopped it.
	 */
	public const ERROR = 'vendor_roles_error';

	/**
	 * Constructor.
	 *
	 * @param VendorRoleCleanup $cleanup The removal.
	 * @param Logger            $logger  Technical log.
	 */
	public function __construct( private VendorRoleCleanup $cleanup, private Logger $logger ) {
	}

	/**
	 * WordPress entry point (`admin_post_merchboard_vendor_roles`, hooked by
	 * WooCommerceModule so the service is built only for this request).
	 */
	public function handle_request(): void {
		// The nonce is verified in process() before any input is used.
		wp_safe_redirect( $this->process( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce verified and every field validated in process().
		exit;
	}

	/**
	 * Processes a request.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function process( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creators.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$nonce = is_string( $input['_wpnonce'] ?? null ) ? $input['_wpnonce'] : '';

		if ( 1 !== wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		if ( '1' !== ( $input['confirm'] ?? '' ) ) {
			return self::back( self::NOT_CONFIRMED );
		}

		$user_ids = array();

		foreach ( is_array( $input['users'] ?? null ) ? $input['users'] : array() as $user_id ) {
			if ( is_string( $user_id ) && ctype_digit( $user_id ) && (int) $user_id > 0 ) {
				$user_ids[] = (int) $user_id;
			}
		}

		try {
			$this->cleanup->remove( $user_ids );

			return self::back( self::DONE );
		} catch ( VendorImportException $e ) {
			return self::back( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Product Vendors\' roles could not be removed.', array( 'reason' => $e->getMessage() ) );

			return self::back( self::ERROR );
		}
	}

	/**
	 * The page with an outcome code.
	 *
	 * @param string $code Outcome code.
	 */
	private static function back( string $code ): string {
		return VendorImportPage::url( array( VendorImportPage::RESULT_ARG => $code ) );
	}
}
