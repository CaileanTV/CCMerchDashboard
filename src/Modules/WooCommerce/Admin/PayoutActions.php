<?php
/**
 * State-changing requests of the admin page "Payouts" (admin-post.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutDecisions;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutGiftCards;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Rejects a pending payout, registers it as transferred (ADR-055) or
 * registers the gift card of a gift card payout (ADR-057).
 * Order of checks: capability `merchboard_manage_payouts` (403) → nonce
 * bound to the action and the payout (403) → input → PayoutDecisions,
 * which checks the capability again and runs the decision under the
 * creator's lock, in one transaction with audit. Completing requires the
 * confirmation that the transfer was made outside Merch Dashboard; nothing
 * here pays anything. Post/Redirect/Get to the payout with a whitelisted
 * outcome code. No AJAX, REST or `nopriv` route.
 */
final class PayoutActions {

	public const REJECT_ACTION = 'merchboard_reject_payout';

	public const COMPLETE_ACTION = 'merchboard_complete_payout';

	/**
	 * Registers a gift card on a pending gift card payout (ADR-057).
	 */
	public const GIFT_CARD_ACTION = 'merchboard_register_payout_gift_card';

	/**
	 * Outcome code when the transfer was not confirmed.
	 */
	public const NOT_CONFIRMED = 'not_confirmed';

	/**
	 * Constructor.
	 *
	 * @param PayoutDecisions      $decisions  Decisions.
	 * @param Logger               $logger     Technical log.
	 * @param PayoutGiftCards|null $gift_cards Gift card registration; null = not available.
	 */
	public function __construct( private PayoutDecisions $decisions, private Logger $logger, private ?PayoutGiftCards $gift_cards = null ) {
	}

	/**
	 * Nonce action of an action for one payout.
	 *
	 * @param string $action     REJECT_ACTION or COMPLETE_ACTION.
	 * @param int    $payout_id Payout id.
	 */
	public static function nonce_action( string $action, int $payout_id ): string {
		return $action . '_' . $payout_id;
	}

	/**
	 * Hooks the handlers (logged-in users only).
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::REJECT_ACTION, array( $this, 'handle_request' ) );
		add_action( 'admin_post_' . self::COMPLETE_ACTION, array( $this, 'handle_request' ) );
		add_action( 'admin_post_' . self::GIFT_CARD_ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * Handles the current request and redirects.
	 */
	public function handle_request(): void {
		// The nonce is verified in handle() before any input is used.
		wp_safe_redirect( $this->handle( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field validated in handle().
		exit;
	}

	/**
	 * Handles a request.
	 *
	 * @param array<mixed> $input Unslashed POST data.
	 * @return string Redirect URL.
	 */
	public function handle( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage payouts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$action    = self::scalar( $input, 'action' );
		$payout_id = 1 === preg_match( '/^[1-9]\d{0,18}$/', self::scalar( $input, 'payout_id' ) ) ? (int) self::scalar( $input, 'payout_id' ) : 0;
		$nonce     = self::scalar( $input, '_wpnonce' );

		if ( ! in_array( $action, array( self::REJECT_ACTION, self::COMPLETE_ACTION, self::GIFT_CARD_ACTION ), true ) || 0 === $payout_id
			|| '' === $nonce || 1 !== wp_verify_nonce( $nonce, self::nonce_action( $action, $payout_id ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		if ( self::COMPLETE_ACTION === $action && '1' !== self::scalar( $input, 'confirm_transfer' ) ) {
			return PayoutsPage::url( array( 'payout' => $payout_id, 'result' => self::NOT_CONFIRMED ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}

		try {
			if ( self::REJECT_ACTION === $action ) {
				$this->decisions->reject( $payout_id, sanitize_textarea_field( self::scalar( $input, 'reason' ) ) );
				$code = 'rejected';
			} elseif ( self::GIFT_CARD_ACTION === $action ) {
				// The code is only passed on: never logged, stored or put in the redirect.
				$code = null === $this->gift_cards ? PayoutException::GIFT_CARDS_UNAVAILABLE : 'gift_card_registered';
				$this->gift_cards?->register( $payout_id, sanitize_text_field( self::scalar( $input, 'gift_card_code' ) ) );
			} else {
				$this->decisions->complete( $payout_id, sanitize_text_field( self::scalar( $input, 'reference' ) ) );
				$code = 'completed';
			}
		} catch ( PayoutException $e ) {
			$code = $e->reason();
		} catch ( Throwable $e ) {
			$this->logger->error(
				'Payout administration failed.',
				array(
					'operation' => $action,
					'payout_id' => $payout_id,
					'error'     => $e::class,
				)
			);
			$code = 'error';
		}

		return PayoutsPage::url( array( 'payout' => $payout_id, 'result' => $code ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * A scalar request field as string ('' when missing or not scalar).
	 *
	 * @param array<mixed> $input Request data.
	 * @param string       $key   Field.
	 */
	private static function scalar( array $input, string $key ): string {
		$value = $input[ $key ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}
}
