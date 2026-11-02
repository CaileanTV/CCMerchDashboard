<?php
/**
 * The small pictures of Printify's catalog for the administrator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use InvalidArgumentException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Providers\PrintifyCatalog;
use MerchBoard\Modules\ProductDesigner\Providers\PrintifyException;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-087: `admin-post.php?action=merchboard_printify_thumb&product=<id>` gives
 * a product's small picture. Printify's pictures are 2048 px PNGs of about
 * 4.5 MB, so the first request makes a JPEG of PrintifyCatalog::THUMB px and
 * keeps it in the designer's private folder. Only for
 * `merchboard_manage_designer`, with one nonce for the page.
 */
final class PrintifyThumbnails {

	public const ACTION = 'merchboard_printify_thumb';

	/**
	 * Constructor.
	 *
	 * @param PrintifyCatalog $catalog Printify's catalog.
	 */
	public function __construct( private PrintifyCatalog $catalog ) {
	}

	/**
	 * A product's small picture.
	 *
	 * @param int $product Product.
	 */
	public static function url( int $product ): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'product'  => $product,
				'_wpnonce' => wp_create_nonce( self::ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Registers the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_request(): void {
		// The nonce is verified in response() before any input is used.
		$response = $this->response( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field validated in response().

		if ( 200 !== $response['status'] ) {
			wp_die( esc_html( $response['body'] ), '', array( 'response' => (int) $response['status'] ) );
		}

		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}

		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A JPEG the server made, sent with its type and nosniff.
		exit;
	}

	/**
	 * The response to a request.
	 *
	 * @param array<mixed> $input Unslashed query.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public function response( array $input ): array {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			return self::failure( 403, __( 'Sorry, you are not allowed to manage the product designer.', 'merchboard' ) );
		}

		$value   = $input['product'] ?? '';
		$product = is_scalar( $value ) && 1 === preg_match( '/^[1-9]\d{0,8}$/', (string) $value ) ? (int) $value : 0;
		$nonce   = is_scalar( $input['_wpnonce'] ?? null ) ? (string) $input['_wpnonce'] : '';

		if ( 0 === $product || '' === $nonce || false === wp_verify_nonce( $nonce, self::ACTION ) ) {
			return self::failure( 403, __( 'The link you followed has expired. Please try again.', 'merchboard' ) );
		}

		try {
			$body = $this->catalog->thumbnail( $product );
		} catch ( PrintifyException | DesignerException $e ) {
			/* translators: %s: the reason, a code. */
			return self::failure( 404, sprintf( __( 'The picture could not be fetched (%s).', 'merchboard' ), $e->reason() ) );
		} catch ( InvalidArgumentException ) {
			return self::failure( 404, __( 'The picture could not be read.', 'merchboard' ) );
		}

		return array(
			'status'  => 200,
			'headers' => array(
				'Content-Type'           => 'image/jpeg',
				'Content-Disposition'    => 'inline',
				'Cache-Control'          => 'private, max-age=86400',
				'X-Content-Type-Options' => 'nosniff',
			),
			'body'    => $body,
		);
	}

	/**
	 * A refused request.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Message.
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
