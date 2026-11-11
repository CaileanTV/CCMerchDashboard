<?php
/**
 * The creator's Twitch subscriber discount in the dashboard.
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
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubCampaign;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountRepository;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `/me`-scoped (ADR-007; the creator only through CreatorContext):
 *
 * - `GET /me/community/twitch-sub-discount`: whether the discount is on,
 *   whether gifted subscriptions
 *   count, the campaigns (percents with up to 2 decimals), the creator's profit
 *   percent per product (the most a campaign may give is the lowest among
 *   its products), the connected Twitch channel and whether Twitch is set
 *   up on the site.
 * - `POST` (or `PUT`) `/me/community/twitch-sub-discount`: saves all
 *   campaigns (400
 *   `merchboard_invalid_twitch_sub_discount` with a code per field).
 *   Blocked under "View as creator" (ImpersonationGuard).
 */
final class MeTwitchSubDiscountController extends RestController {

	/**
	 * Route below the namespace.
	 */
	public const ROUTE = '/me/community/twitch-sub-discount';

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
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): ?string $channel
	 * @phpstan-param Closure(): bool $ready
	 *
	 * @param CreatorContext              $context  Creator access policy.
	 * @param TwitchSubDiscountRepository $settings The creators' settings.
	 * @param Closure                     $channel  The login of a creator's connected Twitch channel, or null.
	 * @param Closure                     $ready    Whether Twitch is set up on the site.
	 * @param Logger                      $logger   Technical log.
	 */
	public function __construct( private CreatorContext $context, private TwitchSubDiscountRepository $settings, Closure $channel, Closure $ready, Logger $logger ) {
		parent::__construct( $logger );
		$this->channel = $channel;
		$this->ready   = $ready;
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
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);
	}

	/**
	 * Only creators with dashboard access, while an administrator has the discount
	 * switched on (ADR-108; 404 otherwise).
	 */
	public function permission(): bool|WP_Error {
		if ( ! Settings::twitch_sub_discount_enabled() ) {
			return self::error( 'merchboard_community_disabled', __( 'The Twitch subscriber discount is not available.', 'merchboard' ), 404 );
		}

		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * `GET /me/community/twitch-sub-discount`.
	 */
	public function get_settings(): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function (): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				return $this->answer( $creator->id, $this->settings->find( $creator->id ) );
			}
		);
	}

	/**
	 * `PUT /me/community/twitch-sub-discount`: every field from the JSON body.
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
				$values = array();

				foreach ( array( 'gifted', 'campaigns' ) as $field ) {
					$values[ $field ] = $body[ $field ] ?? null;
				}

				if ( array_key_exists( 'enabled', $body ) ) {
					$values['enabled'] = $body['enabled'];
				}

				try {
					$saved = $this->settings->save( $creator->id, $values );
				} catch ( TwitchSubDiscountInvalid $e ) {
					return self::error( 'merchboard_invalid_twitch_sub_discount', __( 'Some fields of the Twitch subscriber discount are missing or invalid.', 'merchboard' ), 400, array( 'fields' => $e->fields() ) );
				}

				return $this->answer( $creator->id, $saved );
			}
		);
	}

	/**
	 * The answer to the creator.
	 *
	 * @param int                       $creator_id Creator.
	 * @param TwitchSubDiscountSettings $settings   Settings.
	 */
	private function answer( int $creator_id, TwitchSubDiscountSettings $settings ): WP_REST_Response {
		$login    = ( $this->channel )( $creator_id );
		$response = new WP_REST_Response(
			array(
				'enabled'         => $settings->enabled,
				'gifted'          => $settings->gifted,
				'campaigns'       => array_map(
					static fn( TwitchSubCampaign $campaign ): array => array(
						'id'          => $campaign->id,
						'name'        => $campaign->name,
						'enabled'     => $campaign->enabled,
						'tiers'       => array(
							'1' => $campaign->tiers[1] / 100,
							'2' => $campaign->tiers[2] / 100,
							'3' => $campaign->tiers[3] / 100,
						),
						'scope'       => $campaign->scope,
						'product_ids' => $campaign->product_ids,
						'starts'      => $campaign->starts,
						'ends'        => $campaign->ends,
					),
					$settings->campaigns
				),
				// The most a campaign may give is the lowest of these among its products.
				'profit_percents' => (object) array_map( static fn( int $bps ): float|int => $bps / 100, $this->settings->profit_percents( $creator_id ) ),
				'channel'         => null === $login ? null : array( 'login' => $login ),
				'twitch'          => ( $this->ready )(),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
