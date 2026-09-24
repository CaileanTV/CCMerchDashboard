<?php
/**
 * State-changing settings request (admin-post.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Settings\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Settings\SettingsException;
use MerchBoard\Modules\Settings\SettingsService;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the settings form. Order of checks, as for creator administration:
 *
 * 1. capability (`merchboard_manage_settings`) → 403 otherwise;
 * 2. nonce → 403 otherwise;
 * 3. input: only the whitelisted fields, sanitized;
 * 4. SettingsService (checks the capability again, requires the typed
 *    confirmation to enable deletion, audits the change).
 *
 * Post/Redirect/Get with whitelisted notice codes.
 */
final class SettingsActions {

	/**
	 * The admin-post action and nonce action.
	 */
	public const SAVE_ACTION = 'merchboard_save_settings';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $service Use cases.
	 * @param Logger          $logger  Technical log.
	 */
	public function __construct( private SettingsService $service, private Logger $logger ) {
	}

	/**
	 * Registers the admin-post handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save_request' ) );
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_save_request(): void {
		// The nonce is verified in save() before any input is used.
		wp_safe_redirect( $this->save( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in save().
		exit;
	}

	/**
	 * Processes the form.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function save( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage Merch Dashboard settings.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$nonce = is_scalar( $input['_wpnonce'] ?? null ) ? (string) $input['_wpnonce'] : '';

		if ( 1 !== wp_verify_nonce( $nonce, self::SAVE_ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$enabled      = '1' === ( is_scalar( $input[ SettingsPage::FIELD_DELETE ] ?? null ) ? (string) $input[ SettingsPage::FIELD_DELETE ] : '' );
		$confirmation = sanitize_text_field( is_scalar( $input[ SettingsPage::FIELD_CONFIRM ] ?? null ) ? (string) $input[ SettingsPage::FIELD_CONFIRM ] : '' );

		try {
			$changed = $this->service->set_delete_data_on_uninstall( $enabled, $confirmation );
		} catch ( SettingsException $e ) {
			return SettingsPage::url( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error(
				'Saving Merch Dashboard settings failed.',
				array_merge(
					$e->context(),
					array( 'reason' => $e->getMessage() )
				)
			);

			return SettingsPage::url( 'error' );
		}

		return SettingsPage::url( $changed ? 'saved' : 'no_changes' );
	}
}
