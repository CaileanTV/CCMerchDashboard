<?php
/**
 * Payout settings on the settings screen and the creator's edit page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\WooCommerce\Payouts\GiftCards\GiftCardProviders;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutSettings;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Two small forms, shown only with `merchboard_manage_payouts` (ADR-058):
 * "Payouts" on the settings screen (holding period) and "Payout settlement
 * start" on the creator's edit page (D3, D6). Read-only rendering; every
 * change goes through PayoutSettingsActions. Everything is escaped at
 * output.
 */
final class PayoutSettingsSections {

	/**
	 * Constructor.
	 *
	 * @param PayoutSettings         $settings  Current values.
	 * @param GiftCardProviders|null $providers Registered gift card adapters; null = none.
	 */
	public function __construct( private PayoutSettings $settings, private ?GiftCardProviders $providers = null ) {
	}

	/**
	 * Registers both sections.
	 */
	public function register(): void {
		add_action( 'merchboard_settings_sections', array( $this, 'render_settings' ) );
		add_action( 'merchboard_creators_edit_sections', array( $this, 'render_creator' ), 9 );
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'saved'                               => array( __( 'Saved. Available payout amounts are calculated with the new value from now on.', 'merchboard' ), 'success' ),
			'unchanged'                           => array( __( 'Nothing was changed.', 'merchboard' ), 'info' ),
			PayoutException::NOT_AUTHORIZED       => array( __( 'You are not allowed to manage payouts.', 'merchboard' ), 'error' ),
			PayoutException::NOT_FOUND            => array( __( 'The creator does not exist.', 'merchboard' ), 'error' ),
			PayoutException::INVALID_HOLDING_DAYS => array(
				sprintf(
					/* translators: %d: maximum number of days. */
					__( 'Not saved: enter a whole number of days between 0 and %d.', 'merchboard' ),
					Settings::MAX_PAYOUT_HOLDING_DAYS
				),
				'error',
			),
			PayoutException::INVALID_START        => array( __( 'Not saved: enter a valid date that is not in the future, or leave the field empty for the whole history.', 'merchboard' ), 'error' ),
			PayoutException::START_LOCKED         => array( __( 'Not saved: the creator already has a settlement document, so the settlement start can no longer be changed.', 'merchboard' ), 'error' ),
			PayoutException::BUSY                 => array( __( 'Not saved: a payout of this creator is being processed. Try again in a moment.', 'merchboard' ), 'error' ),
			PayoutException::INVALID_GIFT_CARD_PROVIDER => array( __( 'Not saved: choose one of the listed gift card plugins, or none.', 'merchboard' ), 'error' ),
			'error'                               => array( __( 'A technical error occurred. Nothing was changed; try again later.', 'merchboard' ), 'error' ),
			default                               => null,
		};
	}

	/**
	 * The "Payouts" section on the settings screen.
	 */
	public function render_settings(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			return;
		}

		echo '<h2 id="merchboard-payouts">' . esc_html__( 'Payouts', 'merchboard' ) . '</h2>';
		self::render_notice();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( PayoutSettingsActions::HOLDING_ACTION ) . '">';
		wp_nonce_field( PayoutSettingsActions::HOLDING_ACTION );
		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row"><label for="merchboard-payout-holding-days">' . esc_html__( 'Holding period (days)', 'merchboard' ) . '</label></th><td>';
		printf(
			'<input type="number" id="merchboard-payout-holding-days" name="%1$s" value="%2$d" min="0" max="%3$d" step="1" class="small-text">',
			esc_attr( PayoutSettingsActions::FIELD_HOLDING_DAYS ),
			(int) Settings::payout_holding_days(),
			(int) Settings::MAX_PAYOUT_HOLDING_DAYS
		);
		echo '<p class="description">' . esc_html__( 'A sale counts towards the available payout amount when it is older than this. Refunds and other negative amounts count at once. Changing it changes no sales history.', 'merchboard' ) . '</p>';
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save holding period', 'merchboard' ), 'secondary' );
		echo '</form>';
		$this->render_gift_card_provider();
	}

	/**
	 * The choice of the gift card plugin for payout gift cards (ADR-057), with
	 * the state of the chosen one. Choosing one installs, activates or
	 * configures nothing.
	 */
	private function render_gift_card_provider(): void {
		$providers = null === $this->providers ? array() : $this->providers->all();
		$current   = Settings::payout_gift_card_provider();

		echo '<h3 id="merchboard-payout-gift-cards">' . esc_html__( 'Payout gift cards', 'merchboard' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Creators can choose a gift card as payout only when a gift card plugin is chosen here and available. You create each card by hand in that plugin and register it on the payout. Bank transfer and PayPal do not depend on this.', 'merchboard' ) . '</p>';

		if ( null !== $this->providers && '' !== $current ) {
			$state = $this->providers->state();
			$text  = match ( $state['state'] ) {
				GiftCardProviders::AVAILABLE        => __( 'Status: available.', 'merchboard' ),
				GiftCardProviders::UNKNOWN_PROVIDER => __( 'Status: the chosen gift card plugin is not supported. Gift cards are not available.', 'merchboard' ),
				default                             => sprintf(
					/* translators: %s: reason code, e.g. inactive. */
					__( 'Status: not available (%s). Gift cards cannot be requested or registered.', 'merchboard' ),
					(string) $state['code']
				),
			};
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( GiftCardProviders::AVAILABLE === $state['state'] ? 'info' : 'warning' ), esc_html( $text ) );
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( PayoutSettingsActions::PROVIDER_ACTION ) . '">';
		wp_nonce_field( PayoutSettingsActions::PROVIDER_ACTION );
		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row"><label for="merchboard-payout-gift-card-provider">' . esc_html__( 'Gift card plugin', 'merchboard' ) . '</label></th><td>';
		echo '<select id="merchboard-payout-gift-card-provider" name="' . esc_attr( PayoutSettingsActions::FIELD_PROVIDER ) . '">';
		echo '<option value=""' . selected( $current, '', false ) . '>' . esc_html__( 'None (gift cards off)', 'merchboard' ) . '</option>';

		foreach ( $providers as $id => $provider ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $current, $id, false ) . '>' . esc_html( $provider->label() ) . '</option>';
		}

		echo '</select></td></tr></tbody></table>';
		submit_button( __( 'Save gift card plugin', 'merchboard' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * The "Payout settlement start" section on the creator's edit page.
	 *
	 * @param Creator $creator Creator being edited.
	 */
	public function render_creator( Creator $creator ): void {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			return;
		}

		echo '<hr><h2 id="merchboard-payout-start">' . esc_html__( 'Payout settlement start', 'merchboard' ) . '</h2>';
		self::render_notice();

		try {
			$start = $this->settings->settlement_start( $creator->id );
		} catch ( Throwable ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The settlement start could not be read.', 'merchboard' ) . '</p></div>';

			return;
		}

		echo '<p class="description">' . esc_html__( 'Only sales on or after this date count towards the creator\'s payouts; refunds and corrections follow their sale. Leave it empty to count the whole history. Older sales are never changed or filled in.', 'merchboard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( PayoutSettingsActions::START_ACTION ) . '">';
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator->id ) . '">';
		wp_nonce_field( PayoutSettingsActions::start_nonce_action( $creator->id ) );
		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row"><label for="merchboard-payout-start-date">' . esc_html__( 'Settlement start', 'merchboard' ) . '</label></th><td>';
		printf(
			'<input type="date" id="merchboard-payout-start-date" name="%1$s" value="%2$s">',
			esc_attr( PayoutSettingsActions::FIELD_START ),
			esc_attr( null === $start ? '' : $start->setTimezone( wp_timezone() )->format( 'Y-m-d' ) )
		);
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save settlement start', 'merchboard' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * The outcome of the previous request.
	 */
	private static function render_notice(): void {
		// Read-only outcome code; nothing is changed by this request.
		$code   = isset( $_GET[ PayoutSettingsActions::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ PayoutSettingsActions::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::result_message( $code );

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}
	}
}
