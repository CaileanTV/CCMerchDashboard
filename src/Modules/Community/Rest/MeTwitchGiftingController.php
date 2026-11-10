<?php
/**
 * The creator's Twitch gifting in the dashboard.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Rest;

use Closure;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Community\Gifting\Gift;
use MerchBoard\Modules\Community\Gifting\GiftDraws;
use MerchBoard\Modules\Community\Gifting\GiftingSettings;
use MerchBoard\Modules\Community\Gifting\GiftingSettingsRepository;
use MerchBoard\Modules\Community\Gifting\GiftRepository;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `/me`-scoped (ADR-007; the creator only through CreatorContext; ADR-107):
 *
 * - `GET /me/community/twitch-gifting`: the settings, the connected Twitch
 *   channel, whether Twitch is set up and the creator is live, and the
 *   latest 50 gifts (status, product, the buyer's chosen name, the winner).
 * - `POST /me/community/twitch-gifting`: saves on/off, the `!enter` window
 *   and the chat language (400 `merchboard_invalid_twitch_gifting` with a
 *   code per field). Pausing has its own route.
 * - `POST …/pause` `{paused}`: pauses or resumes; the chat is told.
 * - `POST …/start`: starts the waiting gifts (as `!gift start`);
 *   `started` says whether one started.
 *
 * Writes are blocked under "View as creator" (ImpersonationGuard): they
 * speak in the creator's chat.
 */
final class MeTwitchGiftingController extends RestController {

	/**
	 * Route below the namespace.
	 */
	public const ROUTE = '/me/community/twitch-gifting';

	/**
	 * Gifts listed.
	 */
	private const LISTED = 50;

	/**
	 * The login of a creator's connected Twitch channel, or null.
	 *
	 * @var Closure(int): ?string
	 */
	private Closure $channel;

	/**
	 * Whether Twitch is set up on the site (the app and the bot).
	 *
	 * @var Closure(): bool
	 */
	private Closure $ready;

	/**
	 * Whether a creator is live; null without a connected channel.
	 *
	 * @var Closure(int): ?bool
	 */
	private Closure $live;

	/**
	 * A product's name.
	 *
	 * @var Closure(int): string
	 */
	private Closure $product_name;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): ?string $channel
	 * @phpstan-param Closure(): bool $ready
	 * @phpstan-param Closure(int): ?bool $live
	 * @phpstan-param Closure(int): string $product_name
	 *
	 * @param CreatorContext            $context      Creator access policy.
	 * @param GiftingSettingsRepository $settings     The creators' gifting settings.
	 * @param GiftRepository            $gifts        Gifts.
	 * @param GiftDraws                 $draws        The draws.
	 * @param Closure                   $channel      The login of a creator's connected Twitch channel, or null.
	 * @param Closure                   $ready        Whether Twitch is set up on the site.
	 * @param Closure                   $live         Whether a creator is live.
	 * @param Closure                   $product_name A product's name.
	 * @param Logger                    $logger       Technical log.
	 */
	public function __construct(
		private CreatorContext $context,
		private GiftingSettingsRepository $settings,
		private GiftRepository $gifts,
		private GiftDraws $draws,
		Closure $channel,
		Closure $ready,
		Closure $live,
		Closure $product_name,
		Logger $logger
	) {
		parent::__construct( $logger );
		$this->channel      = $channel;
		$this->ready        = $ready;
		$this->live         = $live;
		$this->product_name = $product_name;
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
					'callback'            => array( $this, 'get_gifting' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/pause',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'pause' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/start',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);
	}

	/**
	 * Only creators with dashboard access.
	 */
	public function permission(): bool|WP_Error {
		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * `GET /me/community/twitch-gifting`.
	 */
	public function get_gifting(): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function (): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				return $creator instanceof WP_Error ? $creator : $this->answer( $creator->id, $this->settings->find( $creator->id ) );
			}
		);
	}

	/**
	 * `POST /me/community/twitch-gifting`: `enabled`, `window`, `language`.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$body   = (array) $request->get_json_params();
				$values = array( 'paused' => $this->settings->find( $creator->id )->paused );

				foreach ( array( 'enabled', 'window', 'language' ) as $field ) {
					$values[ $field ] = $body[ $field ] ?? null;
				}

				try {
					$saved = $this->settings->save( $creator->id, $values );
				} catch ( TwitchSubDiscountInvalid $e ) {
					return self::error( 'merchboard_invalid_twitch_gifting', __( 'Some fields of Twitch gifting are missing or invalid.', 'merchboard' ), 400, array( 'fields' => $e->fields() ) );
				}

				return $this->answer( $creator->id, $saved );
			}
		);
	}

	/**
	 * `POST /me/community/twitch-gifting/pause`: `{paused: bool}`.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function pause( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/pause',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$paused = ( (array) $request->get_json_params() )['paused'] ?? null;

				if ( ! is_bool( $paused ) ) {
					return self::error( 'merchboard_invalid_twitch_gifting', __( 'Some fields of Twitch gifting are missing or invalid.', 'merchboard' ), 400, array( 'fields' => array( 'paused' => 'required' ) ) );
				}

				if ( ! $this->settings->find( $creator->id )->enabled ) {
					return self::error( 'merchboard_twitch_gifting_off', __( 'Twitch gifting is turned off.', 'merchboard' ), 409 );
				}

				if ( $this->settings->find( $creator->id )->paused !== $paused ) {
					$this->draws->pause( $creator->id, $paused );
				}

				return $this->answer( $creator->id, $this->settings->find( $creator->id ) );
			}
		);
	}

	/**
	 * `POST /me/community/twitch-gifting/start`.
	 */
	public function start(): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/start',
			function (): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$settings = $this->settings->find( $creator->id );

				if ( ! $settings->open() ) {
					return self::error( 'merchboard_twitch_gifting_off', __( 'Twitch gifting is turned off or paused.', 'merchboard' ), 409 );
				}

				$started  = $this->draws->start( $creator->id );
				$response = $this->answer( $creator->id, $settings );
				$data     = (array) $response->get_data();
				$response->set_data( array( 'started' => $started ) + $data );

				return $response;
			}
		);
	}

	/**
	 * The answer to the creator.
	 *
	 * @param int             $creator_id Creator.
	 * @param GiftingSettings $settings   Settings.
	 * @throws \MerchBoard\Core\Database\DatabaseException When the gifts cannot be read.
	 */
	private function answer( int $creator_id, GiftingSettings $settings ): WP_REST_Response {
		$login    = ( $this->channel )( $creator_id );
		$names    = array();
		$response = new WP_REST_Response(
			array(
				'enabled'  => $settings->enabled,
				'paused'   => $settings->paused,
				'window'   => $settings->window,
				'language' => $settings->language,
				'limits'   => array(
					'window_min' => GiftingSettings::MIN_WINDOW,
					'window_max' => GiftingSettings::MAX_WINDOW,
				),
				'channel'  => null === $login ? null : array( 'login' => $login ),
				'twitch'   => ( $this->ready )(),
				'live'     => null === $login ? null : ( $this->live )( $creator_id ),
				'gifts'    => array_map(
					function ( Gift $gift ) use ( &$names ): array {
						$names[ $gift->product_id ] ??= ( $this->product_name )( $gift->product_id );

						return array(
							'id'             => $gift->id,
							'status'         => $gift->status,
							'product'        => $names[ $gift->product_id ],
							'buyer'          => $gift->buyer_name,
							'winner'         => $gift->winner_login,
							'draws'          => $gift->draws,
							'created_at'     => $gift->created_at,
							'claim_deadline' => Gift::WON === $gift->status ? $gift->claim_deadline : null,
						);
					},
					$this->gifts->latest( $creator_id, self::LISTED )
				),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
