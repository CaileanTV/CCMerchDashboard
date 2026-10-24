<?php
/**
 * REST routes of the standard overlays for the admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Presets;

use InvalidArgumentException;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\Admin\AlertsAdminPage;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Fonts\AlertFont;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Rest\MeAlertsController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard/v1/admin/alerts/presets` (step 25.9.5), only with
 * `merchboard_manage_alerts`:
 *
 * - `GET /` the list `[ { id, name } ]`; `POST /` (`{ "name" }`) makes one;
 * - `GET|PATCH /{id}` one standard overlay in a creator overlay's JSON
 *   (MeAlertsController::look(), no sounds, the image library only), so the
 *   dashboard's settings form and designer are reused; `DELETE /{id}`;
 * - `POST /{id}/name` (`{ "name" }`) renames it.
 */
final class AdminOverlayPresetsController extends RestController {

	/**
	 * The base route.
	 */
	public const ROUTE = '/admin/alerts/presets';

	/**
	 * The id pattern in a route.
	 */
	private const ID = '/(?P<preset>[0-9a-f]{16})';

	/**
	 * Constructor.
	 *
	 * @param OverlayPresets     $presets      The standard overlays.
	 * @param AlertsAvailability $availability Kill switch and MerchAlert guard.
	 * @param Logger             $logger       Technical log.
	 * @param AlertFonts|null    $fonts        Approved fonts.
	 */
	public function __construct( private OverlayPresets $presets, private AlertsAvailability $availability, Logger $logger, private ?AlertFonts $fonts = null ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$one = fn( string $method, string $callback ): array => array(
			'methods'             => $method,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, 'permission' ),
			'args'                => array(),
		);

		register_rest_route( self::NAMESPACE, self::ROUTE, array( $one( WP_REST_Server::READABLE, 'list_presets' ), $one( WP_REST_Server::CREATABLE, 'create_preset' ) ) );
		register_rest_route( self::NAMESPACE, self::ROUTE . self::ID, array( $one( WP_REST_Server::READABLE, 'get_preset' ), $one( 'PATCH', 'update_preset' ), $one( WP_REST_Server::DELETABLE, 'delete_preset' ) ) );
		register_rest_route( self::NAMESPACE, self::ROUTE . self::ID . '/name', array( $one( WP_REST_Server::CREATABLE, 'rename_preset' ) ) );
	}

	/**
	 * Only users who manage alerts.
	 */
	public function permission(): bool|WP_Error {
		return $this->require_capability( Capabilities::MANAGE_ALERTS );
	}

	/**
	 * The list.
	 */
	public function list_presets(): WP_REST_Response|WP_Error {
		return $this->run( self::ROUTE, fn(): WP_REST_Response => $this->listing() );
	}

	/**
	 * Makes a standard overlay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create_preset( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function () use ( $request ): WP_REST_Response|WP_Error {
				$name = $request->get_param( 'name' );

				try {
					$this->presets->create( is_string( $name ) ? $name : '' );
				} catch ( OverlayPresetException $e ) {
					return self::refused( $e );
				}

				return $this->listing();
			}
		);
	}

	/**
	 * One standard overlay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function get_preset( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/{id}',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$preset = $this->presets->find( self::id( $request ) );

				return null === $preset ? self::unknown() : $this->answer( $preset );
			}
		);
	}

	/**
	 * Changes a standard overlay's design.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_preset( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/{id}',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$input = MeAlertsController::input( $request );

				if ( null === $input ) {
					return self::error( 'merchboard_invalid_request', __( 'The overlay settings are invalid.', 'merchboard' ), 400 );
				}

				try {
					return $this->answer( $this->presets->update_settings( self::id( $request ), $input ) );
				} catch ( OverlayPresetException $e ) {
					return self::refused( $e );
				} catch ( InvalidArgumentException ) {
					return self::error( 'merchboard_invalid_request', __( 'The font is not available.', 'merchboard' ), 400, array( 'fields' => array( 'font_family' => 'not_approved' ) ) );
				}
			}
		);
	}

	/**
	 * Deletes a standard overlay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function delete_preset( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/{id}',
			function () use ( $request ): WP_REST_Response|WP_Error {
				try {
					$this->presets->delete( self::id( $request ) );
				} catch ( OverlayPresetException $e ) {
					return self::refused( $e );
				}

				return $this->listing();
			}
		);
	}

	/**
	 * Renames a standard overlay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function rename_preset( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/{id}/name',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$name = $request->get_param( 'name' );

				try {
					$this->presets->rename( self::id( $request ), is_string( $name ) ? $name : '' );
				} catch ( OverlayPresetException $e ) {
					return self::refused( $e );
				}

				return $this->listing();
			}
		);
	}

	/**
	 * The list answer.
	 */
	private function listing(): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'presets' => array_map(
					static fn( OverlayPreset $preset ): array => array(
						'id'   => $preset->id,
						'name' => $preset->name,
					),
					$this->presets->all()
				),
				'max'     => OverlayPresetStore::MAX,
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * One standard overlay as the dashboard's cards read an overlay (its
	 * "URL" is its place on the admin page; it has no browser source).
	 *
	 * @param OverlayPreset $preset Standard overlay.
	 */
	private function answer( OverlayPreset $preset ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'overlay'           => array_merge(
					array( 'url' => add_query_arg( 'preset', $preset->id, AlertsAdminPage::url( 'presets' ) ) ),
					MeAlertsController::look( $preset->settings, $this->fonts ),
					array(
						'audio'  => null,
						'images' => $this->presets->images(),
						'id'     => $preset->id,
						'name'   => $preset->name,
					)
				),
				'alerts_enabled'    => $this->availability->enabled(),
				'merchalert_active' => $this->availability->merchalert_active(),
				'fonts'             => array_values(
					array_map(
						static fn( AlertFont $font ): array => array(
							'family' => $font->family,
							'url'    => $font->css_url(),
						),
						$this->fonts?->approved() ?? array()
					)
				),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The id of a route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private static function id( WP_REST_Request $request ): string {
		$id = $request->get_param( 'preset' );

		return is_string( $id ) ? $id : '';
	}

	/**
	 * The answer for a refused change.
	 *
	 * @param OverlayPresetException $e Reason.
	 */
	private static function refused( OverlayPresetException $e ): WP_Error {
		if ( OverlayPresetException::NOT_AUTHORIZED === $e->reason() ) {
			return self::error( 'merchboard_forbidden', __( 'You do not have access to this resource.', 'merchboard' ), 403 );
		}

		return OverlayPresetException::UNKNOWN_PRESET === $e->reason()
			? self::unknown()
			: self::error( 'merchboard_invalid_request', __( 'The standard overlay was not saved.', 'merchboard' ), 400, array( 'fields' => array( $e->field() => $e->reason() ) ) );
	}

	/**
	 * The answer for an unknown standard overlay.
	 */
	private static function unknown(): WP_Error {
		return self::error( 'merchboard_not_found', __( 'The standard overlay does not exist.', 'merchboard' ), 404 );
	}
}
