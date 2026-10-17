<?php
/**
 * Admin actions of payout recalculations (ADR-063).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutRecalculationExports;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutRecalculations;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Preview, approve, reject, reopen, execute and close a recalculation.
 * Order of checks: capability `merchboard_manage_payouts` (403) → nonce
 * bound to the action and the recalculation (403) → required confirmation
 * (approve, execute) → PayoutRecalculations, which checks the capability
 * again and validates the status and the basis. Each action is one step;
 * none of them triggers another. Post/Redirect/Get with a whitelisted
 * outcome code. No AJAX, REST or `nopriv` route.
 */
final class PayoutRecalculationActions {

	public const PREVIEW_ACTION = 'merchboard_recalculation_preview';

	public const APPROVE_ACTION = 'merchboard_recalculation_approve';

	public const REJECT_ACTION = 'merchboard_recalculation_reject';

	public const REOPEN_ACTION = 'merchboard_recalculation_reopen';

	public const EXECUTE_ACTION = 'merchboard_recalculation_execute';

	public const CLOSE_ACTION = 'merchboard_recalculation_close';

	/**
	 * Creates an export of a recalculation (ADR-062 pkt. 9).
	 */
	public const EXPORT_ACTION = 'merchboard_recalculation_create_export';

	/**
	 * Outcome code when a required confirmation was not ticked.
	 */
	public const NOT_CONFIRMED = 'not_confirmed';

	private const ACTIONS = array( self::PREVIEW_ACTION, self::APPROVE_ACTION, self::REJECT_ACTION, self::REOPEN_ACTION, self::EXECUTE_ACTION, self::CLOSE_ACTION, self::EXPORT_ACTION );

	/**
	 * Constructor.
	 *
	 * @param PayoutRecalculations            $recalculations Recalculations.
	 * @param Logger                          $logger         Technical log.
	 * @param PayoutRecalculationExports|null $exports        Exports; null = not available.
	 */
	public function __construct( private PayoutRecalculations $recalculations, private Logger $logger, private ?PayoutRecalculationExports $exports = null ) {
	}

	/**
	 * Nonce action of an action for one recalculation (0 for a new preview).
	 *
	 * @param string $action           One of the action constants.
	 * @param int    $recalculation_id Recalculation id.
	 */
	public static function nonce_action( string $action, int $recalculation_id ): string {
		return $action . '_' . $recalculation_id;
	}

	/**
	 * Hooks the handlers (logged-in users only).
	 */
	public function register(): void {
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( $this, 'handle_request' ) );
		}
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

		$action = self::scalar( $input, 'action' );
		$id     = 1 === preg_match( '/^[1-9]\d{0,18}$/', self::scalar( $input, 'recalculation_id' ) ) ? (int) self::scalar( $input, 'recalculation_id' ) : 0;
		$nonce  = self::scalar( $input, '_wpnonce' );

		if ( ! in_array( $action, self::ACTIONS, true ) || ( 0 === $id ) !== ( self::PREVIEW_ACTION === $action )
			|| '' === $nonce || 1 !== wp_verify_nonce( $nonce, self::nonce_action( $action, $id ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		if ( in_array( $action, array( self::APPROVE_ACTION, self::EXECUTE_ACTION ), true ) && '1' !== self::scalar( $input, 'confirm' ) ) {
			return PayoutRecalculationsPage::url( array( 'recalculation' => $id, 'result' => self::NOT_CONFIRMED ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}

		$note = sanitize_textarea_field( self::scalar( $input, 'note' ) );
		$code = $action;

		try {
			switch ( $action ) {
				case self::PREVIEW_ACTION:
					$id = $this->recalculations->preview();
					break;
				case self::APPROVE_ACTION:
					$this->recalculations->approve( $id, $note );
					break;
				case self::REJECT_ACTION:
					$this->recalculations->reject( $id, $note );
					break;
				case self::REOPEN_ACTION:
					$id = $this->recalculations->reopen( $id );
					break;
				case self::EXECUTE_ACTION:
					$this->recalculations->execute( $id );
					break;
				case self::EXPORT_ACTION:
					$group   = self::scalar( $input, 'group' );
					$creator = trim( self::scalar( $input, 'creator_id' ) );

					if ( null === $this->exports || ( '' !== $creator && 1 !== preg_match( '/^[1-9]\d{0,18}$/', $creator ) ) ) {
						$code = PayoutException::INVALID_FILTER;
						break;
					}

					$this->exports->create( $id, '' === $group ? null : $group, '' === $creator ? null : (int) $creator );
					break;
				default:
					$this->recalculations->close( $id, $note );
			}
		} catch ( PayoutException $e ) {
			$code = $e->reason();
		} catch ( Throwable $e ) {
			$this->logger->error(
				'Payout recalculation failed.',
				array(
					'operation'        => $action,
					'recalculation_id' => $id,
					'error'            => $e::class,
				)
			);
			$code = 'error';
		}

		return PayoutRecalculationsPage::url( 0 === $id ? array( 'result' => $code ) : array( 'recalculation' => $id, 'result' => $code ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
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
