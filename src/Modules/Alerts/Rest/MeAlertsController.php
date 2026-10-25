<?php
/**
 * REST routes of the creator's alert overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Rest;

use MerchBoard\Core\Security\ImpersonationSession;
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
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Modules\Alerts\Images\AlertImageException;
use MerchBoard\Modules\Alerts\Images\AlertImages;
use MerchBoard\Modules\Alerts\Sounds\AlertSoundException;
use MerchBoard\Modules\Alerts\Sounds\AlertSounds;
use MerchBoard\Modules\Alerts\Designs\CreatorDesign;
use MerchBoard\Modules\Alerts\Designs\CreatorDesignException;
use MerchBoard\Modules\Alerts\Designs\CreatorDesigns;
use MerchBoard\Modules\Alerts\PageLayout\CreatorPageLayout;
use MerchBoard\Modules\Alerts\PageLayout\CreatorPageLayoutStore;
use MerchBoard\Modules\Alerts\Presets\OverlayPreset;
use MerchBoard\Modules\Alerts\Presets\OverlayPresetException;
use MerchBoard\Modules\Alerts\Presets\OverlayPresets;
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
 * - `POST …/sounds/{slot}` (multipart field `file`, or JSON `library` with
 *   a library sound) and `DELETE …/sounds/{slot}`: the default sound
 *   (`default`) or the sound of one of the creator's categories (its term
 *   id); see AlertSounds.
 * - `POST …/images` (multipart field `file`) and `DELETE …/images/{id}`:
 *   the creator's own images that can fill the alert text (step 25.6.10);
 *   see AlertImages. A text fill (`text_fills` in `PATCH`) keeps only the
 *   creator's own image or a library image.
 *
 * The answer contains the OBS URL with the secret id, so it is only ever
 * sent to the creator it belongs to. Before the overlay is set up, the
 * changing routes answer 404.
 */
final class MeAlertsController extends RestController {

	/**
	 * The overlay id sent while an administrator views as the creator
	 * (ADR-075): the URL's shape without the secret.
	 */
	public const HIDDEN_OVERLAY_ID = '00000000000000000000000000000000';

	private const ROUTE = '/me/alerts/overlay';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext              $context      Creator access.
	 * @param AlertOverlays               $overlays     Overlay service.
	 * @param AlertsAvailability          $availability Kill switch and MerchAlert guard.
	 * @param Logger                      $logger       Technical log.
	 * @param AlertFonts|null             $fonts        Approved fonts; null for none.
	 * @param AlertSounds|null            $sounds       Alert sounds; null for none.
	 * @param AlertImages|null            $images       Text fill images; null for none.
	 * @param OverlayPresets|null         $presets     Standard overlays (step 25.9.5); null for none.
	 * @param CreatorDesigns|null         $designs     The creator's saved designs; null for none.
	 * @param CreatorPageLayoutStore|null $page The setup of the creator's page (step 25.9.6); null for the default.
	 */
	public function __construct( private CreatorContext $context, private AlertOverlays $overlays, private AlertsAvailability $availability, Logger $logger, private ?AlertFonts $fonts = null, private ?AlertSounds $sounds = null, private ?AlertImages $images = null, private ?OverlayPresets $presets = null, private ?CreatorDesigns $designs = null, private ?CreatorPageLayoutStore $page = null ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		// The creator's own saved designs (after step 25.9.5).
		$routes = array(
			'/me/alerts/designs'                          => array( array( WP_REST_Server::READABLE, 'list_designs' ), array( WP_REST_Server::CREATABLE, 'save_design' ) ),
			'/me/alerts/designs/(?P<design>[0-9a-f]{16})' => array( array( WP_REST_Server::DELETABLE, 'delete_design' ) ),
			'/me/alerts/designs/(?P<design>[0-9a-f]{16})/update' => array( array( WP_REST_Server::CREATABLE, 'update_design' ) ),
			'/me/alerts/designs/(?P<design>[0-9a-f]{16})/name' => array( array( WP_REST_Server::CREATABLE, 'rename_design' ) ),
			self::ROUTE . '/design'                       => array( array( WP_REST_Server::CREATABLE, 'apply_design' ) ),
		);

		foreach ( $routes as $route => $methods ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array_map(
					fn( array $method ): array => array(
						'methods'             => $method[0],
						'callback'            => array( $this, $method[1] ),
						'permission_callback' => array( $this, 'permission' ),
						'args'                => array(),
					),
					$methods
				)
			);
		}

		// The standard overlays to start from (step 25.9.5).
		register_rest_route(
			self::NAMESPACE,
			'/me/alerts/presets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_presets' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/preset',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'apply_preset' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
			)
		);

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

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/images',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_image' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/images/(?P<image>[1-9][0-9]{0,18})',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_image' ),
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
	 * The standard overlays, with what their preview needs (step 25.9.5).
	 */
	public function list_presets(): WP_REST_Response|WP_Error {
		return $this->with_creator(
			'/me/alerts/presets',
			function (): WP_REST_Response {
				$images   = $this->presets?->images();
				$response = new WP_REST_Response(
					array(
						'presets' => array_map(
							fn( OverlayPreset $preset ): array => array_merge(
								array(
									'id'   => $preset->id,
									'name' => $preset->name,
								),
								self::look( $preset->settings, $this->fonts ),
								array( 'images' => $images )
							),
							$this->presets?->all() ?? array()
						),
					),
					200
				);
				$response->header( 'Cache-Control', 'no-store' );

				return $response;
			}
		);
	}

	/**
	 * Copies a standard overlay's look and texts into the creator's overlay
	 * (`{ "preset": id }`; step 25.9.5).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function apply_preset( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/preset',
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				$preset = $request->get_param( 'preset' );

				if ( null === $this->presets ) {
					return self::not_set_up();
				}

				try {
					$overlay = $this->presets->apply( $creator_id, is_string( $preset ) ? $preset : '' );
				} catch ( OverlayPresetException $e ) {
					return self::error( 'merchboard_invalid_request', __( 'The standard overlay does not exist any more.', 'merchboard' ), 400, array( 'fields' => array( 'preset' => $e->reason() ) ) );
				}

				return null === $overlay ? self::not_set_up() : $this->answer( $overlay );
			}
		);
	}

	/**
	 * The creator's saved designs, with what their preview needs.
	 */
	public function list_designs(): WP_REST_Response|WP_Error {
		return $this->with_creator( '/me/alerts/designs', fn( int $creator_id ): WP_REST_Response => $this->designs_answer( $creator_id, null === $this->designs ? array() : $this->designs->all( $creator_id ) ) );
	}

	/**
	 * Saves the overlay's current design under a name (`{ "name" }`).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function save_design( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->design_change( '/me/alerts/designs', fn( int $creator_id, CreatorDesigns $designs ): array => $designs->save_current( $creator_id, self::string_param( $request, 'name' ) ) );
	}

	/**
	 * Overwrites a design with the overlay's current design.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_design( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->design_change( '/me/alerts/designs/{id}/update', fn( int $creator_id, CreatorDesigns $designs ): array => $designs->update_from_current( $creator_id, self::string_param( $request, 'design' ) ) );
	}

	/**
	 * Renames a design (`{ "name" }`).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function rename_design( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->design_change( '/me/alerts/designs/{id}/name', fn( int $creator_id, CreatorDesigns $designs ): array => $designs->rename( $creator_id, self::string_param( $request, 'design' ), self::string_param( $request, 'name' ) ) );
	}

	/**
	 * Deletes a design.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function delete_design( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->design_change( '/me/alerts/designs/{id}', fn( int $creator_id, CreatorDesigns $designs ): array => $designs->delete( $creator_id, self::string_param( $request, 'design' ) ) );
	}

	/**
	 * Takes a design into use (`{ "design": id }`); answers with the overlay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function apply_design( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/design',
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				if ( null === $this->designs ) {
					return self::not_set_up();
				}

				try {
					return $this->answer( $this->designs->apply( $creator_id, self::string_param( $request, 'design' ) ) );
				} catch ( CreatorDesignException $e ) {
					return self::design_refused( $e );
				}
			}
		);
	}

	/**
	 * Runs a change of the creator's designs and answers with the list.
	 *
	 * @param string   $route  Route for the log.
	 * @param callable $change The change: creator id and designs → the designs.
	 * @phpstan-param callable(int, CreatorDesigns): list<CreatorDesign> $change
	 */
	private function design_change( string $route, callable $change ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			$route,
			function ( int $creator_id ) use ( $change ): WP_REST_Response|WP_Error {
				if ( null === $this->designs ) {
					return self::not_set_up();
				}

				try {
					return $this->designs_answer( $creator_id, $change( $creator_id, $this->designs ) );
				} catch ( CreatorDesignException $e ) {
					return self::design_refused( $e );
				}
			}
		);
	}

	/**
	 * The designs: `{ designs: [ { id, name, saved_at, settings, font_approved, layout, images } ], max }`.
	 *
	 * @phpstan-param list<CreatorDesign> $designs
	 *
	 * @param int             $creator_id Creator.
	 * @param CreatorDesign[] $designs    Designs.
	 */
	private function designs_answer( int $creator_id, array $designs ): WP_REST_Response {
		$images   = $this->images?->describe( $creator_id );
		$response = new WP_REST_Response(
			array(
				'designs' => array_map(
					fn( CreatorDesign $design ): array => array_merge(
						array(
							'id'       => $design->id,
							'name'     => $design->name,
							'saved_at' => $design->saved_at,
						),
						self::look( $design->settings, $this->fonts ),
						array( 'images' => $images )
					),
					$designs
				),
				'max'     => CreatorDesigns::MAX,
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The answer for a refused design change.
	 *
	 * @param CreatorDesignException $e Reason.
	 */
	private static function design_refused( CreatorDesignException $e ): WP_Error {
		return CreatorDesignException::NO_OVERLAY === $e->reason()
			? self::not_set_up()
			: self::error( 'merchboard_invalid_request', __( 'The design was not saved.', 'merchboard' ), 400, array( 'fields' => array( $e->field() => $e->reason() ) ) );
	}

	/**
	 * A string parameter ('' when missing).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Parameter.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private static function string_param( WP_REST_Request $request, string $name ): string {
		$value = $request->get_param( $name );

		return is_string( $value ) ? $value : '';
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
	 * `POST /me/alerts/overlay/sounds/{slot}`: stores the uploaded file `file`,
	 * or puts the library sound `library` into the slot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function upload_sound( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/sounds',
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				$files   = $request->get_file_params();
				$file    = is_array( $files['file'] ?? null ) ? $files['file'] : array();
				$library = $request->get_param( 'library' );

				if ( null === $this->sounds ) {
					return self::not_set_up();
				}

				try {
					$overlay = array() === $file && null !== $library
						? $this->sounds->choose( $creator_id, self::slot( $request ), is_int( $library ) ? $library : 0 )
						: $this->sounds->upload( $creator_id, self::slot( $request ), $file );
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
	 * `POST /me/alerts/overlay/images`: stores the uploaded image `file`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function upload_image( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/images',
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				$files   = $request->get_file_params();
				$overlay = $this->overlays->find( $creator_id );

				if ( null === $this->images || null === $overlay ) {
					return self::not_set_up();
				}

				try {
					$this->images->upload( $creator_id, is_array( $files['file'] ?? null ) ? $files['file'] : array() );
				} catch ( AlertImageException $e ) {
					return self::error( 'merchboard_invalid_request', __( 'The image was not saved.', 'merchboard' ), 400, array( 'fields' => array( $e->field() => $e->reason() ) ) );
				}

				return $this->answer( $overlay );
			}
		);
	}

	/**
	 * `DELETE /me/alerts/overlay/images/{id}`: deletes an own image; the texts
	 * it filled lose their fill.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function remove_image( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/images',
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				$image = $request->get_param( 'image' );

				if ( null === $this->images || null === $this->overlays->find( $creator_id ) ) {
					return self::not_set_up();
				}

				try {
					$this->images->remove( $creator_id, is_string( $image ) ? (int) $image : 0 );
				} catch ( AlertImageException $e ) {
					return self::error( 'merchboard_invalid_request', __( 'The image was not deleted.', 'merchboard' ), 400, array( 'fields' => array( $e->field() => $e->reason() ) ) );
				}

				return $this->answer( $this->overlays->find( $creator_id ) );
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
				'page'              => ( $this->page?->get() ?? CreatorPageLayout::defaults() )->to_array(),
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
	 * @return array{url: string, settings: array<string, mixed>, font_approved: bool, layout: array<string, mixed>, audio: array<string, mixed>|null, images: array<string, mixed>|null}
	 */
	private function overlay( AlertOverlay $overlay ): array {
		return array_merge(
			// While an administrator views as the creator (ADR-075) the secret id is never sent.
			array( 'url' => OverlayRoute::url( null === ImpersonationSession::creator_id() ? $overlay->overlay_id : self::HIDDEN_OVERLAY_ID ) ),
			self::look( $overlay->settings, $this->fonts ),
			array(
				'audio'  => null === $this->sounds ? null : $this->sounds->describe( $overlay ),
				'images' => $this->images?->describe( $overlay->creator_id ),
			)
		);
	}

	/**
	 * The settings, the font's approval and the layout of an overlay as the
	 * dashboard reads them (shared with the global overlay, step 25.9.4).
	 *
	 * @param OverlaySettings $overlay_settings Settings.
	 * @param AlertFonts|null $fonts            Approved fonts.
	 * @return array{settings: array<string, mixed>, font_approved: bool, layout: array<string, mixed>}
	 */
	public static function look( OverlaySettings $overlay_settings, ?AlertFonts $fonts ): array {
		$values   = $overlay_settings->to_array();
		$settings = array();

		foreach ( AlertOverlays::EDITABLE as $key ) {
			$settings[ $key ] = $values[ $key ];
		}

		$settings['show_secondary']     = $values['layout']['elements']['secondary_text']['visible'];
		$settings['display_from_sound'] = $values['display_from_sound'];
		$settings['font_family']        = $values['font_family'];
		$settings['animations']         = $values['animations'];
		$settings['text_fills']         = $values['text_fills'];
		$settings['text_frame']         = $values['text_frame'];

		return array(
			'settings'      => $settings,
			// False when the saved font is no longer approved; the overlay then shows the default font.
			'font_approved' => 'inherit' === $values['font_family'] || null !== $fonts?->find( $values['font_family'] ),
			'layout'        => $values['layout'],
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
	public static function input( WP_REST_Request $request ): ?array {
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

		foreach ( array( 'layout', 'animations', 'text_fills', 'text_frame' ) as $key ) {
			if ( ! array_key_exists( $key, $body ) ) {
				continue;
			}

			if ( ! is_array( $body[ $key ] ) ) {
				return null;
			}

			$input[ $key ] = $body[ $key ];
		}

		foreach ( array( 'show_secondary', 'audio_enabled', 'display_from_sound' ) as $key ) {
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
