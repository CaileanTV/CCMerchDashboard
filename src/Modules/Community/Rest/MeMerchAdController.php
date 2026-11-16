<?php
/**
 * The creator's merch ad overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Rest;

use Closure;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Community\MerchAd\MerchAdPage;
use MerchBoard\Modules\Community\MerchAd\MerchAdRepository;
use MerchBoard\Modules\Community\MerchAd\MerchAdSettings;
use MerchBoard\Modules\Community\MerchAd\MerchAdView;
use MerchBoard\Modules\Community\TwitchExtension\TwitchExtensionSettings;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's ad in the stream (ADR-113 pkt. 1–4), while the shop has it
 * on (else 404 `merchboard_community_disabled`):
 *
 * - `GET`/`POST /me/community/merch-ad`: the settings, the OBS link, the
 *   products with their shop images, and the limits.
 * - `POST /me/community/merch-ad/regenerate`: a new OBS link.
 * - `POST /me/community/merch-ad/show`: "show now".
 *
 * The images are the shop's: without background since ADR-114, so creators
 * no longer upload or cut out images of their own (the owner's wish
 * 2026-11-16).
 */
final class MeMerchAdController extends RestController {

	/**
	 * The route.
	 */
	public const ROUTE = '/me/community/merch-ad';

	/**
	 * Unix time now.
	 *
	 * @var Closure(): int
	 */
	private Closure $now;

	/**
	 * Constructor.
	 *
	 * @phpstan-param (Closure(): int)|null $now
	 *
	 * @param CreatorContext    $context  The current creator.
	 * @param MerchAdRepository $settings The creators' ads.
	 * @param MerchAdView       $view     What an ad shows.
	 * @param Logger            $logger   Technical log.
	 * @param Closure|null      $now      Unix time now (tests).
	 */
	public function __construct( private CreatorContext $context, private MerchAdRepository $settings, private MerchAdView $view, Logger $logger, ?Closure $now = null ) {
		parent::__construct( $logger );
		$this->now = $now ?? static fn(): int => time();
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$routes = array(
			self::ROUTE                 => array(
				array( WP_REST_Server::READABLE, 'get_settings' ),
				array( WP_REST_Server::EDITABLE, 'save' ),
			),
			self::ROUTE . '/regenerate' => array( array( WP_REST_Server::CREATABLE, 'regenerate' ) ),
			self::ROUTE . '/show'       => array( array( WP_REST_Server::CREATABLE, 'show' ) ),
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
					),
					$methods
				)
			);
		}
	}

	/**
	 * A creator, while the shop has the ad switched on.
	 */
	public function permission(): bool|WP_Error {
		if ( ! Settings::merch_ad_enabled() ) {
			return self::error( 'merchboard_community_disabled', __( 'The merch ad is not available.', 'merchboard' ), 404 );
		}

		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * The creator's ad.
	 */
	public function get_settings(): WP_REST_Response|WP_Error {
		return $this->with_creator( fn( int $creator_id ): WP_REST_Response => $this->answer( $creator_id ) );
	}

	/**
	 * Saves the creator's form.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			function ( int $creator_id ) use ( $request ): WP_REST_Response|WP_Error {
				$body   = (array) $request->get_json_params();
				$values = array();

				foreach ( MerchAdSettings::form_fields() as $field ) {
					$values[ $field ] = $body[ $field ] ?? null;
				}

				$values['note'] = $body['note'] ?? '';

				try {
					$this->settings->save( $creator_id, $values );
				} catch ( TwitchSubDiscountInvalid $e ) {
					return self::invalid( $e );
				}

				return $this->answer( $creator_id );
			}
		);
	}

	/**
	 * A new OBS link.
	 */
	public function regenerate(): WP_REST_Response|WP_Error {
		return $this->with_creator(
			function ( int $creator_id ): WP_REST_Response {
				$this->settings->regenerate( $creator_id );

				return $this->answer( $creator_id );
			}
		);
	}

	/**
	 * "Show now".
	 */
	public function show(): WP_REST_Response|WP_Error {
		return $this->with_creator(
			function ( int $creator_id ): WP_REST_Response {
				$this->settings->show_now( $creator_id, ( $this->now )() );

				return $this->answer( $creator_id );
			}
		);
	}

	/**
	 * Runs a callback for the current creator.
	 *
	 * @param Closure $callback The creator's id → the answer.
	 * @phpstan-param Closure(int): (WP_REST_Response|WP_Error) $callback
	 */
	private function with_creator( Closure $callback ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function () use ( $callback ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				return $creator instanceof WP_Error ? $creator : $callback( $creator->id );
			}
		);
	}

	/**
	 * The answer with field errors.
	 *
	 * @param TwitchSubDiscountInvalid $e The errors.
	 */
	private static function invalid( TwitchSubDiscountInvalid $e ): WP_Error {
		return self::error( 'merchboard_invalid_merch_ad', __( 'Some fields of the merch ad are missing or invalid.', 'merchboard' ), 400, array( 'fields' => $e->fields() ) );
	}

	/**
	 * The answer to the creator.
	 *
	 * @param int $creator_id Creator.
	 * @throws \MerchBoard\Core\Database\DatabaseException When the creator's products cannot be read.
	 */
	private function answer( int $creator_id ): WP_REST_Response {
		$settings = $this->settings->find( $creator_id );
		$response = new WP_REST_Response(
			array(
				'enabled'       => $settings->enabled,
				'design'        => $settings->design,
				'product_ids'   => $settings->product_ids,
				'note'          => $settings->note,
				'every'         => $settings->every,
				'seconds'       => $settings->seconds,
				'enter'         => $settings->enter,
				'leave'         => $settings->leave,
				'accent'        => $settings->accent,
				'theme'         => $settings->theme,
				'pill_position' => $settings->pill_position,
				'pill_size'     => $settings->pill_size,
				'show_names'    => $settings->show_names,
				'stack_spread'  => $settings->stack_spread,
				'glow'          => $settings->glow,
				'pill_gradient' => $settings->pill_gradient,
				'url'           => '' === $settings->overlay_id ? null : MerchAdPage::url( $settings->overlay_id ),
				'preview_url'   => '' === $settings->overlay_id ? null : MerchAdPage::url( $settings->overlay_id, true ),
				'products'      => $this->view->products( $creator_id ),
				'limits'        => array(
					'max_products' => MerchAdSettings::MAX_PRODUCTS,
					'max_note'     => TwitchExtensionSettings::MAX_NOTE,
					'every'        => array( MerchAdSettings::MIN_EVERY, MerchAdSettings::MAX_EVERY ),
					'seconds'      => array( MerchAdSettings::MIN_SECONDS, MerchAdSettings::MAX_SECONDS ),
				),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
