<?php
/**
 * The PDF of a settlement document for administrators (admin-post.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocuments;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementPdf;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php?action=merchboard_settlement_pdf&payout_id=…&_wpnonce=…`:
 * the settlement document of a completed payout as a PDF (SettlementPdf).
 * Read only. Order of checks: capability `merchboard_manage_payouts` (403)
 * → nonce bound to the payout (403) → a document for the payout (404). A
 * document that does not add up gives no PDF (500, logged by class only);
 * nothing is changed. No AJAX, REST or `nopriv` route.
 */
final class SettlementPdfDownload {

	public const ACTION = 'merchboard_settlement_pdf';

	/**
	 * Constructor.
	 *
	 * @param SettlementDocuments $documents Documents.
	 * @param SettlementPdf       $pdf       PDF renderer.
	 * @param Logger              $logger    Technical log.
	 */
	public function __construct( private SettlementDocuments $documents, private SettlementPdf $pdf, private Logger $logger ) {
	}

	/**
	 * Nonce action for one payout.
	 *
	 * @param int $payout_id Payout id.
	 */
	public static function nonce_action( int $payout_id ): string {
		return self::ACTION . '_' . $payout_id;
	}

	/**
	 * The download link of a payout's document.
	 *
	 * @param int $payout_id Payout id.
	 */
	public static function url( int $payout_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => self::ACTION,
					'payout_id' => $payout_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $payout_id )
		);
	}

	/**
	 * Hooks the handler (logged-in users only).
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * Sends the PDF of the current request, or an error page.
	 */
	public function handle_request(): void {
		// The nonce is verified in response() before any input is used.
		$response = $this->response( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field validated in response().

		if ( 200 !== $response['status'] ) {
			wp_die( esc_html( $response['body'] ), '', array( 'response' => (int) $response['status'] ) );
		}

		nocache_headers();

		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}

		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A PDF file, sent as application/pdf with nosniff.
		exit;
	}

	/**
	 * The response to a request: the PDF, or a status with a message.
	 *
	 * @param array<mixed> $input Unslashed GET data.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public function response( array $input ): array {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			return self::failure( 403, __( 'Sorry, you are not allowed to manage payouts.', 'merchboard' ) );
		}

		$value     = $input['payout_id'] ?? '';
		$payout_id = is_scalar( $value ) && 1 === preg_match( '/^[1-9]\d{0,18}$/', (string) $value ) ? (int) $value : 0;
		$nonce     = is_scalar( $input['_wpnonce'] ?? null ) ? (string) $input['_wpnonce'] : '';

		if ( 0 === $payout_id || '' === $nonce || 1 !== wp_verify_nonce( $nonce, self::nonce_action( $payout_id ) ) ) {
			return self::failure( 403, __( 'The link you followed has expired. Please try again.', 'merchboard' ) );
		}

		try {
			$shown = $this->documents->for_payout( $payout_id );

			if ( null === $shown ) {
				return self::failure( 404, __( 'This payout has no settlement document.', 'merchboard' ) );
			}

			$pdf = $this->pdf->render( $shown );
		} catch ( Throwable $e ) {
			$this->logger->error(
				'The settlement document PDF could not be made.',
				array(
					'payout_id' => $payout_id,
					'error'     => $e::class,
				)
			);

			return self::failure( 500, __( 'The settlement document cannot be shown. Please try again later.', 'merchboard' ) );
		}

		return array(
			'status'  => 200,
			'headers' => SettlementPdf::headers( $shown['document'] ),
			'body'    => $pdf,
		);
	}

	/**
	 * A failed response.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Message for the error page.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function failure( int $status, string $message ): array {
		return array(
			'status'  => $status,
			'headers' => array(),
			'body'    => $message,
		);
	}
}
