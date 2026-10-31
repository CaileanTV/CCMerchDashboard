<?php
/**
 * The Prodigi API (catalogue and mockups only).
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
 * ADR-084: the only Prodigi calls Merch Dashboard makes, on
 * `https://api.prodigi.com/v5` with the key as `X-API-Key`:
 *
 * - `GET /catalogue/search`, `/catalogue/products`, `/catalogue/categories`,
 *   `/catalogue/products/{id}` and `/catalogue/products/{id}/variants`;
 * - `POST /assets:ingest`, `GET` and `DELETE /assets/{id}`;
 * - `POST` and `DELETE /products`, `GET /products/{id}/variants`,
 *   `POST /products/{id}/assets`: a saved product exists only to make a
 *   mockup and is deleted again;
 * - `POST /assets/{id}/mockups:fromProduct`, `GET /assets/{id}/mockups/{mockupId}`;
 * - a download of a file Prodigi returned (https, any host Prodigi names).
 *
 * Never orders, quotes, sales channels, billing or account settings: Prodigi
 * produces nothing for us. Every call uses `wp_safe_remote_*` with a 15 s
 * timeout and no redirects; the HTTP function can be replaced in tests. A 429
 * throws RATE_LIMITED; a refused call names the call and Prodigi's own
 * message. Nothing runs while Prodigi is switched off or has no key.
 *
 * Written from Prodigi's OpenAPI file (2026-10-31) and not yet run against
 * Prodigi: there is no key until the owner sets the account up.
 */
class ProdigiApi {

	public const BASE = 'https://api.prodigi.com/v5';

	private const TIMEOUT = 15;

	/**
	 * Rows of a page (Prodigi's largest).
	 */
	private const PAGE = 100;

	/**
	 * Most rows read of one list.
	 */
	private const MAX_ROWS = 1000;

	/**
	 * The HTTP call: (method, url, args) → WordPress response or WP_Error.
	 *
	 * @var Closure(string, string, array<string, mixed>): (array<string, mixed>|WP_Error)
	 */
	private Closure $http;

	/**
	 * Constructor.
	 *
	 * @param ProdigiAccount $account The key.
	 * @param Closure|null   $http    HTTP call (tests); wp_safe_remote_request by default.
	 *
	 * @phpstan-param (Closure(string, string, array<string, mixed>): (array<string, mixed>|WP_Error))|null $http
	 */
	public function __construct( private ProdigiAccount $account, ?Closure $http = null ) {
		$this->http = $http ?? static function ( string $method, string $url, array $args ): array|WP_Error {
			return wp_safe_remote_request( $url, array_merge( $args, array( 'method' => $method ) ) );
		};
	}

	/**
	 * Whether Prodigi may be used: switched on and a key stored.
	 */
	public function available(): bool {
		return Settings::prodigi_enabled() && $this->account->configured();
	}

	/**
	 * The first page of a search of the catalogue.
	 *
	 * @param string $query Words.
	 * @return list<mixed>
	 * @throws ProdigiException When the call fails.
	 */
	public function search( string $query ): array {
		$answer = $this->call( 'GET', '/catalogue/search?q=' . rawurlencode( $query ) . '&limit=' . self::PAGE );

		return is_array( $answer['data'] ?? null ) ? array_values( $answer['data'] ) : array();
	}

	/**
	 * The catalogue's products in a category and its subcategories.
	 *
	 * @param string $category The category's id (its full slug).
	 * @return list<mixed>
	 * @throws ProdigiException When a call fails.
	 */
	public function products( string $category ): array {
		return $this->paged( '/catalogue/products?category=' . rawurlencode( $category ) );
	}

	/**
	 * The catalogue's categories (`id`, `name`, `parentId`).
	 *
	 * @return list<mixed>
	 * @throws ProdigiException When a call fails.
	 */
	public function categories(): array {
		return $this->paged( '/catalogue/categories' );
	}

	/**
	 * A catalogue product (`name`, `description`, `attributes`, `shipsTo`,
	 * `manufacturingRegions`).
	 *
	 * @param string $id Catalogue product.
	 * @return array<mixed>
	 * @throws ProdigiException When the call fails.
	 */
	public function product( string $id ): array {
		$answer = $this->call( 'GET', '/catalogue/products/' . self::id( $id ) );

		return is_array( $answer['data'] ?? null ) ? $answer['data'] : array();
	}

	/**
	 * A catalogue product's SKUs (`id`, `sku`, `attributes`,
	 * `attributeOptions`, `sizeLabel`, `printAreas`, `manufacturingRegions`).
	 *
	 * @param string $id Catalogue product.
	 * @return list<mixed>
	 * @throws ProdigiException When a call fails.
	 */
	public function variants( string $id ): array {
		return $this->paged( '/catalogue/products/' . self::id( $id ) . '/variants' );
	}

	/**
	 * Saves a file from a public URL as an asset.
	 *
	 * @param string $url  URL.
	 * @param string $name Name.
	 * @return string The asset's id.
	 * @throws ProdigiException When the call fails.
	 */
	public function ingest_asset( string $url, string $name ): string {
		return self::new_id(
			$this->call(
				'POST',
				'/assets:ingest',
				array(
					'url'  => $url,
					'name' => mb_substr( $name, 0, 200 ),
				)
			)
		);
	}

	/**
	 * An asset's status (`pending`, `processing`, `ready`, `failed`).
	 *
	 * @param string $id Asset.
	 * @throws ProdigiException When the call fails.
	 */
	public function asset_status( string $id ): string {
		$answer = $this->call( 'GET', '/assets/' . self::id( $id ) );

		return is_array( $answer['data'] ?? null ) && is_string( $answer['data']['status'] ?? null ) ? $answer['data']['status'] : '';
	}

	/**
	 * Deletes an asset.
	 *
	 * @param string $id Asset.
	 * @throws ProdigiException When the call fails.
	 */
	public function delete_asset( string $id ): void {
		$this->call( 'DELETE', '/assets/' . self::id( $id ) );
	}

	/**
	 * Saves a product to make its mockups from.
	 *
	 * @param array<string, mixed> $body `title`, `catalogueProductSlug`, `catalogueProductId`, `variants`.
	 * @return string The saved product's id.
	 * @throws ProdigiException When the call fails.
	 */
	public function create_product( array $body ): string {
		return self::new_id( $this->call( 'POST', '/products', $body ) );
	}

	/**
	 * A saved product's variants (`id`, `catalogueVariantId`, `attributes`).
	 *
	 * @param string $id Saved product.
	 * @return list<mixed>
	 * @throws ProdigiException When a call fails.
	 */
	public function product_variants( string $id ): array {
		return $this->paged( '/products/' . self::id( $id ) . '/variants' );
	}

	/**
	 * Binds an asset to a print area of a saved product.
	 *
	 * @param string $product    Saved product.
	 * @param string $asset      Asset.
	 * @param string $print_area Print area.
	 * @throws ProdigiException When the call fails.
	 */
	public function bind_asset( string $product, string $asset, string $print_area ): void {
		$this->call(
			'POST',
			'/products/' . self::id( $product ) . '/assets',
			array(
				'assetId'   => $asset,
				'printArea' => $print_area,
			)
		);
	}

	/**
	 * Deletes a saved product.
	 *
	 * @param string $id Saved product.
	 * @throws ProdigiException When the call fails.
	 */
	public function delete_product( string $id ): void {
		$this->call( 'DELETE', '/products/' . self::id( $id ) );
	}

	/**
	 * Starts a mockup of a saved product's variant.
	 *
	 * @param string $asset   Asset.
	 * @param string $product Saved product.
	 * @param string $variant Its variant.
	 * @return string The mockup's id.
	 * @throws ProdigiException When the call fails.
	 */
	public function create_mockup( string $asset, string $product, string $variant ): string {
		return self::new_id(
			$this->call(
				'POST',
				'/assets/' . self::id( $asset ) . '/mockups:fromProduct',
				array(
					'productId'        => $product,
					'productVariantId' => $variant,
				)
			)
		);
	}

	/**
	 * A mockup (`status`: pending, processing, completed or failed, `result.url`).
	 *
	 * @param string $asset  Asset.
	 * @param string $mockup Mockup.
	 * @return array<mixed>
	 * @throws ProdigiException When the call fails.
	 */
	public function mockup( string $asset, string $mockup ): array {
		$answer = $this->call( 'GET', '/assets/' . self::id( $asset ) . '/mockups/' . self::id( $mockup ) );

		return is_array( $answer['data'] ?? null ) ? $answer['data'] : array();
	}

	/**
	 * A file Prodigi returned: https only, at most the designer's file size.
	 *
	 * @param string $url URL from a Prodigi answer.
	 * @return string Bytes.
	 * @throws ProdigiException When it cannot be downloaded.
	 */
	public function download( string $url ): string {
		$this->require_available();

		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! is_string( wp_parse_url( $url, PHP_URL_HOST ) ) ) {
			self::fail( ProdigiException::INVALID );
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
			self::fail( ProdigiException::FAILED );
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body || strlen( $body ) >= DesignerFiles::MAX_BYTES ) {
			self::fail( ProdigiException::INVALID );
		}

		return $body;
	}

	/**
	 * Every row of a list, page by page.
	 *
	 * @param string $path Path, with or without a query.
	 * @return list<mixed>
	 * @throws ProdigiException When a call fails.
	 */
	private function paged( string $path ): array {
		$join = str_contains( $path, '?' ) ? '&' : '?';
		$rows = array();

		for ( $offset = 0; $offset < self::MAX_ROWS; $offset += self::PAGE ) {
			$answer = $this->call( 'GET', $path . $join . 'limit=' . self::PAGE . '&offset=' . $offset );
			$page   = is_array( $answer['data'] ?? null ) ? array_values( $answer['data'] ) : array();
			$rows   = array_merge( $rows, $page );

			// Without a total, the docs say to stop at an empty page; a short one is the last too.
			if ( count( $page ) < self::PAGE ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Calls the API and returns its JSON object.
	 *
	 * @param string                    $method Method.
	 * @param string                    $path   Path (one of the approved endpoints).
	 * @param array<string, mixed>|null $body   JSON body.
	 * @return array<mixed>
	 * @throws ProdigiException When the call fails.
	 */
	private function call( string $method, string $path, ?array $body = null ): array {
		$this->require_available();

		$args = array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array(
				'X-API-Key' => (string) $this->account->key(),
				'Accept'    => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		$response = ( $this->http )( $method, self::BASE . $path, $args );
		$call     = $method . ' ' . (string) strtok( $path, '?' );

		if ( $response instanceof WP_Error ) {
			self::fail( ProdigiException::FAILED, $call );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$detail  = self::detail( $status, $decoded );

		if ( 429 === $status ) {
			$wait = wp_remote_retrieve_header( $response, 'retry-after' );

			throw new ProdigiException( ProdigiException::RATE_LIMITED, is_numeric( $wait ) ? max( 1, (int) $wait ) : 60, $call, $detail );
		}

		if ( 401 === $status || 403 === $status ) {
			self::fail( ProdigiException::UNAUTHORIZED, $call, $detail );
		}

		if ( 404 === $status ) {
			self::fail( ProdigiException::NOT_FOUND, $call, $detail );
		}

		if ( $status < 200 || $status > 299 ) {
			self::fail( ProdigiException::FAILED, $call, $detail );
		}

		// A delete answers without a body.
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * The id of what a call created.
	 *
	 * @param array<mixed> $answer Answer.
	 * @throws ProdigiException When it has none.
	 */
	private static function new_id( array $answer ): string {
		$id = is_array( $answer['data'] ?? null ) && is_scalar( $answer['data']['id'] ?? null ) ? (string) $answer['data']['id'] : '';

		return self::id( $id );
	}

	/**
	 * The status and Prodigi's own message of a call, shortened and as plain text.
	 *
	 * @param int   $status  HTTP status.
	 * @param mixed $decoded Decoded answer.
	 */
	private static function detail( int $status, mixed $decoded ): string {
		$message = is_array( $decoded ) && is_string( $decoded['message'] ?? null ) ? $decoded['message'] : '';

		return mb_substr( trim( 'HTTP ' . $status . ' ' . sanitize_text_field( $message ) ), 0, 300 );
	}

	/**
	 * Refuses while Prodigi is switched off or has no key.
	 *
	 * @throws ProdigiException When it is not available.
	 */
	private function require_available(): void {
		if ( ! $this->available() ) {
			self::fail( ProdigiException::NOT_CONFIGURED );
		}
	}

	/**
	 * An id for a path, as Prodigi's own pattern has it.
	 *
	 * @param string $id Id.
	 * @throws ProdigiException When it is not an id.
	 */
	private static function id( string $id ): string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9~_-]{1,120}$/', $id ) ) {
			self::fail( ProdigiException::INVALID );
		}

		return $id;
	}

	/**
	 * Fails with a reason.
	 *
	 * @param string $reason ProdigiException reason.
	 * @param string $call   The call.
	 * @param string $detail Prodigi's message.
	 * @throws ProdigiException Always.
	 */
	private static function fail( string $reason, string $call = '', string $detail = '' ): never {
		throw new ProdigiException( $reason, 0, $call, $detail );
	}
}
