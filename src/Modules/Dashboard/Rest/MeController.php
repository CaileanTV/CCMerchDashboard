<?php
/**
 * Creator-scope REST routes: /me and /me/profile.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard\Rest;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorException;
use MerchBoard\Modules\Creators\CreatorProfileException;
use MerchBoard\Modules\Creators\CreatorProfileService;
use MerchBoard\Modules\Creators\SocialLinks;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /merchboard/v1/me`, `GET /merchboard/v1/me/profile` and
 * `PATCH /merchboard/v1/me/profile` (docs/API.md).
 *
 * There is no creator id in any route or parameter: the creator is always
 * the one CreatorContext resolves for the logged-in user, both in the
 * permission callback and again in the endpoint (not memoized), so a creator
 * deactivated between the two checks gets 403.
 */
final class MeController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param CreatorContext        $context  Creator access policy.
	 * @param CreatorProfileService $profiles Profile use cases.
	 * @param Logger                $logger   Technical log.
	 */
	public function __construct( private CreatorContext $context, private CreatorProfileService $profiles, Logger $logger ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/me',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_me' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
				'schema' => array( $this, 'me_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/me/profile',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_profile' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_profile' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => self::profile_args(),
				),
				'schema' => array( $this, 'profile_schema' ),
			)
		);
	}

	/**
	 * Permission callback of every route: an active creator for the current user.
	 *
	 * @return true|WP_Error
	 */
	public function permission(): bool|WP_Error {
		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * `GET /me`: `{ display_name, status }`.
	 */
	public function get_me(): WP_REST_Response|WP_Error {
		return $this->run(
			'/me',
			function (): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				return new WP_REST_Response(
					array(
						'display_name' => $creator->display_name,
						'status'       => $creator->status->value,
					),
					200
				);
			}
		);
	}

	/**
	 * `GET /me/profile`: `{ display_name, bio, social_links }`.
	 */
	public function get_profile(): WP_REST_Response|WP_Error {
		return $this->run(
			'/me/profile',
			function (): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				return new WP_REST_Response( $this->profiles->profile( $creator )->to_array(), 200 );
			}
		);
	}

	/**
	 * `PATCH /me/profile`: updates the whitelisted fields present in the body
	 * and returns the new profile. 400 with per-field error codes when any
	 * field is invalid (nothing is saved).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			'/me/profile',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				return $this->apply_update( $creator, self::whitelisted( $request ) );
			}
		);
	}

	/**
	 * Applies an update and maps rule violations to REST errors.
	 *
	 * @param Creator              $creator Creator.
	 * @param array<string, mixed> $input   Whitelisted input.
	 */
	private function apply_update( Creator $creator, array $input ): WP_REST_Response|WP_Error {
		try {
			return new WP_REST_Response( $this->profiles->update( $creator, $input )->to_array(), 200 );
		} catch ( CreatorProfileException $e ) {
			return self::error( 'merchboard_invalid_profile', __( 'Some profile fields are invalid.', 'merchboard' ), 400, array( 'fields' => $e->errors() ) );
		} catch ( CreatorException ) {
			return self::error( 'merchboard_forbidden', __( 'You do not have access to this resource.', 'merchboard' ), 403 );
		}
	}

	/**
	 * Only the whitelisted body fields; everything else (status, user_id,
	 * creator ids …) is ignored.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>
	 */
	private static function whitelisted( WP_REST_Request $request ): array {
		// JSON body (apiFetch) or form-encoded body; never query parameters.
		$body = (array) $request->get_json_params();

		if ( array() === $body ) {
			$body = $request->get_body_params();
		}

		$input = array();

		foreach ( array( 'display_name', 'bio', 'social_links' ) as $field ) {
			if ( array_key_exists( $field, $body ) ) {
				$input[ $field ] = $body[ $field ];
			}
		}

		return $input;
	}

	/**
	 * Argument schema of `PATCH /me/profile` (types only; lengths and URL
	 * rules are checked by CreatorProfileService).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function profile_args(): array {
		return array(
			'display_name' => array( 'type' => 'string' ),
			'bio'          => array( 'type' => 'string' ),
			'social_links' => array(
				'type'                 => 'object',
				'properties'           => array_fill_keys( SocialLinks::PLATFORMS, array( 'type' => 'string' ) ),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * JSON schema of `/me`.
	 *
	 * @return array<string, mixed>
	 */
	public function me_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'merchboard_me',
			'type'       => 'object',
			'properties' => array(
				'display_name' => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'status'       => array(
					'type'     => 'string',
					'enum'     => array( 'active', 'inactive' ),
					'readonly' => true,
				),
			),
		);
	}

	/**
	 * JSON schema of `/me/profile`.
	 *
	 * @return array<string, mixed>
	 */
	public function profile_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'merchboard_me_profile',
			'type'       => 'object',
			'properties' => array(
				'display_name' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 191,
				),
				'bio'          => array(
					'type'      => 'string',
					'maxLength' => CreatorProfileService::BIO_MAX_LENGTH,
				),
				'social_links' => array(
					'type'       => 'object',
					'properties' => array_fill_keys(
						SocialLinks::PLATFORMS,
						array(
							'type'      => 'string',
							'format'    => 'uri',
							'maxLength' => SocialLinks::MAX_LENGTH,
						)
					),
				),
			),
		);
	}
}
