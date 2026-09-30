<?php
/**
 * State-changing request of the "Sales ledger" settings section (admin-post.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Settings\Admin\SettingsPage;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatusException;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatusSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Saves the counted order statuses (ADR-038 S1-S14). Order of checks:
 *
 * 1. capability `merchboard_manage_settings` → 403 otherwise;
 * 2. nonce → 403 otherwise;
 * 3. input: only the status list, as plain strings;
 * 4. CountedOrderStatusSettings (checks the capability again, validates the
 *    statuses against WooCommerce, detects "no change", writes audit and
 *    option in one transaction).
 *
 * Post/Redirect/Get to the settings screen with its own whitelisted outcome
 * code (S12), so the Settings module's notices are not involved.
 */
final class SalesLedgerSettingsActions {

	/**
	 * The admin-post action and nonce action.
	 */
	public const SAVE_ACTION = 'merchboard_save_counted_order_statuses';

	/**
	 * Query argument: outcome code after a redirect.
	 */
	public const RESULT_ARG = 'merchboard_sales_ledger_result';

	/**
	 * The status checkboxes (`name="counted_order_statuses[]"`).
	 */
	public const FIELD_STATUSES = 'counted_order_statuses';

	/**
	 * Constructor.
	 *
	 * @param CountedOrderStatusSettings $settings Validation and saving.
	 * @param Logger                     $logger   Technical log.
	 */
	public function __construct( private CountedOrderStatusSettings $settings, private Logger $logger ) {
	}

	/**
	 * URL of the settings screen with an outcome code.
	 *
	 * @param string $result Outcome code.
	 */
	public static function url( string $result ): string {
		return add_query_arg( array( self::RESULT_ARG => $result ), SettingsPage::url() );
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

		$statuses = array();
		$raw      = $input[ self::FIELD_STATUSES ] ?? array();

		foreach ( is_array( $raw ) ? $raw : array() as $status ) {
			if ( is_scalar( $status ) ) {
				$statuses[] = sanitize_text_field( (string) $status );
			}
		}

		try {
			$changed = $this->settings->save( $statuses );
		} catch ( CountedOrderStatusException $e ) {
			return self::url( $e->reason() );
		} catch ( DatabaseException $e ) {
			$this->logger->error(
				'Saving the counted order statuses failed.',
				array_merge(
					$e->context(),
					array( 'reason' => $e->getMessage() )
				)
			);

			return self::url( 'error' );
		}

		return self::url( $changed ? 'saved' : 'no_changes' );
	}
}
