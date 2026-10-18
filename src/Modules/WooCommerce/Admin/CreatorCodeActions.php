<?php
/**
 * State-changing requests of the creator's discount code (admin-post.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeException;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeService;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Registering and ending a creator's own discount code from the Discount
 * code section of the creator's edit page (ADR-068). Order of checks for
 * every request:
 *
 * 1. capability `merchboard_manage_creator_pricing` (K4) → 403 otherwise;
 * 2. nonce, bound to the action AND the creator id → 403 otherwise;
 * 3. input: absint id and plain text only;
 * 4. CreatorCodeService, which checks the capability again and every rule
 *    (K1, K5), writes and audits.
 *
 * The outcome is reported as a code in the redirect URL (Post/Redirect/Get);
 * codes are whitelisted when rendered, and technical failures are logged
 * with safe context (never the code or an e-mail) and shown neutrally.
 */
final class CreatorCodeActions {

	public const REGISTER_ACTION = 'merchboard_register_creator_code';
	public const END_ACTION      = 'merchboard_end_creator_code';
	public const RESULT_ARG      = 'merchboard_code_result';

	/**
	 * Every admin-post action of the section.
	 */
	private const ACTIONS = array( self::REGISTER_ACTION, self::END_ACTION );

	/**
	 * Constructor.
	 *
	 * @param CreatorCodeService $service Registrations.
	 * @param Logger             $logger  Technical log.
	 */
	public function __construct( private CreatorCodeService $service, private Logger $logger ) {
	}

	/**
	 * Nonce action of an admin-post action for one creator.
	 *
	 * @param string $action     One of the *_ACTION constants.
	 * @param int    $creator_id Creator id.
	 */
	public static function nonce_action( string $action, int $creator_id ): string {
		return $action . '_' . $creator_id;
	}

	/**
	 * Registers the admin-post handlers.
	 */
	public function register(): void {
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( $this, 'handle_request' ) );
		}
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_request(): void {
		// The nonce is verified in handle() before any input is used.
		wp_safe_redirect( $this->handle( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field sanitized in handle().
		exit;
	}

	/**
	 * Handles a request.
	 *
	 * @param array<mixed> $input Unslashed request data.
	 * @return string Redirect URL.
	 */
	public function handle( array $input ): string {
		if ( ! current_user_can( Capabilities::MANAGE_CREATOR_PRICING ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage creator discount codes.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		$action     = self::scalar( $input, 'action' );
		$creator_id = absint( self::scalar( $input, 'creator_id' ) );
		$nonce      = self::scalar( $input, '_wpnonce' );

		if ( ! in_array( $action, self::ACTIONS, true )
			|| '' === $nonce || false === wp_verify_nonce( $nonce, self::nonce_action( $action, $creator_id ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		try {
			if ( self::REGISTER_ACTION === $action ) {
				$code = $this->service->register( $creator_id, sanitize_text_field( self::scalar( $input, 'code' ) ) );
			} else {
				$this->service->end( $creator_id );
				$code = 'code_ended';
			}
		} catch ( CreatorCodeException $e ) {
			$code = $e->reason();
		} catch ( Throwable $e ) {
			$this->logger->error(
				'Creator discount code administration failed.',
				array(
					'operation'  => $action,
					'creator_id' => $creator_id,
					'error'      => $e::class,
				)
			);
			$code = 'error';
		}

		return add_query_arg( array( self::RESULT_ARG => $code ), CreatorsAdminPage::edit_url( $creator_id ) ) . '#merchboard-discount-code';
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
