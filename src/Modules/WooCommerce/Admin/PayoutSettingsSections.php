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
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CreatorBillingDetails;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Small forms, shown only with `merchboard_manage_payouts` (ADR-058):
 * "Payouts" on the settings screen (holding period, the issuing business'
 * CVR number and the gift card plugin) and "Payout settlement start" and
 * "Master data for settlement documents" (ADR-062 K09) on the creator's
 * edit page (D3, D6). Read-only rendering; every
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
			PayoutException::INVALID_CVR          => array( __( 'Not saved: a CVR or SE number has eight digits. Leave the field empty when there is none.', 'merchboard' ), 'error' ),
			PayoutException::INVALID_BILLING_DETAILS => array( __( 'Not saved: a field is too long or contains line breaks or other control characters.', 'merchboard' ), 'error' ),
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
		self::render_company_cvr();
		$this->render_gift_card_provider();
	}

	/**
	 * The issuing business' CVR number on settlement documents (ADR-062 K09).
	 */
	private static function render_company_cvr(): void {
		$cvr = Settings::company_cvr();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( PayoutSettingsActions::COMPANY_CVR_ACTION ) . '">';
		wp_nonce_field( PayoutSettingsActions::COMPANY_CVR_ACTION );
		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row"><label for="merchboard-company-cvr">' . esc_html__( 'CVR number on settlement documents', 'merchboard' ) . '</label></th><td>';
		printf(
			'<input type="text" id="merchboard-company-cvr" name="%1$s" value="%2$s" inputmode="numeric" pattern="\d{8}" maxlength="8" class="regular-text">',
			esc_attr( PayoutSettingsActions::FIELD_COMPANY_CVR ),
			esc_attr( $cvr )
		);
		echo '<p class="description">' . esc_html__( 'The CVR number of the business that issues the settlement documents. New documents show it with the shop\'s name and WooCommerce address; issued documents keep what they were issued with. Leave it empty when it is not registered: the document then says so.', 'merchboard' ) . '</p>';

		if ( '' === $cvr ) {
			echo '<p class="description"><strong>' . esc_html__( 'Not registered: new settlement documents show the CVR number as not registered.', 'merchboard' ) . '</strong></p>';
		}

		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save CVR number', 'merchboard' ), 'secondary' );
		echo '</form>';
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
		$this->render_billing( $creator );
	}

	/**
	 * The creator's master data for settlement documents (ADR-062 K09):
	 * legal name, address and CVR/SE number, each optional. Missing data is
	 * listed; nothing is filled in.
	 *
	 * @param Creator $creator Creator being edited.
	 */
	private function render_billing( Creator $creator ): void {
		echo '<h2 id="merchboard-billing-details">' . esc_html__( 'Master data for settlement documents', 'merchboard' ) . '</h2>';
		self::render_notice( PayoutSettingsActions::BILLING_RESULT_ARG );

		try {
			$details = $this->settings->billing_details( $creator->id );
		} catch ( Throwable ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The master data could not be read.', 'merchboard' ) . '</p></div>';

			return;
		}

		echo '<p class="description">' . esc_html__( 'Shown on new settlement documents of this creator; issued documents keep what they were issued with. Fields that are left empty are shown as not registered. Without a legal name, the display name is used.', 'merchboard' ) . '</p>';

		$missing = array_filter(
			array(
				'' === $details->legal_name ? __( 'legal name', 'merchboard' ) : '',
				array() === $details->address_lines() ? __( 'address', 'merchboard' ) : '',
				'' === $details->cvr ? __( 'CVR/SE number', 'merchboard' ) : '',
			)
		);

		if ( array() !== $missing ) {
			/* translators: %s: list of missing fields. */
			echo '<p class="description"><strong>' . esc_html( sprintf( __( 'Not registered: %s.', 'merchboard' ), implode( ', ', $missing ) ) ) . '</strong></p>';
		}

		$labels = array(
			'legal_name' => __( 'Legal name (person or business)', 'merchboard' ),
			'address_1'  => __( 'Address', 'merchboard' ),
			'address_2'  => __( 'Address, line 2', 'merchboard' ),
			'postcode'   => __( 'Postcode', 'merchboard' ),
			'city'       => __( 'City', 'merchboard' ),
			'country'    => __( 'Country', 'merchboard' ),
			'cvr'        => __( 'CVR/SE number (if any)', 'merchboard' ),
		);
		$values = $details->values();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( PayoutSettingsActions::BILLING_ACTION ) . '">';
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator->id ) . '">';
		wp_nonce_field( PayoutSettingsActions::billing_nonce_action( $creator->id ) );
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( CreatorBillingDetails::FIELDS as $field => $max ) {
			$id    = 'merchboard-billing-' . str_replace( '_', '-', $field );
			$extra = 'cvr' === $field ? ' inputmode="numeric" pattern="\d{8}"' : '';

			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $labels[ $field ] ) . '</label></th><td>';
			printf(
				'<input type="text" id="%1$s" name="%2$s" value="%3$s" maxlength="%4$d" class="regular-text"%5$s>',
				esc_attr( $id ),
				esc_attr( PayoutSettingsActions::FIELD_BILLING_PREFIX . $field ),
				esc_attr( $values[ $field ] ),
				(int) $max,
				$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Constant attributes.
			);
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		submit_button( __( 'Save master data', 'merchboard' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * The outcome of the previous request.
	 *
	 * @param string $arg Query argument of the outcome code.
	 */
	private static function render_notice( string $arg = PayoutSettingsActions::RESULT_ARG ): void {
		// Read-only outcome code; nothing is changed by this request.
		$code   = isset( $_GET[ $arg ] ) ? sanitize_key( wp_unslash( $_GET[ $arg ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::result_message( $code );

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}
	}
}
