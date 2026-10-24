<?php
/**
 * Admin-post handler of the migration from Product Vendors.
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
 * The admin-post handler of the migration (step 26.2, ADR-074). Order of
 * checks: capability `merchboard_manage_creators` → nonce → the backup
 * checkbox → the fingerprint's form → the migration itself, which checks
 * `merchboard_manage_payouts`, Product Vendors and that a fresh dry run
 * with the same chosen users has the confirmed fingerprint.
 * Post/Redirect/Get to the page with an outcome code.
 */
final class VendorImportActions {

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'merchboard_vendor_import';

	/**
	 * Outcome: the migration ran.
	 */
	public const DONE = 'vendors_imported';

	/**
	 * Outcome: a technical error stopped it.
	 */
	public const ERROR = 'vendors_error';

	/**
	 * Constructor.
	 *
	 * @param VendorImporter $importer The migration.
	 * @param Logger         $logger   Technical log.
	 */
	public function __construct( private VendorImporter $importer, private Logger $logger ) {
	}

	/**
	 * WordPress entry point (`admin_post_merchboard_vendor_import`, hooked
	 * by WooCommerceModule so the service is built only for this request).
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

		$fingerprint = is_string( $input['fingerprint'] ?? null ) ? $input['fingerprint'] : '';

		if ( '1' !== ( $input['backup'] ?? '' ) ) {
			return self::back( VendorImportException::NO_BACKUP );
		}

		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/D', $fingerprint ) ) {
			return self::back( VendorImportException::OUTDATED );
		}

		try {
			$this->importer->run( $fingerprint, VendorImportPage::choices( $input[ VendorImportPage::USER_ARG ] ?? array() ), VendorImportPage::category_choices( $input[ VendorImportPage::CATEGORY_ARG ] ?? array() ) );

			return self::back( self::DONE );
		} catch ( VendorImportException $e ) {
			return self::back( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The migration from Product Vendors failed.', array( 'reason' => $e->getMessage() ) );

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
