<?php
/**
 * The Printify API (the catalog, and temporary products for photos).
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
 * ADR-087 and ADR-122: the only Printify calls Merch Dashboard makes, on
 * `https://api.printify.com/v1` with the token as a bearer token:
 *
 * - `GET /catalog/blueprints.json` and `/catalog/blueprints/{id}.json`;
 * - `GET /catalog/blueprints/{id}/print_providers.json`;
 * - `GET /catalog/blueprints/{id}/print_providers/{id}/variants.json`;
 * - `GET /catalog/print_providers.json` and `/catalog/print_providers/{id}.json`;
 * - `POST /uploads/images.json` (a marker or the transparent file);
 * - `POST /shops/{id}/products.json`, `GET` and `DELETE`
 *   `/shops/{id}/products/{id}.json`: a temporary product, never published;
 * - a picture or mockup on Printify's image hosts, without the token.
 *
 * Never publishing, orders or webhooks. Every call uses
 * `wp_safe_remote_*` with no redirects; the HTTP function can be replaced in
 * tests. A 429 throws RATE_LIMITED; a refused call names the call and
 * Printify's own message. Nothing runs while Printify is switched off or has
 * no token.
 */
class PrintifyApi {

	public const BASE = 'https://api.printify.com/v1';

	/**
	 * The product list is large.
	 */
	private const TIMEOUT = 30;

	/**
	 * The largest answer read (the product list was 5.5 MB on 2026-11-01).
	 */
	private const MAX_ANSWER = 33554432;

	/**
	 * Printify requires a user agent.
	 */
	private const USER_AGENT = 'MerchDashboard';

	/**
	 * The address of a product's picture.
	 */
	public const IMAGES = '#^https://images(?:-api)?\.printify\.com/[A-Za-z0-9/_.-]{1,200}(?:\?[A-Za-z0-9=&_.%-]{1,200})?$#D';

	/**
	 * A shop's id.
	 */
	public const SHOP = '/^\d{1,12}$/D';

	/**
	 * A product's or an upload's id.
	 */
	private const ID = '/^[0-9a-f]{24}$/D';

	/**
	 * The largest file uploaded (a marker is a few kB).
	 */
	private const MAX_UPLOAD = 1048576;

	/**
	 * The HTTP call: (method, url, args) → WordPress response or WP_Error.
	 *
	 * @var Closure(string, string, array<string, mixed>): (array<string, mixed>|WP_Error)
	 */
	private Closure $http;

	/**
	 * Constructor.
	 *
	 * @param PrintifyAccount $account The token.
	 * @param Closure|null    $http    HTTP call (tests); wp_safe_remote_request by default.
	 *
	 * @phpstan-param (Closure(string, string, array<string, mixed>): (array<string, mixed>|WP_Error))|null $http
	 */
	public function __construct( private PrintifyAccount $account, ?Closure $http = null ) {
		$this->http = $http ?? static function ( string $method, string $url, array $args ): array|WP_Error {
			return wp_safe_remote_request( $url, array_merge( $args, array( 'method' => $method ) ) );
		};
	}

	/**
	 * Whether Printify may be used: switched on and a token stored.
	 */
	public function available(): bool {
		return Settings::printify_enabled() && $this->account->configured();
	}

	/**
	 * Every product of the catalog (`id`, `title`, `brand`, `model`, `images`).
	 *
	 * @return list<mixed>
	 * @throws PrintifyException When the call fails.
	 */
	public function blueprints(): array {
		return array_values( $this->call( '/catalog/blueprints.json' ) );
	}

	/**
	 * A product of the catalog (`title`, `description`, `brand`, `images`).
	 *
	 * @param int $id Product.
	 * @return array<mixed>
	 * @throws PrintifyException When the call fails.
	 */
	public function blueprint( int $id ): array {
		return $this->call( '/catalog/blueprints/' . $id . '.json' );
	}

	/**
	 * Every producer (`id`, `title`, `location`).
	 *
	 * @return list<mixed>
	 * @throws PrintifyException When the call fails.
	 */
	public function print_providers(): array {
		return array_values( $this->call( '/catalog/print_providers.json' ) );
	}

	/**
	 * A producer with the products it makes (`blueprints`, each with `id`).
	 *
	 * @param int $id Producer.
	 * @return array<mixed>
	 * @throws PrintifyException When the call fails.
	 */
	public function print_provider( int $id ): array {
		return $this->call( '/catalog/print_providers/' . $id . '.json' );
	}

	/**
	 * A product's picture: https on Printify's image host only, sent without
	 * the token, at most the designer's file size.
	 *
	 * @param string $url URL from the product list.
	 * @return string Bytes.
	 * @throws PrintifyException When it cannot be fetched.
	 */
	public function image( string $url ): string {
		if ( ! $this->available() ) {
			self::fail( PrintifyException::NOT_CONFIGURED );
		}

		if ( 1 !== preg_match( self::IMAGES, $url ) ) {
			self::fail( PrintifyException::INVALID );
		}

		$response = ( $this->http )(
			'GET',
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'user-agent'          => self::USER_AGENT,
				'limit_response_size' => DesignerFiles::MAX_BYTES,
			)
		);

		if ( $response instanceof WP_Error || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			self::fail( PrintifyException::FAILED, 'GET ' . $url );
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body || strlen( $body ) >= DesignerFiles::MAX_BYTES ) {
			self::fail( PrintifyException::INVALID );
		}

		return $body;
	}

	/**
	 * Uploads a PNG. Printify gives the same id for the same bytes.
	 *
	 * @param string $name File name.
	 * @param string $png  Bytes.
	 * @return string The upload's id.
	 * @throws PrintifyException When the call fails or the answer has no id.
	 */
	public function upload( string $name, string $png ): string {
		if ( '' === $png || strlen( $png ) > self::MAX_UPLOAD || 1 !== preg_match( '/^[a-z0-9-]{1,60}\.png$/', $name ) ) {
			self::fail( PrintifyException::INVALID );
		}

		$answer = $this->call(
			'/uploads/images.json',
			'POST',
			array(
				'file_name' => $name,
				'contents'  => base64_encode( $png ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Printify takes the file as base64.
			)
		);

		return self::id_of( $answer, 'POST /uploads/images.json' );
	}

	/**
	 * Creates a product in a shop. It is never published.
	 *
	 * @param string       $shop    Shop.
	 * @param array<mixed> $product Title, description, blueprint, producer, variants and print areas.
	 * @return array<mixed> The product with its `id` and `images`.
	 * @throws PrintifyException When the call fails or the answer has no id.
	 */
	public function create_product( string $shop, array $product ): array {
		$path   = '/shops/' . self::shop_of( $shop ) . '/products.json';
		$answer = $this->call( $path, 'POST', $product );

		self::id_of( $answer, 'POST ' . $path );

		return $answer;
	}

	/**
	 * A product of a shop.
	 *
	 * @param string $shop Shop.
	 * @param string $id   Product.
	 * @return array<mixed>
	 * @throws PrintifyException When the call fails.
	 */
	public function product( string $shop, string $id ): array {
		return $this->call( '/shops/' . self::shop_of( $shop ) . '/products/' . self::checked( $id ) . '.json' );
	}

	/**
	 * Deletes a product of a shop; one already gone counts as deleted.
	 *
	 * @param string $shop Shop.
	 * @param string $id   Product.
	 * @throws PrintifyException When the call fails.
	 */
	public function delete_product( string $shop, string $id ): void {
		try {
			$this->call( '/shops/' . self::shop_of( $shop ) . '/products/' . self::checked( $id ) . '.json', 'DELETE' );
		} catch ( PrintifyException $e ) {
			if ( PrintifyException::NOT_FOUND !== $e->reason() ) {
				throw $e;
			}
		}
	}

	/**
	 * The producers of a product (`id`, `title`).
	 *
	 * @param int $blueprint Product.
	 * @return list<mixed>
	 * @throws PrintifyException When the call fails.
	 */
	public function blueprint_providers( int $blueprint ): array {
		return array_values( $this->call( '/catalog/blueprints/' . $blueprint . '/print_providers.json' ) );
	}

	/**
	 * A producer's variants of a product in stock (`id`, `title`, `options`,
	 * `placeholders`).
	 *
	 * @param int $blueprint Product.
	 * @param int $provider  Producer.
	 * @return list<mixed>
	 * @throws PrintifyException When the call fails.
	 */
	public function variants( int $blueprint, int $provider ): array {
		$answer = $this->call( '/catalog/blueprints/' . $blueprint . '/print_providers/' . $provider . '/variants.json' );

		return is_array( $answer['variants'] ?? null ) ? array_values( $answer['variants'] ) : array();
	}

	/**
	 * Calls the API and returns its JSON (an empty answer to a DELETE as an
	 * empty array).
	 *
	 * @param string            $path   Path (one of the approved endpoints).
	 * @param string            $method GET, POST or DELETE.
	 * @param array<mixed>|null $body   JSON body.
	 * @return array<mixed>
	 * @throws PrintifyException When the call fails.
	 */
	private function call( string $path, string $method = 'GET', ?array $body = null ): array {
		if ( ! $this->available() ) {
			self::fail( PrintifyException::NOT_CONFIGURED );
		}

		$args = array(
			'timeout'             => self::TIMEOUT,
			'redirection'         => 0,
			'user-agent'          => self::USER_AGENT,
			'limit_response_size' => self::MAX_ANSWER,
			'headers'             => array(
				'Authorization' => 'Bearer ' . (string) $this->account->token(),
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json;charset=utf-8';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		$response = ( $this->http )( $method, self::BASE . $path, $args );
		$call     = $method . ' ' . $path;

		if ( $response instanceof WP_Error ) {
			self::fail( PrintifyException::FAILED, $call );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = 'DELETE' === $method && '' === trim( $raw ) ? array() : json_decode( $raw, true );
		$detail  = self::detail( $status, $decoded );

		if ( 429 === $status ) {
			$wait = wp_remote_retrieve_header( $response, 'retry-after' );

			throw new PrintifyException( PrintifyException::RATE_LIMITED, is_numeric( $wait ) ? max( 1, (int) $wait ) : 60, $call, $detail );
		}

		if ( 401 === $status || 403 === $status ) {
			self::fail( PrintifyException::UNAUTHORIZED, $call, $detail );
		}

		if ( 404 === $status ) {
			self::fail( PrintifyException::NOT_FOUND, $call, $detail );
		}

		if ( $status < 200 || $status > 299 || ! is_array( $decoded ) ) {
			self::fail( PrintifyException::FAILED, $call, $detail );
		}

		return $decoded;
	}

	/**
	 * The id of a created product or upload.
	 *
	 * @param array<mixed> $answer The answer.
	 * @param string       $call   The call.
	 * @throws PrintifyException When it has none.
	 */
	private static function id_of( array $answer, string $call ): string {
		$id = is_string( $answer['id'] ?? null ) ? $answer['id'] : '';

		if ( 1 !== preg_match( self::ID, $id ) ) {
			self::fail( PrintifyException::FAILED, $call, 'no id' );
		}

		return $id;
	}

	/**
	 * A product's id, checked.
	 *
	 * @param string $id Id.
	 * @throws PrintifyException When it is not one.
	 */
	private static function checked( string $id ): string {
		if ( 1 !== preg_match( self::ID, $id ) ) {
			self::fail( PrintifyException::INVALID );
		}

		return $id;
	}

	/**
	 * A shop's id, checked.
	 *
	 * @param string $shop Shop.
	 * @throws PrintifyException When it is not one.
	 */
	private static function shop_of( string $shop ): string {
		if ( 1 !== preg_match( self::SHOP, $shop ) ) {
			self::fail( PrintifyException::NOT_CONFIGURED );
		}

		return $shop;
	}

	/**
	 * The status and Printify's own message of a call, shortened and as plain text.
	 *
	 * @param int   $status  HTTP status.
	 * @param mixed $decoded Decoded answer.
	 */
	private static function detail( int $status, mixed $decoded ): string {
		$message = is_array( $decoded ) && is_string( $decoded['message'] ?? null ) ? $decoded['message'] : '';

		return mb_substr( trim( 'HTTP ' . $status . ' ' . sanitize_text_field( $message ) ), 0, 300 );
	}

	/**
	 * Fails with a reason.
	 *
	 * @param string $reason PrintifyException reason.
	 * @param string $call   The call.
	 * @param string $detail Printify's message.
	 * @throws PrintifyException Always.
	 */
	private static function fail( string $reason, string $call = '', string $detail = '' ): never {
		throw new PrintifyException( $reason, 0, $call, $detail );
	}
}
