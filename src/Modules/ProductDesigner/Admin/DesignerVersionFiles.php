<?php
/**
 * A design version's print files and mockups for the administrator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\ProductDesigner\Designs\SubmissionFiles;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php?action=merchboard_designer_version_file&version=…&slot=…`
 * (step 30.10, ADR-080 pkt. 5): a print file or mockup of a submitted
 * version, shown in the approval queue and downloaded from the product and
 * the order. Order of checks: capability `merchboard_manage_designer`
 * (403) → nonce bound to version and slot (403) → a stored file of that
 * version (404). Sent with `nosniff` and `no-store`; a print file as a
 * download named after the product and side. Print files never get a
 * public URL.
 */
final class DesignerVersionFiles {

	public const ACTION = 'merchboard_designer_version_file';

	/**
	 * Constructor.
	 *
	 * @param DesignerProductRepository $products Designer products.
	 * @param DesignerFiles             $files    Files.
	 */
	public function __construct( private DesignerProductRepository $products, private DesignerFiles $files ) {
	}

	/**
	 * Nonce action of one file.
	 *
	 * @param int    $version_id Version.
	 * @param string $slot       Slot.
	 */
	public static function nonce_action( int $version_id, string $slot ): string {
		return self::ACTION . '_' . $version_id . '_' . $slot;
	}

	/**
	 * The file's URL for an administrator.
	 *
	 * @param int    $version_id Version.
	 * @param string $slot       `print:<side>` or `mockup:<side>:<colour>`.
	 */
	public static function url( int $version_id, string $slot ): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'version'  => $version_id,
				'slot'     => rawurlencode( $slot ),
				'_wpnonce' => wp_create_nonce( self::nonce_action( $version_id, $slot ) ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * The slots of a version's stored files: print files first.
	 *
	 * @param string|null $files Stored JSON.
	 * @return array{print: array<string, string>, mockups: array<string, string>} Slot by side (print) or by "side:colour" (mockups).
	 */
	public static function slots( ?string $files ): array {
		$decoded = SubmissionFiles::decode( $files );
		$slots   = array(
			'print'   => array(),
			'mockups' => array(),
		);

		foreach ( array_keys( $decoded['print'] ) as $side ) {
			$slots['print'][ $side ] = SubmissionFiles::PRINT . ':' . $side;
		}

		foreach ( $decoded['mockups'] as $side => $colors ) {
			foreach ( array_keys( $colors ) as $color ) {
				$slots['mockups'][ $side . ':' . $color ] = SubmissionFiles::MOCKUP . ':' . $side . ':' . $color;
			}
		}

		return $slots;
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
	 * The response to a request: the file, or a status with a message.
	 *
	 * @param array<mixed> $input Unslashed GET data.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public function response( array $input ): array {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			return self::failure( 403, __( 'Sorry, you are not allowed to manage the product designer.', 'merchboard' ) );
		}

		$value   = $input['version'] ?? '';
		$version = is_scalar( $value ) && 1 === preg_match( '/^[1-9]\d{0,18}$/', (string) $value ) ? (int) $value : 0;
		$slot    = is_scalar( $input['slot'] ?? null ) ? rawurldecode( (string) $input['slot'] ) : '';
		$parts   = SubmissionFiles::slot( $slot );
		$nonce   = is_scalar( $input['_wpnonce'] ?? null ) ? (string) $input['_wpnonce'] : '';

		if ( 0 === $version || null === $parts || '' === $nonce || false === wp_verify_nonce( $nonce, self::nonce_action( $version, $slot ) ) ) {
			return self::failure( 403, __( 'The link you followed has expired. Please try again.', 'merchboard' ) );
		}

		try {
			$row = $this->products->version( $version );
		} catch ( DatabaseException ) {
			$row = null;
		}

		$files = SubmissionFiles::decode( is_string( $row['files'] ?? null ) ? $row['files'] : null );
		$file  = SubmissionFiles::PRINT === $parts[0] ? ( $files['print'][ $parts[1] ] ?? null ) : ( $files['mockups'][ $parts[1] ][ (string) $parts[2] ] ?? null );
		$path  = is_string( $file['file'] ?? null ) ? $this->files->path( $file['file'] ) : null;
		$body  = null === $path ? false : file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of at most 25 MB.

		if ( null === $row || ! is_array( $file ) || false === $body ) {
			return self::failure( 404, __( 'The file no longer exists.', 'merchboard' ) );
		}

		$disposition = 'inline';

		if ( SubmissionFiles::PRINT === $parts[0] ) {
			$name        = sanitize_file_name( sanitize_title( (string) $row['title'] ) . '-v' . (int) $row['number'] . '-' . $parts[1] . ( 'image/jpeg' === $file['mime'] ? '.jpg' : '.png' ) );
			$disposition = 'attachment; filename="' . $name . '"';
		}

		return array(
			'status'  => 200,
			'headers' => array(
				'Content-Type'           => (string) $file['mime'],
				'Content-Disposition'    => $disposition,
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
