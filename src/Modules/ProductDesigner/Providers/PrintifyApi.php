<?php
/**
 * The Printify API (the catalog only).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use Closure;
use MerchBoard\Core\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-087: the only Printify calls Merch Dashboard makes, all `GET` on
 * `https://api.printify.com/v1` with the token as a bearer token:
 *
 * - `/catalog/blueprints.json` and `/catalog/blueprints/{id}.json`;
 * - `/catalog/blueprints/{id}/print_providers.json`;
 * - `/catalog/blueprints/{id}/print_providers/{id}/variants.json`;
 * - `/catalog/print_providers.json`.
 *
 * Never products, uploads, mockups, orders or webhooks: the token needs no
 * more than `catalog.read` and `print_providers.read`. Every call uses
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
	 * Reads from the API and returns its JSON.
	 *
	 * @param string $path Path (one of the approved endpoints).
	 * @return array<mixed>
	 * @throws PrintifyException When the call fails.
	 */
	private function call( string $path ): array {
		if ( ! $this->available() ) {
			self::fail( PrintifyException::NOT_CONFIGURED );
		}

		$response = ( $this->http )(
			'GET',
			self::BASE . $path,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'user-agent'          => self::USER_AGENT,
				'limit_response_size' => self::MAX_ANSWER,
				'headers'             => array(
					'Authorization' => 'Bearer ' . (string) $this->account->token(),
					'Accept'        => 'application/json',
				),
			)
		);
		$call     = 'GET ' . $path;

		if ( $response instanceof WP_Error ) {
			self::fail( PrintifyException::FAILED, $call );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
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
