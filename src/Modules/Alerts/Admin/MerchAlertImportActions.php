<?php
/**
 * Runs the import from MerchAlert from the admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\AlertSettingsException;
use MerchBoard\Modules\Alerts\Fonts\AlertFontsException;
use MerchBoard\Modules\Alerts\Import\MerchAlertImporter;
use MerchBoard\Modules\Alerts\Import\MerchAlertImportException;

defined( 'ABSPATH' ) || exit;

/**
 * The admin-post handler of the import (step 25.10.1). Order of checks:
 * capability `merchboard_manage_alerts` → nonce → the backup checkbox → the
 * import itself, which refuses while MerchAlert is active and when a fresh
 * dry run differs from the confirmed one (fingerprint). Post/Redirect/Get
 * to the Import tab with an outcome code.
 */
final class MerchAlertImportActions {

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'merchboard_alert_import';

	/**
	 * Constructor.
	 *
	 * @param MerchAlertImporter $importer The import.
	 * @param Logger             $logger   Technical log.
	 */
	public function __construct( private MerchAlertImporter $importer, private Logger $logger ) {
	}

	/**
	 * Registers the admin-post handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * WordPress entry point.
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
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage alerts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$nonce = is_string( $input['_wpnonce'] ?? null ) ? $input['_wpnonce'] : '';

		if ( 1 !== wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$backup      = $input['backup'] ?? '';
		$fingerprint = is_string( $input['fingerprint'] ?? null ) ? $input['fingerprint'] : '';

		if ( '1' !== $backup ) {
			return AlertsAdminPage::url( MerchAlertImportTab::TAB, MerchAlertImportException::BACKUP_REQUIRED );
		}

		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/D', $fingerprint ) ) {
			return AlertsAdminPage::url( MerchAlertImportTab::TAB, MerchAlertImportException::PREVIEW_OUTDATED );
		}

		try {
			$this->importer->run( $fingerprint );

			return AlertsAdminPage::url( MerchAlertImportTab::TAB, 'imported' );
		} catch ( MerchAlertImportException $e ) {
			return AlertsAdminPage::url( MerchAlertImportTab::TAB, $e->reason() );
		} catch ( DatabaseException | AlertSettingsException | AlertFontsException $e ) {
			$this->logger->error( 'The import from MerchAlert failed.', array( 'reason' => $e->getMessage() ) );

			return AlertsAdminPage::url( MerchAlertImportTab::TAB, 'import_error' );
		}
	}
}
