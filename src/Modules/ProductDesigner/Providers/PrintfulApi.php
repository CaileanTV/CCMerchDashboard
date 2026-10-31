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
 * - v2 (ADR-081, the owner's choice 2026-10-31, in beta, v1 is the fallback):
 *   `GET /v2/catalog-products` and `/v2/catalog-categories`, and per product
 *   `/v2/catalog-products/{id}`, `/catalog-variants`, `/availability`,
 *   `/mockup-styles` and `/mockup-templates`, `POST /v2/mockup-tasks` and
 *   `GET /v2/mockup-tasks?id=…`;
 * - a download of a file Printful returned (https, any host Printful names).
 *
 * The v1 layout templates (`/mockup-generator/templates/{id}`) are not read:
 * their images are guide graphics, not product photos (review 30.15). v2's
 * `mockup-templates` are read only for all-over products, whose sewing
 * pattern is what the creator designs on.
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
	 * Rows of a v2 page (Printful's largest).
	 */
	private const PAGE = 100;

	/**
	 * Most rows read of one v2 list (a t-shirt has 2 158 mockup templates).
	 */
	private const MAX_ROWS = 5000;

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
	 * The v2 catalog (`id`, `name`, `type`, `brand`, `model`, `image`,
	 * `main_category_id`, `is_discontinued`, `techniques`).
	 *
	 * @param string $region  Selling region.
	 * @param string $country Destination country.
	 * @return list<mixed>
	 * @throws PrintfulException When a call fails.
	 */
	public function catalog_products( string $region, string $country ): array {
		return $this->paged( '/v2/catalog-products?selling_region_name=' . rawurlencode( $region ) . '&destination_country=' . rawurlencode( $country ) );
	}

	/**
	 * The v2 catalog's categories (`id`, `parent_id`, `title`).
	 *
	 * @return list<mixed>
	 * @throws PrintfulException When a call fails.
	 */
	public function catalog_categories(): array {
		return $this->paged( '/v2/catalog-categories' );
	}

	/**
	 * A v2 catalog product in a selling region (`name`, `description`,
	 * `image`, `techniques`, `placements`, `product_options`).
	 *
	 * @param int    $id     Printful product.
	 * @param string $region Selling region.
	 * @return array<mixed>
	 * @throws PrintfulException When the call fails.
	 */
	public function catalog_product( int $id, string $region ): array {
		$answer = $this->call_v2( 'GET', '/v2/catalog-products/' . $id . '?selling_region_name=' . rawurlencode( $region ) );

		if ( ! is_array( $answer['data'] ?? null ) ) {
			throw new PrintfulException( PrintfulException::FAILED, 0, 'GET /v2/catalog-products/' . $id );
		}

		return $answer['data'];
	}

	/**
	 * A product's variants (`id`, `size`, `color`, `color_code`,
	 * `placement_dimensions` in inches).
	 *
	 * @param int $id Printful product.
	 * @return list<mixed>
	 * @throws PrintfulException When a call fails.
	 */
	public function catalog_variants( int $id ): array {
		return $this->paged( '/v2/catalog-products/' . $id . '/catalog-variants' );
	}

	/**
	 * A product's stock in a selling region (`catalog_variant_id`,
	 * `techniques` with `selling_regions` and their `availability`).
	 *
	 * @param int    $id     Printful product.
	 * @param string $region Selling region.
	 * @return list<mixed>
	 * @throws PrintfulException When a call fails.
	 */
	public function availability( int $id, string $region ): array {
		return $this->paged( '/v2/catalog-products/' . $id . '/availability?selling_region_name=' . rawurlencode( $region ) );
	}

	/**
	 * A product's placements with their print size and mockup styles.
	 *
	 * @param int    $id     Printful product.
	 * @param string $region Selling region.
	 * @return list<mixed>
	 * @throws PrintfulException When a call fails.
	 */
	public function mockup_styles( int $id, string $region ): array {
		return $this->paged( '/v2/catalog-products/' . $id . '/mockup-styles?selling_region_name=' . rawurlencode( $region ) );
	}

	/**
	 * A product's templates with their print area in template pixels.
	 *
	 * @param int    $id     Printful product.
	 * @param string $region Selling region.
	 * @return list<mixed>
	 * @throws PrintfulException When a call fails.
	 */
	public function mockup_templates( int $id, string $region ): array {
		return $this->paged( '/v2/catalog-products/' . $id . '/mockup-templates?selling_region_name=' . rawurlencode( $region ) );
	}

	/**
	 * Creates a v2 mockup task. The file's shape must match the layer's
	 * position within 2 %; a cut-and-sew product needs its `stitch_color`.
	 *
	 * @param array<string, mixed> $body `format` and `products`.
	 * @return string The task's id.
	 * @throws PrintfulException When the call fails.
	 */
	public function create_mockup_task( array $body ): string {
		$answer = $this->call_v2( 'POST', '/v2/mockup-tasks', $body );
		$id     = is_array( $answer['data'] ?? null ) && is_array( $answer['data'][0] ?? null ) ? ( $answer['data'][0]['id'] ?? null ) : null;

		if ( ! is_numeric( $id ) ) {
			throw new PrintfulException( PrintfulException::INVALID, 0, 'POST /v2/mockup-tasks' );
		}

		return (string) (int) $id;
	}

	/**
	 * A v2 mockup task (`status`, `catalog_variant_mockups`, `failure_reasons`).
	 *
	 * @param string $id Task id.
	 * @return array<mixed>
	 * @throws PrintfulException When the call fails.
	 */
	public function mockup_task( string $id ): array {
		if ( 1 !== preg_match( '/^[0-9]{1,20}$/', $id ) ) {
			throw new PrintfulException( PrintfulException::INVALID );
		}

		$answer = $this->call_v2( 'GET', '/v2/mockup-tasks?id=' . $id );

		if ( ! is_array( $answer['data'] ?? null ) || ! is_array( $answer['data'][0] ?? null ) ) {
			throw new PrintfulException( PrintfulException::NOT_FOUND, 0, 'GET /v2/mockup-tasks' );
		}

		return $answer['data'][0];
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
		[ $status, $decoded, $call, $detail ] = $this->send( $method, $path, $body );

		if ( 200 !== $status || ! is_array( $decoded ) || ! is_array( $decoded['result'] ?? null ) ) {
			throw new PrintfulException( PrintfulException::FAILED, 0, $call, $detail );
		}

		return $decoded['result'];
	}

	/**
	 * Calls v2 and returns its whole answer (`data`, `paging`).
	 *
	 * @param string                    $method Method.
	 * @param string                    $path   Path (one of the approved endpoints).
	 * @param array<string, mixed>|null $body   JSON body.
	 * @return array<mixed>
	 * @throws PrintfulException When the call fails.
	 */
	private function call_v2( string $method, string $path, ?array $body = null ): array {
		[ $status, $decoded, $call, $detail ] = $this->send( $method, $path, $body );

		if ( $status < 200 || $status > 299 || ! is_array( $decoded ) ) {
			throw new PrintfulException( PrintfulException::FAILED, 0, $call, $detail );
		}

		return $decoded;
	}

	/**
	 * Every row of a v2 list, page by page.
	 *
	 * @param string $path Path, with or without a query.
	 * @return list<mixed>
	 * @throws PrintfulException When a call fails.
	 */
	private function paged( string $path ): array {
		$join = str_contains( $path, '?' ) ? '&' : '?';
		$rows = array();

		for ( $offset = 0; $offset < self::MAX_ROWS; $offset += self::PAGE ) {
			$answer = $this->call_v2( 'GET', $path . $join . 'limit=' . self::PAGE . '&offset=' . $offset );
			$page   = is_array( $answer['data'] ?? null ) ? array_values( $answer['data'] ) : array();
			$total  = is_array( $answer['paging'] ?? null ) && is_numeric( $answer['paging']['total'] ?? null ) ? (int) $answer['paging']['total'] : 0;
			$rows   = array_merge( $rows, $page );

			if ( array() === $page || count( $rows ) >= $total ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * One call: throws on what both API versions treat alike.
	 *
	 * @param string                    $method Method.
	 * @param string                    $path   Path (one of the approved endpoints).
	 * @param array<string, mixed>|null $body   JSON body.
	 * @return array{0: int, 1: mixed, 2: string, 3: string} Status, decoded answer, the call and Printful's message.
	 * @throws PrintfulException When the call fails.
	 */
	private function send( string $method, string $path, ?array $body = null ): array {
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

		return array( $status, $decoded, $call, $detail );
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
