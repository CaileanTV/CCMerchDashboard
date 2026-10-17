<?php
/**
 * Download of a recalculation export file (ADR-062 pkt. 9, ADR-063).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\Recalculation\PayoutRecalculationExports;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php?action=merchboard_recalculation_export&export_id=…&file=…&_wpnonce=…`:
 * one file of a stored export. Order of checks: capability
 * `merchboard_manage_payouts` (403) → nonce bound to the export and file
 * (403) → the export passes its integrity check (409 otherwise; nothing is
 * delivered) → audited download. No AJAX, REST or `nopriv` route.
 */
final class PayoutRecalculationExportDownload {

	public const ACTION = 'merchboard_recalculation_export';

	/**
	 * Constructor.
	 *
	 * @param PayoutRecalculationExports $exports Exports.
	 * @param Logger                     $logger  Technical log.
	 */
	public function __construct( private PayoutRecalculationExports $exports, private Logger $logger ) {
	}

	/**
	 * Nonce action for one file of an export.
	 *
	 * @param int    $export_id Export id.
	 * @param string $file      File name.
	 */
	public static function nonce_action( int $export_id, string $file ): string {
		return self::ACTION . '_' . $export_id . '_' . $file;
	}

	/**
	 * The download link of one file.
	 *
	 * @param int    $export_id Export id.
	 * @param string $file      File name.
	 */
	public static function url( int $export_id, string $file ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => self::ACTION,
					'export_id' => $export_id,
					'file'      => $file,
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $export_id, $file )
		);
	}

	/**
	 * Hooks the handler (logged-in users only).
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * Sends the file of the current request, or an error page.
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

		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A downloaded file, sent as an attachment with nosniff.
		exit;
	}

	/**
	 * The response to a request: the file, or a status with a message.
	 *
	 * @param array<mixed> $input Unslashed GET data.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public function response( array $input ): array {
		if ( ! current_user_can( Capabilities::MANAGE_PAYOUTS ) ) {
			return self::failure( 403, __( 'Sorry, you are not allowed to manage payouts.', 'merchboard' ) );
		}

		$value     = $input['export_id'] ?? '';
		$export_id = is_scalar( $value ) && 1 === preg_match( '/^[1-9]\d{0,18}$/', (string) $value ) ? (int) $value : 0;
		$file      = is_scalar( $input['file'] ?? null ) ? (string) $input['file'] : '';
		$nonce     = is_scalar( $input['_wpnonce'] ?? null ) ? (string) $input['_wpnonce'] : '';

		if ( 0 === $export_id || 1 !== preg_match( '/^[A-Za-z0-9_.]{1,32}$/', $file ) || '' === $nonce || 1 !== wp_verify_nonce( $nonce, self::nonce_action( $export_id, $file ) ) ) {
			return self::failure( 403, __( 'The link you followed has expired. Please try again.', 'merchboard' ) );
		}

		try {
			$download = $this->exports->download( $export_id, $file );
		} catch ( PayoutException $e ) {
			return match ( $e->reason() ) {
				PayoutException::EXPORT_INTEGRITY => self::failure( 409, __( 'The export does not match its manifest and checksums. It is not delivered; nothing was changed.', 'merchboard' ) ),
				PayoutException::NOT_AUTHORIZED   => self::failure( 403, __( 'Sorry, you are not allowed to manage payouts.', 'merchboard' ) ),
				default                           => self::failure( 404, __( 'The export or file does not exist.', 'merchboard' ) ),
			};
		} catch ( Throwable $e ) {
			$this->logger->error(
				'The recalculation export could not be delivered.',
				array(
					'export_id' => $export_id,
					'error'     => $e::class,
				)
			);

			return self::failure( 500, __( 'The export cannot be delivered. Please try again later.', 'merchboard' ) );
		}

		return array(
			'status'  => 200,
			'headers' => array(
				'Content-Type'           => $download['type'],
				'Content-Disposition'    => 'attachment; filename="' . $download['name'] . '"',
				'Cache-Control'          => 'no-store, private',
				'X-Content-Type-Options' => 'nosniff',
			),
			'body'    => $download['content'],
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
