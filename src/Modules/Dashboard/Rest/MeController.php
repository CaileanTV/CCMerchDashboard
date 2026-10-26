<?php
/**
 * Creator-scope REST routes: /me, /me/profile and /me/preferences.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard\Rest;

use DateTimeImmutable;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\Proposals\CategoryProposal;
use MerchBoard\Modules\Creators\Proposals\CategoryProposalException;
use MerchBoard\Modules\Creators\Proposals\CategoryProposals;
use MerchBoard\Modules\Creators\Proposals\ProposalField;
use MerchBoard\Modules\Creators\Proposals\ProposalFiles;
use MerchBoard\Modules\Creators\Proposals\SocialPlatforms;
use MerchBoard\Modules\Dashboard\ThemePreference;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /merchboard/v1/me`, the creator's profile (`/me/profile`, phase 28,
 * ADR-077) and `PATCH /merchboard/v1/me/preferences` (docs/API.md).
 *
 * The profile shows the creator's name and product category as they are and
 * the creator's proposals: every change (name, description, images, banner
 * link, social links) is a proposal an administrator approves.
 *
 * There is no creator id in any route or parameter: the creator is always
 * the one CreatorContext resolves for the logged-in user, both in the
 * permission callback and again in the endpoint (not memoized), so a creator
 * deactivated between the two checks gets 403.
 */
final class MeController extends RestController {

	/**
	 * The route of a proposed image (served as the image, not JSON).
	 */
	public const FILE_ROUTE = '/' . self::NAMESPACE . '/me/profile/files/(?P<id>\d+)';

	/**
	 * Text fields of `PATCH /me/profile` ('' for an image removes it).
	 */
	private const TEXT_FIELDS = array( 'display_name', 'description', 'banner_link', 'image', 'banner', 'banner_mobile' );

	/**
	 * Constructor.
	 *
	 * @param CreatorContext    $context   Creator access policy.
	 * @param CategoryProposals $proposals The creator's proposals and category.
	 * @param ProposalFiles     $files     Proposed images.
	 * @param ThemePreference   $themes    The user's dashboard theme.
	 * @param Logger            $logger    Technical log.
	 */
	public function __construct( private CreatorContext $context, private CategoryProposals $proposals, private ProposalFiles $files, private ThemePreference $themes, Logger $logger ) {
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
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/me/profile/files/(?P<field>image|banner|banner_mobile)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_file' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/me/profile/files/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_file' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/me/profile/proposals/(?P<field>[a-z_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'withdraw' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/me/preferences',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_preferences' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => self::preferences_args(),
				),
				'schema' => array( $this, 'preferences_schema' ),
			)
		);

		add_filter( 'rest_pre_serve_request', array( $this, 'serve_file' ), 10, 3 );
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
	 * `GET /me/profile`: the name, the fields that can be proposed, the
	 * category now, and the pending and rejected proposals.
	 */
	public function get_profile(): WP_REST_Response|WP_Error {
		return $this->with_creator( '/me/profile', fn( Creator $creator ): WP_REST_Response => $this->profile( $creator ) );
	}

	/**
	 * `PATCH /me/profile`: proposes the fields in the body (name, description,
	 * banner link, social links; '' for an image removes it) and returns the
	 * profile. 400 with per-field error codes when any field is invalid
	 * (nothing is stored).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			'/me/profile',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				try {
					$this->proposals->propose( $creator, self::proposal_input( self::body( $request ) ) );
				} catch ( CategoryProposalException $e ) {
					return self::refused( $e );
				}

				return $this->profile( $this->current( $creator ) );
			}
		);
	}

	/**
	 * `POST /me/profile/files/{image|banner|banner_mobile}`: proposes an
	 * uploaded image (multipart field `file`) and returns the profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function upload_file( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			'/me/profile/files/{field}',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				$field = ProposalField::tryFrom( (string) $request->get_param( 'field' ) );
				$files = $request->get_file_params();

				if ( null === $field || ! $field->is_file() ) {
					return self::error( 'merchboard_not_found', __( 'Not found.', 'merchboard' ), 404 );
				}

				try {
					$this->proposals->propose_image( $creator, $field, is_array( $files['file'] ?? null ) ? $files['file'] : array() );
				} catch ( CategoryProposalException $e ) {
					return CategoryProposalException::NOT_AUTHORIZED === $e->reason()
						? self::refused( $e )
						: self::error( 'merchboard_invalid_request', __( 'The image was not saved.', 'merchboard' ), 400, array( 'fields' => array( $field->value => self::code( $e->reason() ) ) ) );
				}

				return $this->profile( $creator );
			}
		);
	}

	/**
	 * `GET /me/profile/files/{id}`: one of the creator's proposed images that
	 * is still waiting, sent as the image itself (serve_file()); never another
	 * creator's.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function get_file( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			'/me/profile/files/{id}',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				$proposal = $this->proposals->find( (int) $request->get_param( 'id' ) );
				$file     = $proposal?->file();
				$path     = null === $file ? null : $this->files->path( $file );
				$body     = null === $path ? false : file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of at most 5 MB.

				if ( null === $proposal || null === $file || $proposal->creator_id !== $creator->id || false === $body ) {
					return self::error( 'merchboard_not_found', __( 'Not found.', 'merchboard' ), 404 );
				}

				$response = new WP_REST_Response( $body, 200 );
				$response->set_headers(
					array(
						'Content-Type'           => $file->mime,
						'Content-Disposition'    => 'inline',
						'Cache-Control'          => 'private, no-store',
						'X-Content-Type-Options' => 'nosniff',
					)
				);

				return $response;
			}
		);
	}

	/**
	 * `DELETE /me/profile/proposals/{field}`: withdraws the pending proposal
	 * of a field and returns the profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function withdraw( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			'/me/profile/proposals/{field}',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				$field = ProposalField::tryFrom( (string) $request->get_param( 'field' ) );

				if ( null === $field ) {
					return self::error( 'merchboard_not_found', __( 'Not found.', 'merchboard' ), 404 );
				}

				try {
					$this->proposals->withdraw( $creator, $field );
				} catch ( CategoryProposalException $e ) {
					return self::refused( $e );
				}

				return $this->profile( $creator );
			}
		);
	}

	/**
	 * `rest_pre_serve_request`: sends a successful response of the image
	 * route as the image itself instead of JSON. The REST server has already
	 * sent the response's headers (the image type, `no-store`, `nosniff`);
	 * every other response is left to the server.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param mixed           $served  Whether the request was already served (any type: other plugins' callbacks may return null).
	 * @param mixed           $result  The response.
	 * @param WP_REST_Request $request Request.
	 */
	public function serve_file( mixed $served, mixed $result, WP_REST_Request $request ): bool {
		$served = (bool) $served;

		if ( $served || ! $result instanceof WP_REST_Response || 'GET' !== $request->get_method() || 200 !== $result->get_status()
			|| self::FILE_ROUTE !== $result->get_matched_route() || ! in_array( $result->get_headers()['Content-Type'] ?? null, ProposalFiles::TYPES, true ) ) {
			return $served;
		}

		$body = $result->get_data();

		if ( ! is_string( $body ) ) {
			return $served;
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- An image the server validated, sent with its type and nosniff.

		return true;
	}

	/**
	 * `PATCH /me/preferences`: `{ "theme": "<theme>" | null }` saves the
	 * logged-in user's dashboard theme (ADR-044); null removes the explicit
	 * choice, so the dashboard follows the system preference again. Only the
	 * JSON or form body counts, never query parameters, and the user is always
	 * the current one. The value is validated again here, not only by the
	 * argument schema (which skips null). Returns `{ "theme": <theme|null> }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_preferences( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			'/me/preferences',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$body  = self::body( $request );
				$theme = $body['theme'] ?? null;

				if ( ! array_key_exists( 'theme', $body ) || ( null !== $theme && ! ThemePreference::is_theme( $theme ) ) ) {
					return self::error( 'merchboard_invalid_preferences', __( 'The theme is not valid.', 'merchboard' ), 400, array( 'fields' => array( 'theme' => 'invalid' ) ) );
				}

				$user_id = get_current_user_id();
				$this->themes->save( $user_id, is_string( $theme ) ? $theme : null );

				return new WP_REST_Response( array( 'theme' => $this->themes->for_user( $user_id ) ), 200 );
			}
		);
	}

	/**
	 * Runs an endpoint with the current user's creator (resolved again).
	 *
	 * @param string   $route    Route for the log.
	 * @param callable $callback Endpoint.
	 *
	 * @phpstan-param callable(Creator): (WP_REST_Response|WP_Error) $callback
	 */
	private function with_creator( string $route, callable $callback ): WP_REST_Response|WP_Error {
		return $this->run(
			$route,
			function () use ( $callback ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				return $creator instanceof WP_Error ? $creator : $callback( $creator );
			}
		);
	}

	/**
	 * The creator as stored now (after a proposal the name is unchanged, but
	 * read again for a consistent answer).
	 *
	 * @param Creator $creator Creator.
	 */
	private function current( Creator $creator ): Creator {
		$current = $this->context->current();

		return null !== $current && $current->id === $creator->id ? $current : $creator;
	}

	/**
	 * The profile as the dashboard shows it.
	 *
	 * @param Creator $creator Creator.
	 */
	private function profile( Creator $creator ): WP_REST_Response {
		$category = $this->proposals->category( $creator->id );
		$pending  = array();
		$rejected = array();

		foreach ( $this->proposals->pending( $creator->id ) as $field => $proposal ) {
			$pending[ $field ] = self::pending( $proposal );
		}

		foreach ( $this->proposals->rejected( $creator->id ) as $field => $proposal ) {
			$rejected[ $field ] = array(
				'id'         => $proposal->id,
				'reason'     => $proposal->reason,
				'decided_at' => self::time( $proposal->decided_at ),
			);
		}

		return new WP_REST_Response(
			array(
				'display_name' => $creator->display_name,
				'fields'       => array_map( static fn( ProposalField $field ): string => $field->value, $this->proposals->available_fields() ),
				'category'     => null === $category ? null : array(
					'name'              => $category->name,
					'description'       => $category->description,
					'image_url'         => $category->image_url,
					'banner_url'        => $category->banner_url,
					'banner_default'    => $category->default_banner(),
					'banner_mobile_url' => $category->banner_mobile_url,
					'banner_link'       => $category->banner_link,
					'social_links'      => $category->socials,
				),
				'pending'      => (object) $pending,
				'rejected'     => (object) $rejected,
			),
			200
		);
	}

	/**
	 * A pending proposal as the dashboard shows it: text, or an image's type
	 * and size (the image itself at `/me/profile/files/{id}`).
	 *
	 * @param CategoryProposal $proposal Proposal.
	 * @return array<string, mixed>
	 */
	private static function pending( CategoryProposal $proposal ): array {
		$file = $proposal->file();

		return array(
			'id'         => $proposal->id,
			'value'      => $proposal->field->is_file() ? '' : $proposal->value,
			'file'       => null === $file ? null : array(
				'mime'   => $file->mime,
				'width'  => $file->width,
				'height' => $file->height,
			),
			'remove'     => $proposal->is_removal(),
			'created_at' => self::time( $proposal->created_at ),
		);
	}

	/**
	 * The proposals of a request body: the text fields, and `social_links`
	 * as `{ platform: url }`. Anything else is ignored.
	 *
	 * @param array<string, mixed> $body Body.
	 * @return array<string, mixed>
	 */
	private static function proposal_input( array $body ): array {
		$input = array_intersect_key( $body, array_flip( self::TEXT_FIELDS ) );

		if ( is_array( $body['social_links'] ?? null ) ) {
			foreach ( $body['social_links'] as $platform => $url ) {
				$input[ 'social_' . sanitize_key( (string) $platform ) ] = $url;
			}
		}

		return $input;
	}

	/**
	 * A refused proposal as a REST error: 403 for another creator, otherwise
	 * 400 with the field codes (`social_x` as `social_links.x`).
	 *
	 * @param CategoryProposalException $e Refusal.
	 */
	private static function refused( CategoryProposalException $e ): WP_Error {
		if ( CategoryProposalException::NOT_AUTHORIZED === $e->reason() ) {
			return self::error( 'merchboard_forbidden', __( 'You do not have access to this resource.', 'merchboard' ), 403 );
		}

		$fields = array();

		foreach ( $e->errors() as $field => $reason ) {
			$fields[ str_starts_with( $field, 'social_' ) ? 'social_links.' . substr( $field, 7 ) : $field ] = self::code( $reason );
		}

		return self::error( 'merchboard_invalid_profile', __( 'Some profile fields are invalid.', 'merchboard' ), 400, array( 'fields' => $fields ) );
	}

	/**
	 * The API's code of a refusal reason (`proposal_too_long` → `too_long`).
	 *
	 * @param string $reason Reason.
	 */
	private static function code( string $reason ): string {
		return str_starts_with( $reason, 'proposal_' ) ? substr( $reason, 9 ) : $reason;
	}

	/**
	 * A UTC time as ISO 8601, or null.
	 *
	 * @param DateTimeImmutable|null $time Time.
	 */
	private static function time( ?DateTimeImmutable $time ): ?string {
		return $time?->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * The JSON body (apiFetch) or form-encoded body; never query parameters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>
	 */
	private static function body( WP_REST_Request $request ): array {
		$body = (array) $request->get_json_params();

		if ( array() === $body ) {
			$body = $request->get_body_params();
		}

		return $body;
	}

	/**
	 * Argument schema of `PATCH /me/preferences`. WordPress validates only
	 * non-null values, so null passes here and the endpoint checks the value
	 * again. `required` is not used, because WordPress treats null as missing.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function preferences_args(): array {
		return array(
			'theme' => array(
				'type' => array( 'string', 'null' ),
				'enum' => array_merge( ThemePreference::THEMES, array( null ) ),
			),
		);
	}

	/**
	 * JSON schema of `/me/preferences`.
	 *
	 * @return array<string, mixed>
	 */
	public function preferences_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'merchboard_me_preferences',
			'type'       => 'object',
			'properties' => array(
				'theme' => array(
					'type' => array( 'string', 'null' ),
					'enum' => array_merge( ThemePreference::THEMES, array( null ) ),
				),
			),
		);
	}

	/**
	 * Argument schema of `PATCH /me/profile` (types only; lengths, URLs and
	 * domains are checked by CategoryProposals).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function profile_args(): array {
		$args = array_fill_keys( self::TEXT_FIELDS, array( 'type' => 'string' ) );

		$args['display_name']['maxLength'] = CreatorRepository::DISPLAY_NAME_MAX_LENGTH;
		$args['social_links']              = array(
			'type'                 => 'object',
			'properties'           => array_fill_keys( SocialPlatforms::PLATFORMS, array( 'type' => 'string' ) ),
			'additionalProperties' => false,
		);

		return $args;
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
}
