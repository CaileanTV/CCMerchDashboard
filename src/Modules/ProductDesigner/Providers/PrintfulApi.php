<?php
/**
 * The Printful API (catalog and mockup generator only).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use Closure;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-081 pkt. 1–2: the only Printful calls Merch Dashboard makes, on
 * `https://api.printful.com` with the private token as Bearer:
 *
 * - `GET /products` and `GET /categories` (the catalog search, review 30.15);
 * - `GET /products/{id}`;
 * - `GET /mockup-generator/printfiles/{id}`;
 * - `POST /mockup-generator/create-task/{id}` and `GET /mockup-generator/task?task_key=…`;
 * - a download of a file Printful returned (https, any host Printful names).
 *
 * The layout templates (`/mockup-generator/templates/{id}`) are no longer
 * read: their images are guide graphics, not product photos (review 30.15).
 * Every call uses `wp_safe_remote_*` (no private addresses) with a 15 s
 * timeout and no redirects; the HTTP function can be replaced in tests.
 * A 429 throws RATE_LIMITED with `Retry-After`; other statuses and
 * unreadable answers throw, naming the call and Printful's own message.
 * Nothing runs while Printful is switched off or has no token. The token
 * is never logged.
 */
class PrintfulApi {

	public const BASE = 'https://api.printful.com';

	private const TIMEOUT = 15;

	/**
	 * The HTTP call: (method, url, args) → WordPress response or WP_Error.
	 *
	 * @var Closure(string, string, array<string, mixed>): (array<string, mixed>|WP_Error)
	 */
	private Closure $http;

	/**
	 * Constructor.
	 *
	 * @param PrintfulToken $token The token.
	 * @param Closure|null  $http  HTTP call (tests); wp_safe_remote_request by default.
	 *
	 * @phpstan-param (Closure(string, string, array<string, mixed>): (array<string, mixed>|WP_Error))|null $http
	 */
	public function __construct( private PrintfulToken $token, ?Closure $http = null ) {
		$this->http = $http ?? static function ( string $method, string $url, array $args ): array|WP_Error {
			return wp_safe_remote_request( $url, array_merge( $args, array( 'method' => $method ) ) );
		};
	}

	/**
	 * Whether Printful may be used: switched on and a token stored.
	 */
	public function available(): bool {
		return Settings::printful_enabled() && $this->token->configured();
	}

	/**
	 * The catalog's products (`id`, `title`, `type_name`, `brand`,
	 * `model`, `image`, `main_category_id`, `is_discontinued`).
	 *
	 * @return array<mixed>
	 * @throws PrintfulException When the call fails.
	 */
	public function products(): array {
		return $this->call( 'GET', '/products' );
	}

	/**
	 * The catalog's categories (`categories` with `id`, `parent_id`, `title`).
	 *
	 * @return array<string, mixed>
	 * @throws PrintfulException When the call fails.
	 */
	public function categories(): array {
		return $this->call( 'GET', '/categories' );
	}

	/**
	 * A catalog product with its variants (`product`, `variants`).
	 *
	 * @param int $id Printful product.
	 * @return array<string, mixed>
	 * @throws PrintfulException When the call fails.
	 */
	public function product( int $id ): array {
		return $this->call( 'GET', '/products/' . $id );
	}

	/**
	 * The print files of a catalog product (`available_placements`, `printfiles`, `variant_printfiles`).
	 *
	 * @param int $id Printful product.
	 * @return array<string, mixed>
	 * @throws PrintfulException When the call fails.
	 */
	public function printfiles( int $id ): array {
		return $this->call( 'GET', '/mockup-generator/printfiles/' . $id );
	}

	/**
	 * Creates a mockup task (`task_key`, `status`).
	 *
	 * @param int                  $id   Printful product.
	 * @param array<string, mixed> $body `variant_ids`, `format`, `files`.
	 * @return array<string, mixed>
	 * @throws PrintfulException When the call fails.
	 */
	public function create_task( int $id, array $body ): array {
		return $this->call( 'POST', '/mockup-generator/create-task/' . $id, $body );
	}

	/**
	 * A mockup task (`status`, `error`, `mockups`).
	 *
	 * @param string $key Task key.
	 * @return array<string, mixed>
	 * @throws PrintfulException When the call fails.
	 */
	public function task( string $key ): array {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,190}$/', $key ) ) {
			throw new PrintfulException( PrintfulException::INVALID );
		}

		return $this->call( 'GET', '/mockup-generator/task?task_key=' . rawurlencode( $key ) );
	}

	/**
	 * A file Printful returned (a mockup): https only, at most
	 * the designer's file size.
	 *
	 * @param string $url URL from a Printful answer.
	 * @return string The file's bytes.
	 * @throws PrintfulException When it cannot be downloaded.
	 */
	public function download( string $url ): string {
		$this->require_available();

		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! is_string( wp_parse_url( $url, PHP_URL_HOST ) ) ) {
			throw new PrintfulException( PrintfulException::INVALID );
		}

		$response = ( $this->http )(
			'GET',
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => DesignerFiles::MAX_BYTES,
			)
		);

		if ( $response instanceof WP_Error || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			throw new PrintfulException( PrintfulException::FAILED );
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body || strlen( $body ) >= DesignerFiles::MAX_BYTES ) {
			throw new PrintfulException( PrintfulException::INVALID );
		}

		return $body;
	}

	/**
	 * Calls the API and returns `result`.
	 *
	 * @param string                    $method Method.
	 * @param string                    $path   Path (one of the approved endpoints).
	 * @param array<string, mixed>|null $body   JSON body.
	 * @return array<string, mixed>
	 * @throws PrintfulException When the call fails.
	 */
	private function call( string $method, string $path, ?array $body = null ): array {
		$this->require_available();

		$args = array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . (string) $this->token->get(),
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		$response = ( $this->http )( $method, self::BASE . $path, $args );
		$call     = $method . ' ' . (string) strtok( $path, '?' );

		if ( $response instanceof WP_Error ) {
			throw new PrintfulException( PrintfulException::FAILED, 0, $call );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$detail  = self::detail( $status, $decoded );

		if ( 429 === $status ) {
			$wait = wp_remote_retrieve_header( $response, 'retry-after' );

			throw new PrintfulException( PrintfulException::RATE_LIMITED, is_numeric( $wait ) ? max( 1, (int) $wait ) : 60, $call, $detail );
		}

		if ( 401 === $status || 403 === $status ) {
			throw new PrintfulException( PrintfulException::UNAUTHORIZED, 0, $call, $detail );
		}

		if ( 404 === $status ) {
			throw new PrintfulException( PrintfulException::NOT_FOUND, 0, $call, $detail );
		}

		if ( 200 !== $status || ! is_array( $decoded ) || ! is_array( $decoded['result'] ?? null ) ) {
			throw new PrintfulException( PrintfulException::FAILED, 0, $call, $detail );
		}

		return $decoded['result'];
	}

	/**
	 * The status and Printful's own message of a refused call (`error.message`
	 * or a text `result`), shortened and as plain text.
	 *
	 * @param int   $status  HTTP status.
	 * @param mixed $decoded Decoded answer.
	 */
	private static function detail( int $status, mixed $decoded ): string {
		$message = '';

		if ( is_array( $decoded ) ) {
			$error   = $decoded['error'] ?? null;
			$message = is_array( $error ) && is_string( $error['message'] ?? null ) ? $error['message'] : ( is_string( $decoded['result'] ?? null ) ? $decoded['result'] : '' );
		}

		return mb_substr( trim( 'HTTP ' . $status . ' ' . sanitize_text_field( $message ) ), 0, 300 );
	}

	/**
	 * Refuses while Printful is switched off or has no token.
	 *
	 * @throws PrintfulException When it is not available.
	 */
	private function require_available(): void {
		if ( ! $this->available() ) {
			throw new PrintfulException( PrintfulException::NOT_CONFIGURED );
		}
	}
}
