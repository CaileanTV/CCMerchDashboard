<?php
/**
 * "Discount code" section on the creator's edit page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use DateTimeImmutable;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCode;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeDeviations;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeException;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's own discount code and its history (ADR-068, K3): the active
 * code with its WooCommerce coupon, a warning when the coupon is no longer
 * the registered one (K5), the form to register or change it, ending it, and every earlier
 * registration. Read-only rendering: every change goes through
 * CreatorCodeActions and CreatorCodeService. Shown only with
 * `merchboard_manage_creator_pricing` (K4).
 */
final class CreatorCodeSection {

	/**
	 * Constructor.
	 *
	 * @param CreatorCodeRepository $codes      Registrations.
	 * @param CreatorCodeDeviations $deviations K5 deviation check.
	 * @param Logger                $logger     Technical log.
	 */
	public function __construct( private CreatorCodeRepository $codes, private CreatorCodeDeviations $deviations, private Logger $logger ) {
	}

	/**
	 * Registers the section on the creator's edit page, after the pricing.
	 */
	public function register(): void {
		add_action( 'merchboard_creators_edit_sections', array( $this, 'render' ), 9 );
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'warning'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'code_registered'                          => array( __( 'Discount code registered. It applies to orders created from now on; earlier orders are not changed.', 'merchboard' ), 'success' ),
			'code_changed'                             => array( __( 'Discount code changed. The earlier code was ended; orders created before now keep their decision.', 'merchboard' ), 'success' ),
			'code_ended'                               => array( __( 'Discount code ended. Orders created from now on with this code count normally; earlier orders keep their decision.', 'merchboard' ), 'success' ),
			CreatorCodeException::UNCHANGED            => array( __( 'Nothing was changed: this code is already the creator\'s active code.', 'merchboard' ), 'info' ),
			CreatorCodeException::INVALID_CODE         => array( __( 'Not saved: enter a coupon code of at most 191 characters.', 'merchboard' ), 'error' ),
			CreatorCodeException::COUPON_NOT_FOUND     => array( __( 'Not saved: there is no WooCommerce coupon with this code.', 'merchboard' ), 'error' ),
			CreatorCodeException::GIFT_CARD_CODE       => array( __( 'Not saved: this is a gift card code, not a discount code.', 'merchboard' ), 'error' ),
			CreatorCodeException::GIFT_CARD_UNVERIFIED => array( __( 'Not saved: it could not be checked whether this is a gift card code. Try again later.', 'merchboard' ), 'error' ),
			CreatorCodeException::CODE_TAKEN           => array( __( 'Not saved: this code is the active code of another creator.', 'merchboard' ), 'error' ),
			CreatorCodeException::NO_ACTIVE_CODE       => array( __( 'The creator has no active discount code.', 'merchboard' ), 'info' ),
			CreatorCodeException::BUSY                 => array( __( 'Another change of this code is in progress. Nothing was changed; try again.', 'merchboard' ), 'error' ),
			CreatorCodeException::NOT_AUTHORIZED       => array( __( 'You are not allowed to manage creator discount codes.', 'merchboard' ), 'error' ),
			CreatorCodeException::CREATOR_NOT_FOUND    => array( __( 'The creator does not exist.', 'merchboard' ), 'error' ),
			'error'                                    => array( __( 'A technical error occurred. Nothing was changed; try again later.', 'merchboard' ), 'error' ),
			default                                    => null,
		};
	}

	/**
	 * Text of a deviation code.
	 *
	 * @param string $code CreatorCodeDeviations constant.
	 */
	public static function deviation_text( string $code ): string {
		return CreatorCodeDeviations::COUPON_MISSING === $code
			? __( 'The WooCommerce coupon with this code no longer exists.', 'merchboard' )
			: __( 'The code now belongs to another WooCommerce coupon than the registered one.', 'merchboard' );
	}

	/**
	 * Renders the section.
	 *
	 * @param Creator $creator Creator being edited.
	 */
	public function render( Creator $creator ): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATOR_PRICING ) ) {
			return;
		}

		echo '<hr><h2 id="merchboard-discount-code">' . esc_html__( 'Discount code', 'merchboard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The creator\'s own WooCommerce coupon, which lets the creator buy their own merchandise without paying for their profit. In an order that uses it, the creator\'s own products do not count in the creator\'s sales, profit or payouts, whoever placed the order; other creators\' products in the same order count normally. A code applies to orders created from its registration on; earlier orders are never changed.', 'merchboard' ) . '</p>';

		// Read-only display parameter; nothing is changed by this request.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$result = isset( $_GET[ CreatorCodeActions::RESULT_ARG ] ) ? self::result_message( sanitize_key( wp_unslash( $_GET[ CreatorCodeActions::RESULT_ARG ] ) ) ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( null !== $result ) {
			echo '<div class="notice notice-' . esc_attr( $result[1] ) . ' inline"><p>' . esc_html( $result[0] ) . '</p></div>';
		}

		try {
			$history = $this->codes->for_creator( $creator->id );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The creator discount codes could not be read.', array( 'error' => $e::class ) );
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The discount codes could not be read. Please try again later.', 'merchboard' ) . '</p></div>';
			return;
		}

		$active = null;

		foreach ( $history as $registration ) {
			if ( $registration->is_active() ) {
				$active = $registration;
			}
		}

		$this->render_active( $creator, $active );
		$this->render_form( $creator, $active );
		$this->render_history( $history );
	}

	/**
	 * The active code, its coupon and its deviations.
	 *
	 * @param Creator          $creator Creator.
	 * @param CreatorCode|null $active  Active registration.
	 */
	private function render_active( Creator $creator, ?CreatorCode $active ): void {
		if ( null === $active ) {
			echo '<p><strong>' . esc_html__( 'No active discount code.', 'merchboard' ) . '</strong></p>';
			return;
		}

		$coupon = get_edit_post_link( $active->coupon_id, 'raw' );

		/* translators: 1: discount code, 2: date. */
		echo '<p>' . esc_html( sprintf( __( 'Active code: %1$s, since %2$s.', 'merchboard' ), $active->code, self::date( $active->valid_from ) ) );

		if ( is_string( $coupon ) && '' !== $coupon ) {
			echo ' <a href="' . esc_url( $coupon ) . '">' . esc_html__( 'Open the coupon in WooCommerce', 'merchboard' ) . '</a>';
		}

		echo '</p>';

		foreach ( $this->deviations->of( $active ) as $deviation ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Check the coupon:', 'merchboard' ) . '</strong> ' . esc_html( self::deviation_text( $deviation ) ) . ' ' . esc_html__( 'Orders with the registered code are still excluded and are marked with a deviation. The registration is not ended automatically.', 'merchboard' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( CreatorCodeActions::END_ACTION ) . '">';
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator->id ) . '">';
		wp_nonce_field( CreatorCodeActions::nonce_action( CreatorCodeActions::END_ACTION, $creator->id ) );
		echo '<p><button type="submit" class="button">' . esc_html__( 'End code', 'merchboard' ) . '</button> <span class="description">' . esc_html__( 'Orders created from then on with this code count normally.', 'merchboard' ) . '</span></p>';
		echo '</form>';
	}

	/**
	 * The form to register or change the code.
	 *
	 * @param Creator          $creator Creator.
	 * @param CreatorCode|null $active  Active registration.
	 */
	private function render_form( Creator $creator, ?CreatorCode $active ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( CreatorCodeActions::REGISTER_ACTION ) . '">';
		echo '<input type="hidden" name="creator_id" value="' . esc_attr( (string) $creator->id ) . '">';
		wp_nonce_field( CreatorCodeActions::nonce_action( CreatorCodeActions::REGISTER_ACTION, $creator->id ) );
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="merchboard-creator-code">' . esc_html( null === $active ? __( 'Register code', 'merchboard' ) : __( 'Change code', 'merchboard' ) ) . '</label></th><td>';
		echo '<input type="text" id="merchboard-creator-code" name="code" class="regular-text" maxlength="191" autocomplete="off" required> ';
		echo '<button type="submit" class="button button-primary">' . esc_html( null === $active ? __( 'Register code', 'merchboard' ) : __( 'Change code', 'merchboard' ) ) . '</button>';
		echo '<p class="description">' . esc_html__( 'The code of an existing WooCommerce coupon. A change ends the current code now and registers the new one from now on.', 'merchboard' ) . '</p>';
		echo '</td></tr></table></form>';
	}

	/**
	 * Every registration, newest first.
	 *
	 * @phpstan-param list<CreatorCode> $history
	 *
	 * @param CreatorCode[] $history Registrations.
	 */
	private function render_history( array $history ): void {
		if ( array() === $history ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Discount code history', 'merchboard' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';

		foreach ( array( __( 'Code', 'merchboard' ), __( 'Coupon ID', 'merchboard' ), __( 'From', 'merchboard' ), __( 'To', 'merchboard' ), __( 'Registered by', 'merchboard' ), __( 'Ended by', 'merchboard' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $history as $registration ) {
			echo '<tr><td><code>' . esc_html( $registration->code ) . '</code></td>';
			echo '<td>' . esc_html( (string) $registration->coupon_id ) . '</td>';
			echo '<td>' . esc_html( self::date( $registration->valid_from ) ) . '</td>';
			echo '<td>' . esc_html( null === $registration->valid_to ? __( 'Active', 'merchboard' ) : self::date( $registration->valid_to ) ) . '</td>';
			echo '<td>' . esc_html( self::user_name( $registration->created_by ) ) . '</td>';
			echo '<td>' . esc_html( null === $registration->valid_to ? '' : self::user_name( $registration->ended_by ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * A user's display name, or a neutral text.
	 *
	 * @param int|null $user_id User.
	 */
	private static function user_name( ?int $user_id ): string {
		$user = null === $user_id ? false : get_userdata( $user_id );

		return false === $user ? __( 'Unknown user', 'merchboard' ) : (string) $user->display_name;
	}

	/**
	 * A UTC time in the site's time zone and format.
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function date( DateTimeImmutable $time ): string {
		$text = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time->getTimestamp() );

		return false === $text || '' === $text ? $time->format( 'Y-m-d H:i' ) : $text;
	}
}
