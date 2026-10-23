<?php
/**
 * REST routes of the global alert overlay for the admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\GlobalOverlay;

use InvalidArgumentException;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Fonts\AlertFont;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Images\AlertImageException;
use MerchBoard\Modules\Alerts\Rest\MeAlertsController;
use MerchBoard\Modules\Alerts\Sounds\AlertSoundException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard/v1/admin/alerts/global-overlay` (step 25.9.4), only with
 * `merchboard_manage_alerts`: the admin page's designer and cards read and
 * change the global overlay here, with the same JSON as a creator's overlay
 * (`/me/alerts/overlay`, MeAlertsController::look() and input()), so the
 * dashboard's cards are reused. Additionally: `enabled` (on/off) and
 * `replay` (the latest order again). Nothing here reads or changes a
 * creator's overlay.
 *
 * - `GET|POST|PATCH /` read, set up, change settings;
 * - `POST /enabled` (`{ "enabled": bool }`), `/regenerate`, `/test`, `/replay`;
 * - `POST|DELETE /sounds/{default|term id}`, `POST /images`, `DELETE /images/{id}`.
 */
final class AdminGlobalOverlayController extends RestController {

	/**
	 * The base route.
	 */
	public const ROUTE = '/admin/alerts/global-overlay';

	/**
	 * Constructor.
	 *
	 * @param GlobalOverlayService     $service      Actions.
	 * @param AlertsAvailability       $availability Kill switch and MerchAlert guard.
	 * @param Logger                   $logger       Technical log.
	 * @param AlertFonts|null          $fonts        Approved fonts.
	 * @param GlobalOverlaySounds|null $sounds       Sounds; null for none.
	 * @param GlobalOverlayImages|null $images       Images; null for none.
	 */
	public function __construct( private GlobalOverlayService $service, private AlertsAvailability $availability, Logger $logger, private ?AlertFonts $fonts = null, private ?GlobalOverlaySounds $sounds = null, private ?GlobalOverlayImages $images = null ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$route = static fn( string $path, array $methods ): bool => register_rest_route( self::NAMESPACE, self::ROUTE . $path, $methods );
		$one   = fn( string $method, string $callback ): array => array(
			'methods'             => $method,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, 'permission' ),
			'args'                => array(),
		);

		$route( '', array( $one( WP_REST_Server::READABLE, 'get_overlay' ), $one( WP_REST_Server::CREATABLE, 'create_overlay' ), $one( 'PATCH', 'update_overlay' ) ) );
		$route( '/enabled', array( $one( WP_REST_Server::CREATABLE, 'set_enabled' ) ) );
		$route( '/regenerate', array( $one( WP_REST_Server::CREATABLE, 'regenerate' ) ) );
		$route( '/test', array( $one( WP_REST_Server::CREATABLE, 'send_test' ) ) );
		$route( '/replay', array( $one( WP_REST_Server::CREATABLE, 'replay' ) ) );
		$route( '/sounds/(?P<slot>default|[1-9][0-9]{0,18})', array( $one( WP_REST_Server::CREATABLE, 'upload_sound' ), $one( WP_REST_Server::DELETABLE, 'remove_sound' ) ) );
		$route( '/images', array( $one( WP_REST_Server::CREATABLE, 'upload_image' ) ) );
		$route( '/images/(?P<image>[1-9][0-9]{0,18})', array( $one( WP_REST_Server::DELETABLE, 'remove_image' ) ) );
	}

	/**
	 * Only users who manage alerts.
	 */
	public function permission(): bool|WP_Error {
		return $this->require_capability( Capabilities::MANAGE_ALERTS );
	}

	/**
	 * The overlay (null before it is set up).
	 */
	public function get_overlay(): WP_REST_Response|WP_Error {
		return $this->run( self::ROUTE, fn(): WP_REST_Response => $this->answer( $this->service->find() ) );
	}

	/**
	 * Sets the overlay up.
	 */
	public function create_overlay(): WP_REST_Response|WP_Error {
		return $this->run( self::ROUTE, fn(): WP_REST_Response => $this->answer( $this->service->create() ) );
	}

	/**
	 * Changes the settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_overlay( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function () use ( $request ): WP_REST_Response|WP_Error {
				$input = MeAlertsController::input( $request );

				if ( null === $input ) {
					return self::error( 'merchboard_invalid_request', __( 'The overlay settings are invalid.', 'merchboard' ), 400 );
				}

				try {
					$overlay = $this->service->update_settings( $input );
				} catch ( InvalidArgumentException ) {
					return self::error( 'merchboard_invalid_request', __( 'The font is not available.', 'merchboard' ), 400, array( 'fields' => array( 'font_family' => 'not_approved' ) ) );
				}

				return null === $overlay ? self::not_set_up() : $this->answer( $overlay );
			}
		);
	}

	/**
	 * Switches the overlay on or off.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function set_enabled( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/enabled',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$enabled = $request->get_param( 'enabled' );

				if ( ! is_bool( $enabled ) ) {
					return self::error( 'merchboard_invalid_request', __( 'The overlay settings are invalid.', 'merchboard' ), 400 );
				}

				return null === $this->service->set_enabled( $enabled ) ? self::not_set_up() : $this->answer( $this->service->find() );
			}
		);
	}

	/**
	 * A new URL.
	 */
	public function regenerate(): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/regenerate',
			function (): WP_REST_Response|WP_Error {
				$overlay = $this->service->regenerate();

				return null === $overlay ? self::not_set_up() : $this->answer( $overlay );
			}
		);
	}

	/**
	 * A test alert.
	 */
	public function send_test(): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/test',
			fn(): WP_REST_Response|WP_Error => $this->service->send_test() ? new WP_REST_Response( array( 'sent' => true ), 200 ) : self::not_set_up()
		);
	}

	/**
	 * The latest order again.
	 */
	public function replay(): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/replay',
			function (): WP_REST_Response|WP_Error {
				$count = $this->service->replay_latest();

				if ( null === $count ) {
					return self::not_set_up();
				}

				return 0 === $count
					? self::error( 'merchboard_no_orders', __( 'There is no order with alerts yet.', 'merchboard' ), 404 )
					: new WP_REST_Response( array( 'sent' => $count ), 200 );
			}
		);
	}

	/**
	 * Uploads a sound into a slot, or chooses a library sound (`library`).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function upload_sound( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/sounds',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$files   = $request->get_file_params();
				$file    = is_array( $files['file'] ?? null ) ? $files['file'] : array();
				$library = $request->get_param( 'library' );

				if ( null === $this->sounds ) {
					return self::not_set_up();
				}

				try {
					$overlay = array() === $file && null !== $library
						? $this->sounds->choose( self::slot( $request ), is_int( $library ) ? $library : 0 )
						: $this->sounds->upload( self::slot( $request ), $file );
				} catch ( AlertSoundException $e ) {
					return self::error( 'merchboard_invalid_request', __( 'The sound was not saved.', 'merchboard' ), 400, array( 'fields' => array( $e->field() => $e->reason() ) ) );
				}

				return null === $overlay ? self::not_set_up() : $this->answer( $overlay );
			}
		);
	}

	/**
	 * Empties a sound slot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function remove_sound( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/sounds',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$overlay = $this->sounds?->remove( self::slot( $request ) );

				return null === $overlay ? self::not_set_up() : $this->answer( $overlay );
			}
		);
	}

	/**
	 * Uploads an own image.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function upload_image( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/images',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$files   = $request->get_file_params();
				$overlay = $this->service->find();

				if ( null === $this->images || null === $overlay ) {
					return self::not_set_up();
				}

				try {
					$this->images->upload( is_array( $files['file'] ?? null ) ? $files['file'] : array() );
				} catch ( AlertImageException $e ) {
					return self::error( 'merchboard_invalid_request', __( 'The image was not saved.', 'merchboard' ), 400, array( 'fields' => array( $e->field() => $e->reason() ) ) );
				}

				return $this->answer( $overlay );
			}
		);
	}

	/**
	 * Deletes an own image.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function remove_image( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/images',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$image = $request->get_param( 'image' );

				if ( null === $this->images || null === $this->service->find() ) {
					return self::not_set_up();
				}

				try {
					$this->images->remove( is_string( $image ) ? (int) $image : 0 );
				} catch ( AlertImageException $e ) {
					return self::error( 'merchboard_invalid_request', __( 'The image was not deleted.', 'merchboard' ), 400, array( 'fields' => array( $e->field() => $e->reason() ) ) );
				}

				return $this->answer( $this->service->find() );
			}
		);
	}

	/**
	 * The slot of a sound route (0: default).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private static function slot( WP_REST_Request $request ): int {
		$slot = $request->get_param( 'slot' );

		return is_string( $slot ) && 'default' !== $slot ? (int) $slot : 0;
	}

	/**
	 * The answer: the overlay as the dashboard's cards read it, plus whether
	 * it is switched on.
	 *
	 * @param GlobalOverlay|null $overlay Overlay.
	 */
	private function answer( ?GlobalOverlay $overlay ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'overlay'           => null === $overlay ? null : array_merge(
					array( 'url' => GlobalOverlayRoute::url( $overlay->overlay_id ) ),
					MeAlertsController::look( $overlay->settings, $this->fonts ),
					array(
						'audio'   => $this->sounds?->describe( $overlay ),
						'images'  => $this->images?->describe(),
						'enabled' => $overlay->enabled,
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
	 * The answer before the overlay is set up.
	 */
	private static function not_set_up(): WP_Error {
		return self::error( 'merchboard_not_found', __( 'The global overlay is not set up yet.', 'merchboard' ), 404 );
	}
}
