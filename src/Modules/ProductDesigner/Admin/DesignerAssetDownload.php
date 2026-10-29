<?php
/**
 * A designer file for the administrator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php?action=merchboard_designer_asset&asset=…&_wpnonce=…`: a
 * template layer or clipart (step 30.6), shown inline on the base products
 * page. Order of checks: capability `merchboard_manage_designer` (403) →
 * nonce bound to the asset (403) → a stored file (404). Sent as the image
 * with `nosniff` and `no-store`; never a public URL. Creators reach their
 * designer's files through the dashboard (step 30.8).
 */
final class DesignerAssetDownload {

	public const ACTION = 'merchboard_designer_asset';

	/**
	 * Constructor.
	 *
	 * @param DesignerAssetRepository $assets File records.
	 * @param DesignerFiles           $files  Files.
	 */
	public function __construct( private DesignerAssetRepository $assets, private DesignerFiles $files ) {
	}

	/**
	 * Nonce action of one asset.
	 *
	 * @param int $asset_id Asset id.
	 */
	public static function nonce_action( int $asset_id ): string {
		return self::ACTION . '_' . $asset_id;
	}

	/**
	 * The file's URL for an administrator.
	 *
	 * @param int $asset_id Asset id.
	 */
	public static function url( int $asset_id ): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'asset'    => $asset_id,
				'_wpnonce' => wp_create_nonce( self::nonce_action( $asset_id ) ),
			),
			admin_url( 'admin-post.php' )
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

		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- An image the server validated, sent with its type and nosniff.
		exit;
	}

	/**
	 * The response to a request: the image, or a status with a message.
	 *
	 * @param array<mixed> $input Unslashed GET data.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public function response( array $input ): array {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			return self::failure( 403, __( 'Sorry, you are not allowed to manage the product designer.', 'merchboard' ) );
		}

		$value    = $input['asset'] ?? '';
		$asset_id = is_scalar( $value ) && 1 === preg_match( '/^[1-9]\d{0,18}$/', (string) $value ) ? (int) $value : 0;
		$nonce    = is_scalar( $input['_wpnonce'] ?? null ) ? (string) $input['_wpnonce'] : '';

		if ( 0 === $asset_id || '' === $nonce || false === wp_verify_nonce( $nonce, self::nonce_action( $asset_id ) ) ) {
			return self::failure( 403, __( 'The link you followed has expired. Please try again.', 'merchboard' ) );
		}

		try {
			$asset = $this->assets->find( $asset_id );
		} catch ( DatabaseException ) {
			$asset = null;
		}

		$path = null === $asset || null !== $asset['creator_id'] ? null : $this->files->path( $asset['file'] );
		$body = null === $path ? false : file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of at most 25 MB.

		if ( null === $asset || false === $body ) {
			return self::failure( 404, __( 'The file no longer exists.', 'merchboard' ) );
		}

		return array(
			'status'  => 200,
			'headers' => array(
				'Content-Type'           => $asset['mime'],
				'Content-Disposition'    => 'inline',
				'Cache-Control'          => 'private, no-store',
				'X-Content-Type-Options' => 'nosniff',
			),
			'body'    => $body,
		);
	}

	/**
	 * A failed response.
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
