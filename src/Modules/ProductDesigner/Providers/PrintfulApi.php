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
 * - `GET /products/{id}`;
 * - `GET /mockup-generator/printfiles/{id}` and `GET /mockup-generator/templates/{id}`;
 * - `POST /mockup-generator/create-task/{id}` and `GET /mockup-generator/task?task_key=…`;
 * - a download of a file Printful returned (https, any host Printful names).
 *
 * Every call uses `wp_safe_remote_*` (no private addresses) with a 15 s
 * timeout and no redirects; the HTTP function can be replaced in tests.
 * A 429 throws RATE_LIMITED with `Retry-After`; other statuses and
 * unreadable answers throw. Nothing runs while Printful is switched off or
 * has no token. The token is never logged.
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
	 * The layout templates of a catalog product (`variant_mapping`, `templates`).
	 *
	 * @param int $id Printful product.
	 * @return array<string, mixed>
	 * @throws PrintfulException When the call fails.
	 */
	public function templates( int $id ): array {
		return $this->call( 'GET', '/mockup-generator/templates/' . $id );
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
	 * A file Printful returned (a template or a mockup): https only, at most
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

		if ( $response instanceof WP_Error ) {
			throw new PrintfulException( PrintfulException::FAILED );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $status ) {
			$wait = wp_remote_retrieve_header( $response, 'retry-after' );

			throw new PrintfulException( PrintfulException::RATE_LIMITED, is_numeric( $wait ) ? max( 1, (int) $wait ) : 60 );
		}

		if ( 401 === $status || 403 === $status ) {
			throw new PrintfulException( PrintfulException::UNAUTHORIZED );
		}

		if ( 404 === $status ) {
			throw new PrintfulException( PrintfulException::NOT_FOUND );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || ! is_array( $decoded ) || ! is_array( $decoded['result'] ?? null ) ) {
			throw new PrintfulException( PrintfulException::FAILED );
		}

		return $decoded['result'];
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
