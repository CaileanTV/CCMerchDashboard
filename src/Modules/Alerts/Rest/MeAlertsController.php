<?php
/**
 * REST routes of the creator's alert overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Rest;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Fonts\AlertFont;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use InvalidArgumentException;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlay;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlays;
use MerchBoard\Modules\Alerts\Overlay\OverlayRoute;
use MerchBoard\Modules\Alerts\Sounds\AlertSoundException;
use MerchBoard\Modules\Alerts\Sounds\AlertSounds;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `/me/alerts/overlay` (ADR-065 pkt. 3, phase 25 step 4). The creator is
 * always the one CreatorContext resolves (ADR-007); no route takes an id.
 *
 * - `GET`: the overlay (or null before it is set up) and whether alerts are
 *   switched on and MerchAlert is active.
 * - `POST`: sets the overlay up (idempotent).
 * - `PATCH`: changes the form fields (AlertOverlays::EDITABLE,
 *   `show_secondary`, `audio_enabled`, `font_family` and `layout`) from the
 *   JSON or form body; values are normalized,
 *   so the answer shows what was saved.
 * - `POST …/regenerate`: a new secret id.
 * - `POST …/test`: a test alert.
 * - `POST …/sounds/{slot}` (multipart field `file`) and `DELETE …/sounds/{slot}`:
 *   the default sound (`default`) or the sound of one of the creator's
 *   categories (its term id); see AlertSounds.
 *
 * The answer contains the OBS URL with the secret id, so it is only ever
 * sent to the creator it belongs to. Before the overlay is set up, the
 * changing routes answer 404.
 */
final class MeAlertsController extends RestController {

	private const ROUTE = '/me/alerts/overlay';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext     $context      Creator access.
	 * @param AlertOverlays      $overlays     Overlay service.
	 * @param AlertsAvailability $availability Kill switch and MerchAlert guard.
	 * @param Logger             $logger       Technical log.
	 * @param AlertFonts|null    $fonts        Approved fonts; null for none.
	 * @param AlertSounds|null   $sounds       Alert sounds; null for none.
	 */
	public function __construct( private CreatorContext $context, private AlertOverlays $overlays, private AlertsAvailability $availability, Logger $logger, private ?AlertFonts $fonts = null, private ?AlertSounds $sounds = null ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_overlay' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_overlay' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_overlay' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
			)
		);

		foreach ( array( 'regenerate', 'test' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				self::ROUTE . '/' . $action,
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'regenerate' === $action ? 'regenerate' : 'send_test' ),
						'permission_callback' => array( $this, 'permission' ),
						'args'                => array(),
					),
				)
			);
		}

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/sounds/(?P<slot>default|[1-9][0-9]{0,18})',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_sound' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_sound' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * Permission: an active creator (CreatorContext).
	 */
	public function permission(): bool|WP_Error {
		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * `GET /me/alerts/overlay`.
	 */
	public function get_overlay(): WP_REST_Response|WP_Error {
		return $this->with_creator( self::ROUTE, fn( int $creator_id ): WP_REST_Response => $this->answer( $this->overlays->find( $creator_id ) ) );
	}

	/**
	 * `POST /me/alerts/overlay`.
	 */
	public function create_overlay(): WP_REST_Response|WP_Error {
		return $this->with_creator( self::ROUTE, fn( int $creator_id ): WP_REST_Response => $this->answer( $this->overlays->create( $creator_id ) ) );
	}

	/**
	 * `PATCH /me/alerts/overlay`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_overlay( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE,
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				$input = self::input( $request );

				if ( null === $input ) {
					return self::error( 'merchboard_invalid_request', __( 'The overlay settings are invalid.', 'merchboard' ), 400 );
				}

				try {
					$overlay = $this->overlays->update_settings( $creator_id, $input );
				} catch ( InvalidArgumentException ) {
					return self::error( 'merchboard_invalid_request', __( 'The font is not available.', 'merchboard' ), 400, array( 'fields' => array( 'font_family' => 'not_approved' ) ) );
				}

				return null === $overlay ? self::not_set_up() : $this->answer( $overlay );
			}
		);
	}

	/**
	 * `POST /me/alerts/overlay/regenerate`.
	 */
	public function regenerate(): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/regenerate',
			function ( int $creator_id ): WP_REST_Response|WP_Error {
				$overlay = $this->overlays->regenerate( $creator_id );

				return null === $overlay ? self::not_set_up() : $this->answer( $overlay );
			}
		);
	}

	/**
	 * `POST /me/alerts/overlay/test`: `{ "sent": true }`.
	 */
	public function send_test(): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/test',
			fn( int $creator_id ): WP_REST_Response|WP_Error => $this->overlays->send_test( $creator_id ) ? new WP_REST_Response( array( 'sent' => true ), 200 ) : self::not_set_up()
		);
	}

	/**
	 * `POST /me/alerts/overlay/sounds/{slot}`: stores the uploaded file `file`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function upload_sound( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/sounds',
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				$files = $request->get_file_params();
				$file  = is_array( $files['file'] ?? null ) ? $files['file'] : array();

				if ( null === $this->sounds ) {
					return self::not_set_up();
				}

				try {
					$overlay = $this->sounds->upload( $creator_id, self::slot( $request ), $file );
				} catch ( AlertSoundException $e ) {
					return self::error( 'merchboard_invalid_request', __( 'The sound was not saved.', 'merchboard' ), 400, array( 'fields' => array( $e->field() => $e->reason() ) ) );
				}

				return null === $overlay ? self::not_set_up() : $this->answer( $overlay );
			}
		);
	}

	/**
	 * `DELETE /me/alerts/overlay/sounds/{slot}`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function remove_sound( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/sounds',
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				$overlay = null === $this->sounds ? null : $this->sounds->remove( $creator_id, self::slot( $request ) );

				return null === $overlay ? self::not_set_up() : $this->answer( $overlay );
			}
		);
	}

	/**
	 * The sound slot of a route: 0 for the default sound, else a term id.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private static function slot( WP_REST_Request $request ): int {
		$slot = $request->get_param( 'slot' );

		return is_string( $slot ) && 'default' !== $slot ? (int) $slot : 0;
	}

	/**
	 * Runs an endpoint for the current creator.
	 *
	 * @param string                                     $route    Route, for the log.
	 * @param callable(int): (WP_REST_Response|WP_Error) $callback Endpoint body.
	 */
	private function with_creator( string $route, callable $callback ): WP_REST_Response|WP_Error {
		return $this->run(
			$route,
			function () use ( $callback ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				return $creator instanceof WP_Error ? $creator : $callback( $creator->id );
			}
		);
	}

	/**
	 * The answer of every overlay route.
	 *
	 * @param AlertOverlay|null $overlay Overlay, or null before it is set up.
	 */
	private function answer( ?AlertOverlay $overlay ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'overlay'           => null === $overlay ? null : $this->overlay( $overlay ),
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
	 * An overlay as the dashboard shows it.
	 *
	 * @param AlertOverlay $overlay Overlay.
	 * @return array{url: string, settings: array<string, mixed>, font_approved: bool, layout: array<string, mixed>, audio: array<string, mixed>|null}
	 */
	private function overlay( AlertOverlay $overlay ): array {
		$values   = $overlay->settings->to_array();
		$settings = array();

		foreach ( AlertOverlays::EDITABLE as $key ) {
			$settings[ $key ] = $values[ $key ];
		}

		$settings['show_secondary'] = $values['layout']['elements']['secondary_text']['visible'];
		$settings['font_family']    = $values['font_family'];

		return array(
			'url'           => OverlayRoute::url( $overlay->overlay_id ),
			'settings'      => $settings,
			// False when the saved font is no longer approved; the overlay then shows the default font.
			'font_approved' => 'inherit' === $values['font_family'] || null !== $this->fonts?->find( $values['font_family'] ),
			'layout'        => $values['layout'],
			'audio'         => null === $this->sounds ? null : $this->sounds->describe( $overlay ),
		);
	}

	/**
	 * The submitted form fields (JSON or form body, never query parameters),
	 * or null when a field has the wrong type.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>|null
	 */
	private static function input( WP_REST_Request $request ): ?array {
		$body = (array) $request->get_json_params();

		if ( array() === $body ) {
			$body = $request->get_body_params();
		}

		$input = array();

		foreach ( AlertOverlays::EDITABLE as $key ) {
			if ( ! array_key_exists( $key, $body ) ) {
				continue;
			}

			if ( ! is_string( $body[ $key ] ) && ! is_int( $body[ $key ] ) ) {
				return null;
			}

			$input[ $key ] = $body[ $key ];
		}

		if ( array_key_exists( 'font_family', $body ) ) {
			if ( ! is_string( $body['font_family'] ) ) {
				return null;
			}

			$input['font_family'] = $body['font_family'];
		}

		if ( array_key_exists( 'layout', $body ) ) {
			if ( ! is_array( $body['layout'] ) ) {
				return null;
			}

			$input['layout'] = $body['layout'];
		}

		foreach ( array( 'show_secondary', 'audio_enabled' ) as $key ) {
			if ( ! array_key_exists( $key, $body ) ) {
				continue;
			}

			if ( ! is_bool( $body[ $key ] ) ) {
				return null;
			}

			$input[ $key ] = $body[ $key ];
		}

		return $input;
	}

	/**
	 * The answer before the overlay is set up.
	 */
	private static function not_set_up(): WP_Error {
		return self::error( 'merchboard_not_found', __( 'Set up your overlay first.', 'merchboard' ), 404 );
	}
}
