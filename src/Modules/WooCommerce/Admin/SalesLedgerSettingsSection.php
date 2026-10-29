<?php
/**
 * "Sales ledger" section on the settings screen.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Settings\Admin\SettingsPage;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatusException;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatusSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an administrator choose which order statuses count as sales
 * (ADR-038 S1-S14). Rendered on "Merch Dashboard → Settings" via
 * `merchboard_settings_sections`, between the dashboard slug and "Data on
 * uninstall" (S11). Read-only rendering with its own form, nonce and
 * notices; every change goes through SalesLedgerSettingsActions. A counted
 * status WooCommerce no longer registers is marked "not registered"; it can
 * be kept or removed. Registered statuses that cannot count (no `wc-` key,
 * invalid or too long slug) are listed separately as "Not supported" without
 * a checkbox, never left out silently. Everything is escaped at output.
 */
final class SalesLedgerSettingsSection {

	/**
	 * The settings tab of the section.
	 */
	public const TAB = 'sales';

	/**
	 * Constructor.
	 *
	 * @param CountedOrderStatusSettings $settings Choices.
	 */
	public function __construct( private CountedOrderStatusSettings $settings ) {
	}

	/**
	 * Registers the section on the settings screen.
	 */
	public function register(): void {
		SettingsPage::add_tab( self::TAB, static fn(): string => __( 'Sales and payouts', 'merchboard' ) );
		add_action( 'merchboard_settings_sections', array( $this, 'render' ) );
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'saved'                                     => array( __( 'Counted order statuses saved. They apply from the next synchronization of an order; nothing was recalculated.', 'merchboard' ), 'success' ),
			'no_changes'                                => array( __( 'No changes were made to the counted order statuses.', 'merchboard' ), 'info' ),
			CountedOrderStatusException::NOT_AUTHORIZED => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			CountedOrderStatusException::UNAVAILABLE    => array( __( 'The counted order statuses were not changed: WooCommerce is not available.', 'merchboard' ), 'error' ),
			CountedOrderStatusException::EMPTY_LIST     => array( __( 'The counted order statuses were not changed: choose at least one status.', 'merchboard' ), 'error' ),
			CountedOrderStatusException::NOT_ALLOWED    => array( __( 'The counted order statuses were not changed: a chosen status cannot count. Reload the page and try again.', 'merchboard' ), 'error' ),
			'error'                                     => array( __( 'A technical error occurred. The counted order statuses were not changed.', 'merchboard' ), 'error' ),
			default                                     => null,
		};
	}

	/**
	 * Renders the section.
	 *
	 * @param mixed $tab The open settings tab (only its own renders).
	 */
	public function render( mixed $tab = self::TAB ): void {
		if ( self::TAB !== $tab ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		// Read-only outcome code; nothing is changed by this request.
		$code   = isset( $_GET[ SalesLedgerSettingsActions::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ SalesLedgerSettingsActions::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::result_message( $code );

		echo '<h2 id="merchboard-sales-ledger">' . esc_html__( 'Sales ledger', 'merchboard' ) . '</h2>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		try {
			$choices     = $this->settings->choices();
			$unsupported = $this->settings->unsupported();
		} catch ( CountedOrderStatusException ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'WooCommerce is not available, so the counted order statuses cannot be changed now.', 'merchboard' ) . '</p></div>';

			return;
		}

		echo '<p>' . esc_html__( 'Orders in these statuses count as sales in the sales ledger.', 'merchboard' ) . '</p>';
		echo '<p>' . esc_html__( 'A change applies only to future synchronizations: an order is evaluated with the new list the next time it is saved or synchronized, also if it already exists. Nothing is recalculated now, and the sales history is never changed.', 'merchboard' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( SalesLedgerSettingsActions::SAVE_ACTION ) . '">';
		wp_nonce_field( SalesLedgerSettingsActions::SAVE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Counted order statuses', 'merchboard' ) . '</th><td><fieldset>';
		echo '<legend class="screen-reader-text">' . esc_html__( 'Counted order statuses', 'merchboard' ) . '</legend>';

		foreach ( $choices as $choice ) {
			echo '<label><input type="checkbox" name="' . esc_attr( SalesLedgerSettingsActions::FIELD_STATUSES ) . '[]" value="' . esc_attr( $choice['status'] ) . '"' . checked( $choice['selected'], true, false ) . '> ';
			echo esc_html( $choice['label'] ) . ' <code>' . esc_html( $choice['status'] ) . '</code>';

			if ( ! $choice['registered'] ) {
				echo ' <strong>(' . esc_html__( 'not registered', 'merchboard' ) . ')</strong>';
			}

			echo '</label><br>';
		}

		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'At least one status is required. Pending payment, failed, cancelled, refunded, draft and trashed orders never count. A status marked "not registered" is no longer known to WooCommerce (for example because the plugin that adds it is inactive); it still counts, can be removed, but cannot be added again until it is registered.', 'merchboard' ) . '</p>';
		echo '</td></tr>';
		$this->render_unsupported( $unsupported );
		echo '</tbody></table>';
		submit_button( __( 'Save counted statuses', 'merchboard' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Registered statuses that cannot count, with the reason; no checkbox, so
	 * they can never be chosen (ADR-038, status note 2026-09-30).
	 *
	 * @param list<array{status: string, label: string, reason: string}> $unsupported Unsupported registrations.
	 */
	private function render_unsupported( array $unsupported ): void {
		if ( array() === $unsupported ) {
			return;
		}

		echo '<tr><th scope="row">' . esc_html__( 'Not supported', 'merchboard' ) . '</th><td>';
		echo '<p class="description">' . esc_html__( 'These statuses are registered by WooCommerce or another plugin, but they cannot count as sales and cannot be chosen: only statuses with a "wc-" key and a slug that both order storages (HPOS and legacy) keep unchanged are supported.', 'merchboard' ) . '</p>';
		echo '<ul>';

		foreach ( $unsupported as $status ) {
			echo '<li>' . esc_html( $status['label'] ) . ' <code>' . esc_html( $status['status'] ) . '</code> – ' . esc_html( self::unsupported_reason( $status['reason'], $status['status'] ) ) . '</li>';
		}

		echo '</ul></td></tr>';
	}

	/**
	 * Explanation of an unsupported reason. For an invalid format, a key that
	 * is only "wc-" or repeats the prefix is explained as such; otherwise the
	 * characters are the problem.
	 *
	 * @param string $reason One of the CountedOrderStatusSettings::UNSUPPORTED_* constants.
	 * @param string $key    Registered key.
	 */
	private static function unsupported_reason( string $reason, string $key ): string {
		if ( CountedOrderStatusSettings::UNSUPPORTED_FORMAT === $reason && ( 'wc-' === $key || str_starts_with( $key, 'wc-wc-' ) ) ) {
			return __( 'the key must be "wc-" followed by the status slug, without an empty or repeated "wc-"', 'merchboard' );
		}

		return match ( $reason ) {
			CountedOrderStatusSettings::UNSUPPORTED_NO_PREFIX => __( 'the key does not start with "wc-"', 'merchboard' ),
			CountedOrderStatusSettings::UNSUPPORTED_TOO_LONG  => sprintf(
				/* translators: %d: maximum number of characters. */
				__( 'longer than %d characters without "wc-"', 'merchboard' ),
				Settings::STATUS_SLUG_MAX_LENGTH
			),
			default                                           => __( 'only lowercase letters, digits, "_" and "-" are supported', 'merchboard' ),
		};
	}
}
