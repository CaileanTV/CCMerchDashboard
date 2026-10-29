<?php
/**
 * The Gelato API (templates and template products only).
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
 * ADR-082 pkt. 1 and 5: the Gelato calls Merch Dashboard makes, on
 * `https://ecommerce.gelatoapis.com` with the key as `X-API-KEY`:
 *
 * - `GET /v1/templates/{templateId}`;
 * - `POST /v1/stores/{storeId}/products:create-from-template`;
 * - `GET /v1/stores/{storeId}/products/{productId}`;
 * - a download of a file Gelato returned (https, any host Gelato names).
 *
 * The approved catalog endpoints (`product.gelatoapis.com`) are not needed:
 * the template names the products, variants and print areas. Every call
 * uses `wp_safe_remote_*` with a 15 s timeout and no redirects; the HTTP
 * function can be replaced in tests. A 429 throws RATE_LIMITED. Nothing
 * runs while Gelato is switched off or has no key and store.
 */
class GelatoApi {

	public const BASE = 'https://ecommerce.gelatoapis.com';

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
	 * @param GelatoAccount $account Key and store.
	 * @param Closure|null  $http    HTTP call (tests); wp_safe_remote_request by default.
	 *
	 * @phpstan-param (Closure(string, string, array<string, mixed>): (array<string, mixed>|WP_Error))|null $http
	 */
	public function __construct( private GelatoAccount $account, ?Closure $http = null ) {
		$this->http = $http ?? static function ( string $method, string $url, array $args ): array|WP_Error {
			return wp_safe_remote_request( $url, array_merge( $args, array( 'method' => $method ) ) );
		};
	}

	/**
	 * Whether Gelato may be used: switched on, a key and a store stored.
	 */
	public function available(): bool {
		return Settings::gelato_enabled() && $this->account->configured();
	}

	/**
	 * A template made in the Gelato dashboard (`variants` with
	 * `variantOptions` and `imagePlaceholders`).
	 *
	 * @param string $id Template id.
	 * @return array<string, mixed>
	 * @throws GelatoException When the call fails.
	 */
	public function template( string $id ): array {
		return $this->call( 'GET', '/v1/templates/' . self::id( $id ) );
	}

	/**
	 * Creates a product in the store from a template (mockups follow in the background).
	 *
	 * @param array<string, mixed> $body `templateId`, `title`, `description`, `variants`.
	 * @return array<string, mixed>
	 * @throws GelatoException When the call fails.
	 */
	public function create_from_template( array $body ): array {
		return $this->call( 'POST', '/v1/stores/' . (string) $this->account->store() . '/products:create-from-template', $body );
	}

	/**
	 * A product of the store (`status`, `previewUrl`, `productImages`, `variants`).
	 *
	 * @param string $id Product id.
	 * @return array<string, mixed>
	 * @throws GelatoException When the call fails.
	 */
	public function product( string $id ): array {
		return $this->call( 'GET', '/v1/stores/' . (string) $this->account->store() . '/products/' . self::id( $id ) );
	}

	/**
	 * A file Gelato returned: https only, at most the designer's file size.
	 *
	 * @param string $url URL from a Gelato answer.
	 * @return string Bytes.
	 * @throws GelatoException When it cannot be downloaded.
	 */
	public function download( string $url ): string {
		$this->require_available();

		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! is_string( wp_parse_url( $url, PHP_URL_HOST ) ) ) {
			self::fail( GelatoException::INVALID );
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
			self::fail( GelatoException::FAILED );
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body || strlen( $body ) >= DesignerFiles::MAX_BYTES ) {
			self::fail( GelatoException::INVALID );
		}

		return $body;
	}

	/**
	 * Calls the API and returns its JSON object.
	 *
	 * @param string                    $method Method.
	 * @param string                    $path   Path.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @return array<string, mixed>
	 * @throws GelatoException When the call fails.
	 */
	private function call( string $method, string $path, ?array $body = null ): array {
		$this->require_available();

		$args = array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array(
				'X-API-KEY' => (string) $this->account->key(),
				'Accept'    => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		$response = ( $this->http )( $method, self::BASE . $path, $args );

		if ( $response instanceof WP_Error ) {
			self::fail( GelatoException::FAILED );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $status ) {
			$wait = wp_remote_retrieve_header( $response, 'retry-after' );

			throw new GelatoException( GelatoException::RATE_LIMITED, is_numeric( $wait ) ? max( 1, (int) $wait ) : 60 );
		}

		if ( 401 === $status || 403 === $status ) {
			self::fail( GelatoException::UNAUTHORIZED );
		}

		if ( 404 === $status ) {
			self::fail( GelatoException::NOT_FOUND );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status > 299 || ! is_array( $decoded ) ) {
			self::fail( GelatoException::FAILED );
		}

		return $decoded;
	}

	/**
	 * Refuses while Gelato is switched off or has no key and store.
	 *
	 * @throws GelatoException When it is not available.
	 */
	private function require_available(): void {
		if ( ! $this->available() ) {
			self::fail( GelatoException::NOT_CONFIGURED );
		}
	}

	/**
	 * An id for a path: letters, digits and `-`.
	 *
	 * @param string $id Id.
	 * @throws GelatoException When it is not an id.
	 */
	private static function id( string $id ): string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9-]{1,100}$/', $id ) ) {
			self::fail( GelatoException::INVALID );
		}

		return $id;
	}

	/**
	 * Fails with a reason.
	 *
	 * @param string $reason GelatoException reason.
	 * @throws GelatoException Always.
	 */
	private static function fail( string $reason ): never {
		throw new GelatoException( $reason );
	}
}
