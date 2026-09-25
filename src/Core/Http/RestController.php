<?php
/**
 * REST controller base class.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core\Http;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\Creator;
use Throwable;
use WP_Error;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Shared rules for every `merchboard/v1` controller (docs/API.md):
 *
 * - routes are registered on `rest_api_init` with an explicit permission
 *   callback (never `__return_true` for private data);
 * - `/me` routes authorize with require_creator(), i.e. CreatorContext alone:
 *   401 when not logged in, 403 without creator access (including an
 *   inactive creator or a failed access check);
 * - errors are WP_Error with a `merchboard_` code, an HTTP status and a
 *   generic message; unexpected exceptions are logged and become a generic
 *   500 without internal details.
 */
abstract class RestController {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'merchboard/v1';

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Technical log.
	 */
	public function __construct( protected Logger $logger ) {
	}

	/**
	 * Registers the controller's routes (called on `rest_api_init`).
	 */
	abstract public function register_routes(): void;

	/**
	 * The current user's active creator, or the error that denies access.
	 *
	 * @param CreatorContext $context Creator access policy.
	 * @return Creator|WP_Error
	 */
	protected function require_creator( CreatorContext $context ): Creator|WP_Error {
		if ( ! is_user_logged_in() ) {
			return self::error( 'merchboard_unauthenticated', __( 'You must be logged in.', 'merchboard' ), 401 );
		}

		return $context->current() ?? self::error( 'merchboard_forbidden', __( 'You do not have access to this resource.', 'merchboard' ), 403 );
	}

	/**
	 * Requires a capability of the logged-in user.
	 *
	 * @param string $capability Capability (a Capabilities constant).
	 * @return true|WP_Error
	 */
	protected function require_capability( string $capability ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return self::error( 'merchboard_unauthenticated', __( 'You must be logged in.', 'merchboard' ), 401 );
		}

		return current_user_can( $capability ) ? true : self::error( 'merchboard_forbidden', __( 'You do not have access to this resource.', 'merchboard' ), 403 );
	}

	/**
	 * Runs an endpoint and turns unexpected failures into a logged, generic
	 * 500 response.
	 *
	 * @param string                                  $route    Route, for the log.
	 * @param callable(): (WP_REST_Response|WP_Error) $callback Endpoint body.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function run( string $route, callable $callback ): WP_REST_Response|WP_Error {
		try {
			return $callback();
		} catch ( DatabaseException $e ) {
			$this->logger->error(
				'REST request failed.',
				array_merge(
					$e->context(),
					array(
						'route'  => $route,
						'reason' => $e->getMessage(),
					)
				)
			);
		} catch ( Throwable $e ) {
			$this->logger->error(
				'REST request failed unexpectedly.',
				array(
					'route'     => $route,
					'exception' => $e,
				)
			);
		}

		return self::error( 'merchboard_internal_error', __( 'Something went wrong. Please try again later.', 'merchboard' ), 500 );
	}

	/**
	 * A REST error.
	 *
	 * @param string               $code    Error code (prefix `merchboard_`).
	 * @param string               $message Generic, translated message.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $data    Extra, non-sensitive data.
	 */
	protected static function error( string $code, string $message, int $status, array $data = array() ): WP_Error {
		return new WP_Error( $code, $message, array_merge( $data, array( 'status' => $status ) ) );
	}
}
