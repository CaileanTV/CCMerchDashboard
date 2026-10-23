<?php
/**
 * State-changing requests of the "API" tab (admin-post.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\Api\AlertApiException;
use MerchBoard\Modules\Alerts\Api\AlertApiKeys;
use MerchBoard\Modules\Alerts\Api\AlertApiSwitch;

defined( 'ABSPATH' ) || exit;

/**
 * Switches the alert API on or off and revokes all keys of a creator (step
 * 25.12.1, ADR-073). Order of checks: capability `merchboard_manage_alerts`
 * → nonce → input (`do`: switch | revoke_all; revoking needs the confirm
 * checkbox) → AlertApiSwitch / AlertApiKeys (check the capability again,
 * audit). Post/Redirect/Get to the tab with a whitelisted outcome code.
 */
final class AlertApiActions {

	/**
	 * The admin-post action and nonce action.
	 */
	public const ACTION = 'merchboard_alert_api';

	/**
	 * Constructor.
	 *
	 * @param AlertApiSwitch $api    The switch.
	 * @param AlertApiKeys   $keys   Keys.
	 * @param Logger         $logger Technical log.
	 */
	public function __construct( private AlertApiSwitch $api, private AlertApiKeys $keys, private Logger $logger ) {
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
		wp_safe_redirect( $this->process( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field validated in process().
		exit;
	}

	/**
	 * Processes the form.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function process( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage alerts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$field = static fn( string $key ): string => is_scalar( $input[ $key ] ?? null ) ? (string) $input[ $key ] : '';

		if ( 1 !== wp_verify_nonce( $field( '_wpnonce' ), self::ACTION ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			if ( 'switch' === $field( 'do' ) ) {
				$enabled = '1' === $field( 'enabled' );

				if ( ! $this->api->set( $enabled ) ) {
					return AlertsAdminPage::url( AlertApiTab::TAB, 'no_changes' );
				}

				return AlertsAdminPage::url( AlertApiTab::TAB, $enabled ? 'api_enabled' : 'api_disabled' );
			}

			if ( 'revoke_all' === $field( 'do' ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/D', $field( 'creator_id' ) ) ) {
				if ( '1' !== $field( 'confirm' ) ) {
					return AlertsAdminPage::url( AlertApiTab::TAB, 'api_confirm_required' );
				}

				$this->keys->revoke_all( (int) $field( 'creator_id' ) );

				return AlertsAdminPage::url( AlertApiTab::TAB, 'api_keys_revoked' );
			}
		} catch ( AlertApiException $e ) {
			return AlertsAdminPage::url( AlertApiTab::TAB, 'api_' . $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Changing the alert API failed.', array_merge( $e->context(), array( 'reason' => $e->getMessage() ) ) );

			return AlertsAdminPage::url( AlertApiTab::TAB, 'api_error' );
		}

		return AlertsAdminPage::url( AlertApiTab::TAB );
	}
}
