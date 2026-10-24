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
use MerchBoard\Modules\Alerts\Api\AlertApiAccess;
use MerchBoard\Modules\Alerts\Api\AlertApiException;
use MerchBoard\Modules\Alerts\Api\AlertApiKeys;
use MerchBoard\Modules\Alerts\Api\AlertApiSwitch;
use MerchBoard\Modules\Alerts\Webhooks\AlertWebhooks;
use MerchBoard\Modules\Alerts\Webhooks\WebhookException;

defined( 'ABSPATH' ) || exit;

/**
 * Switches the alert API on or off, sets a creator's access to API keys and
 * webhooks (step 25.12.4), revokes all keys and switches off all webhooks of
 * a creator (steps 25.12.1–25.12.2, ADR-073). Order of checks: capability
 * `merchboard_manage_alerts` → nonce → input (`do`: switch | access |
 * revoke_all | disable_webhooks; the last two need the confirm checkbox) →
 * AlertApiSwitch / AlertApiAccess / AlertApiKeys / AlertWebhooks (check the
 * capability again, audit). Post/Redirect/Get to the tab with a whitelisted
 * outcome code.
 */
final class AlertApiActions {

	/**
	 * The admin-post action and nonce action.
	 */
	public const ACTION = 'merchboard_alert_api';

	/**
	 * Constructor.
	 *
	 * @param AlertApiSwitch      $api    The switch.
	 * @param AlertApiKeys        $keys   Keys.
	 * @param Logger              $logger Technical log.
	 * @param AlertWebhooks|null  $webhooks Webhooks (step 25.12.2); null for none.
	 * @param AlertApiAccess|null $access  The creators' access (step 25.12.4); null for none.
	 */
	public function __construct( private AlertApiSwitch $api, private AlertApiKeys $keys, private Logger $logger, private ?AlertWebhooks $webhooks = null, private ?AlertApiAccess $access = null ) {
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

			$creator = 1 === preg_match( '/^[1-9][0-9]{0,18}$/D', $field( 'creator_id' ) ) ? (int) $field( 'creator_id' ) : 0;

			if ( 'access' === $field( 'do' ) && $creator > 0 && null !== $this->access ) {
				$changed = $this->access->set( $creator, '1' === $field( 'keys' ), '1' === $field( 'webhooks' ) );

				return AlertsAdminPage::url( AlertApiTab::TAB, $changed ? 'api_access_saved' : 'no_changes' );
			}

			if ( in_array( $field( 'do' ), array( 'revoke_all', 'disable_webhooks' ), true ) && $creator > 0 ) {
				if ( '1' !== $field( 'confirm' ) ) {
					return AlertsAdminPage::url( AlertApiTab::TAB, 'api_confirm_required' );
				}

				if ( 'revoke_all' === $field( 'do' ) ) {
					$this->keys->revoke_all( $creator );

					return AlertsAdminPage::url( AlertApiTab::TAB, 'api_keys_revoked' );
				}

				if ( null !== $this->webhooks ) {
					$this->webhooks->disable_all( $creator );

					return AlertsAdminPage::url( AlertApiTab::TAB, 'api_webhooks_disabled' );
				}
			}
		} catch ( AlertApiException | WebhookException $e ) {
			return AlertsAdminPage::url( AlertApiTab::TAB, 'api_' . $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Changing the alert API failed.', array_merge( $e->context(), array( 'reason' => $e->getMessage() ) ) );

			return AlertsAdminPage::url( AlertApiTab::TAB, 'api_error' );
		}

		return AlertsAdminPage::url( AlertApiTab::TAB );
	}
}
