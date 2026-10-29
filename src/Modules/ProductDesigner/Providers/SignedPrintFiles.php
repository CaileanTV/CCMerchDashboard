<?php
/**
 * Short-lived signed URLs of print files for Printful.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\ProductDesigner\Designs\SubmissionFiles;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-081 pkt. 4: Printful's mockup generator fetches the print file from a
 * public URL. `GET /merchboard/v1/designer-print/{version}/{side}?expires=…&signature=…`
 * answers without a login only with a valid HMAC (own key domain, derived
 * from the site's auth salt) that has not expired (at most one hour), and
 * only the print file of a pending or approved version. The answer is the
 * PNG itself with `no-store` and `nosniff`; anything else is 404 without
 * telling why.
 */
class SignedPrintFiles {

	public const ROUTE = '/designer-print/(?P<version>[1-9][0-9]{0,18})/(?P<side>[a-z0-9][a-z0-9-]{0,31})';

	public const TTL = 3600;

	private const MATCHED = '/merchboard/v1' . self::ROUTE;

	/**
	 * Constructor.
	 *
	 * @param DesignerProductRepository $products Designer products.
	 * @param DesignerFiles             $files    Files.
	 */
	public function __construct( private DesignerProductRepository $products, private DesignerFiles $files ) {
	}

	/**
	 * A signed URL of a version's print file, valid for an hour.
	 *
	 * @param int      $version_id Version.
	 * @param string   $side       Side.
	 * @param int|null $now        Now (tests).
	 */
	public static function url( int $version_id, string $side, ?int $now = null ): string {
		$expires = ( $now ?? time() ) + self::TTL;

		return add_query_arg(
			array(
				'expires'   => $expires,
				'signature' => self::signature( $version_id, $side, $expires ),
			),
			rest_url( 'merchboard/v1/designer-print/' . $version_id . '/' . $side )
		);
	}

	/**
	 * Whether a signature is valid now.
	 *
	 * @param int      $version_id Version.
	 * @param string   $side       Side.
	 * @param int      $expires    Expiry (Unix time).
	 * @param string   $signature  Signature.
	 * @param int|null $now        Now (tests).
	 */
	public static function valid( int $version_id, string $side, int $expires, string $signature, ?int $now = null ): bool {
		$now ??= time();

		return $expires >= $now && $expires <= $now + self::TTL && hash_equals( self::signature( $version_id, $side, $expires ), $signature );
	}

	/**
	 * Registers the public route and its binary answer.
	 */
	public function register_routes(): void {
		register_rest_route(
			'merchboard/v1',
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'serve' ),
				// Public by design: the signature is the authorization (ADR-081 pkt. 4).
				'permission_callback' => '__return_true',
				'args'                => array(),
			)
		);
		add_filter( 'rest_pre_serve_request', array( $this, 'send' ), 10, 2 );
	}

	/**
	 * The print file, or 404.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function serve( WP_REST_Request $request ): WP_REST_Response {
		$version   = (int) $request->get_param( 'version' );
		$side      = (string) $request->get_param( 'side' );
		$expires   = $request->get_param( 'expires' );
		$signature = $request->get_param( 'signature' );
		$missing   = new WP_REST_Response( array( 'code' => 'merchboard_not_found' ), 404 );

		if ( ! is_numeric( $expires ) || ! is_string( $signature ) || ! self::valid( $version, $side, (int) $expires, $signature ) ) {
			return $missing;
		}

		try {
			$row = $this->products->version( $version );
		} catch ( DatabaseException ) {
			return $missing;
		}

		if ( null === $row || ! in_array( $row['status'], array( DesignerProductRepository::PENDING, DesignerProductRepository::APPROVED ), true ) ) {
			return $missing;
		}

		$file = SubmissionFiles::decode( is_string( $row['files'] ) ? $row['files'] : null )['print'][ $side ] ?? null;
		$path = is_string( $file['file'] ?? null ) ? $this->files->path( $file['file'] ) : null;
		$body = null === $path ? false : file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of at most 25 MB.

		if ( false === $body ) {
			return $missing;
		}

		$response = new WP_REST_Response( $body, 200 );
		$response->set_headers(
			array(
				'Content-Type'           => 'image/png',
				'Cache-Control'          => 'private, no-store',
				'X-Content-Type-Options' => 'nosniff',
			)
		);

		return $response;
	}

	/**
	 * `rest_pre_serve_request`: sends a print file as the image itself.
	 *
	 * @param mixed $served Whether it was already served.
	 * @param mixed $result The response.
	 */
	public function send( mixed $served, mixed $result ): bool {
		$served = (bool) $served;

		if ( $served || ! $result instanceof WP_REST_Response || 200 !== $result->get_status() || self::MATCHED !== $result->get_matched_route() || 'image/png' !== ( $result->get_headers()['Content-Type'] ?? null ) ) {
			return $served;
		}

		$body = $result->get_data();

		if ( ! is_string( $body ) ) {
			return $served;
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A print file the server stored, sent as PNG with nosniff.

		return true;
	}

	/**
	 * The HMAC of a URL.
	 *
	 * @param int    $version_id Version.
	 * @param string $side       Side.
	 * @param int    $expires    Expiry.
	 */
	private static function signature( int $version_id, string $side, int $expires ): string {
		return hash_hmac( 'sha256', 'merchboard-designer-print-v1|' . $version_id . '|' . $side . '|' . $expires, hash( 'sha256', 'merchboard-designer-print|' . wp_salt( 'auth' ), true ) );
	}
}
