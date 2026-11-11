<?php
/**
 * The creator's giveaways.
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
use MerchBoard\Modules\Community\Gifting\Gift;
use MerchBoard\Modules\Community\Gifting\GiftingSettings;
use MerchBoard\Modules\Community\Giveaways\Giveaway;
use MerchBoard\Modules\Community\Giveaways\GiveawayCheckout;
use MerchBoard\Modules\Community\Giveaways\GiveawayException;
use MerchBoard\Modules\Community\Giveaways\Giveaways;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `/me/community/giveaways` (ADR-110, ADR-007): the creator's giveaways,
 * the products that can be given away with their price, and the actions
 * create, start in the chat and cancel. Only while an administrator has
 * giveaway links switched on (404 otherwise).
 */
final class MeGiveawaysController extends RestController {

	public const ROUTE = '/me/community/giveaways';

	/**
	 * The products a creator can give away with their price.
	 *
	 * @var Closure(int): list<array<string, mixed>>
	 */
	private Closure $products;

	/**
	 * The available balance of a creator in the shop's currency (decimal), or null when unknown.
	 *
	 * @var Closure(int): ?string
	 */
	private Closure $available;

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
	 * The page of a gift's link.
	 *
	 * @var Closure(string): string
	 */
	private Closure $claim_url;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): list<array<string, mixed>> $products
	 * @phpstan-param Closure(int): ?string $available
	 * @phpstan-param Closure(int): ?string $channel
	 * @phpstan-param Closure(): bool $ready
	 * @phpstan-param Closure(int): ?bool $live
	 * @phpstan-param Closure(int): string $product_name
	 * @phpstan-param Closure(string): string $claim_url
	 *
	 * @param CreatorContext $context      Creator access policy.
	 * @param Giveaways      $giveaways    Giveaways.
	 * @param Closure        $products     The products a creator can give away.
	 * @param Closure        $available    A creator's available balance.
	 * @param Closure        $channel      The login of a creator's connected Twitch channel.
	 * @param Closure        $ready        Whether Twitch is set up on the site.
	 * @param Closure        $live         Whether a creator is live.
	 * @param Closure        $product_name A product's name.
	 * @param Closure        $claim_url    The page of a gift's link (by token).
	 * @param Logger         $logger       Technical log.
	 */
	public function __construct(
		private CreatorContext $context,
		private Giveaways $giveaways,
		Closure $products,
		Closure $available,
		Closure $channel,
		Closure $ready,
		Closure $live,
		Closure $product_name,
		Closure $claim_url,
		Logger $logger
	) {
		parent::__construct( $logger );
		$this->products     = $products;
		$this->available    = $available;
		$this->channel      = $channel;
		$this->ready        = $ready;
		$this->live         = $live;
		$this->product_name = $product_name;
		$this->claim_url    = $claim_url;
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
					'callback'            => array( $this, 'list' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);

		foreach ( array( 'start', 'cancel' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				self::ROUTE . '/(?P<id>\d+)/' . $action,
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, $action ),
						'permission_callback' => array( $this, 'permission' ),
						'args'                => array(
							'id' => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
					),
				)
			);
		}
	}

	/**
	 * Only creators with dashboard access, while an administrator has
	 * giveaway links switched on (ADR-108 pattern; 404 otherwise).
	 */
	public function permission(): bool|WP_Error {
		if ( ! Settings::giveaways_enabled() ) {
			return self::error( 'merchboard_community_disabled', __( 'Giveaways are not available.', 'merchboard' ), 404 );
		}

		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * `GET /me/community/giveaways`.
	 */
	public function list(): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function (): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				return $creator instanceof WP_Error ? $creator : $this->answer( $creator->id );
			}
		);
	}

	/**
	 * `POST /me/community/giveaways`: `product_id`, `mode` (`bot`|`manual`),
	 * `funding` (`balance`|`shop`), `window` (bot).
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$body    = (array) $request->get_json_params();
				$product = $body['product_id'] ?? null;
				$mode    = $body['mode'] ?? null;
				$funding = $body['funding'] ?? null;
				$window  = $body['window'] ?? GiftingSettings::DEFAULT_WINDOW;
				$fields  = array();

				if ( ! is_int( $product ) || $product < 1 ) {
					$fields['product_id'] = 'required';
				}

				if ( ! in_array( $mode, array( Giveaway::BOT, Giveaway::MANUAL ), true ) ) {
					$fields['mode'] = 'invalid';
				}

				if ( ! in_array( $funding, array( Giveaway::BALANCE, Giveaway::SHOP ), true ) ) {
					$fields['funding'] = 'invalid';
				}

				if ( ! is_int( $window ) || $window < GiftingSettings::MIN_WINDOW || $window > GiftingSettings::MAX_WINDOW ) {
					$fields['window'] = 'range';
				}

				if ( array() !== $fields ) {
					return self::error( 'merchboard_invalid_giveaway', __( 'Some fields of the giveaway are missing or invalid.', 'merchboard' ), 400, array( 'fields' => $fields ) );
				}

				try {
					$giveaway = $this->giveaways->create( $creator->id, (int) $product, (string) $mode, (string) $funding, (int) $window );
				} catch ( GiveawayException $e ) {
					return self::refused( $e );
				} catch ( PayoutException ) {
					return self::error( 'merchboard_giveaway_busy', __( 'Your balance is being updated. Please try again in a moment.', 'merchboard' ), 409 );
				}

				$response = $this->answer( $creator->id );
				$response->set_data( array( 'created' => $giveaway->id ) + (array) $response->get_data() );
				$response->set_status( 201 );

				return $response;
			}
		);
	}

	/**
	 * `POST /me/community/giveaways/{id}/start`: starts a bot giveaway in the chat.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/start',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				try {
					$started = $this->giveaways->start( $creator->id, (int) $request->get_param( 'id' ) );
				} catch ( GiveawayException $e ) {
					return self::refused( $e );
				}

				$response = $this->answer( $creator->id );
				$response->set_data( array( 'started' => $started ) + (array) $response->get_data() );

				return $response;
			}
		);
	}

	/**
	 * `POST /me/community/giveaways/{id}/cancel`.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/cancel',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				try {
					$this->giveaways->cancel( $creator->id, (int) $request->get_param( 'id' ) );
				} catch ( GiveawayException $e ) {
					return self::refused( $e );
				}

				return $this->answer( $creator->id );
			}
		);
	}

	/**
	 * The answer to the creator.
	 *
	 * @param int $creator_id Creator.
	 * @throws \MerchBoard\Core\Database\DatabaseException When the giveaways cannot be read.
	 */
	private function answer( int $creator_id ): WP_REST_Response {
		$login    = ( $this->channel )( $creator_id );
		$names    = array();
		$response = new WP_REST_Response(
			array(
				'giveaways' => array_map(
					function ( array $row ) use ( &$names ): array {
						$giveaway = $row['giveaway'];
						$gift     = $row['gift'];
						$names[ $giveaway->product_id ] ??= ( $this->product_name )( $giveaway->product_id );

						return array(
							'id'         => $giveaway->id,
							'status'     => $giveaway->status,
							'mode'       => $giveaway->mode,
							'funding'    => $giveaway->funding,
							'product'    => $names[ $giveaway->product_id ],
							'cost'       => $giveaway->cost,
							'currency'   => $giveaway->currency,
							'window'     => $giveaway->window_seconds,
							'created_at' => $giveaway->created_at,
							'pay_url'    => Giveaway::AWAITING_PAYMENT === $giveaway->status ? GiveawayCheckout::url( $giveaway->id ) : null,
							'gift'       => null === $gift ? null : $this->gift( $gift ),
						);
					},
					$this->giveaways->latest( $creator_id )
				),
				'products'  => ( $this->products )( $creator_id ),
				'available' => ( $this->available )( $creator_id ),
				'limits'    => array(
					'window_min'     => GiftingSettings::MIN_WINDOW,
					'window_max'     => GiftingSettings::MAX_WINDOW,
					'window_default' => GiftingSettings::DEFAULT_WINDOW,
					'max_open'       => Giveaways::MAX_OPEN,
				),
				'channel'   => null === $login ? null : array( 'login' => $login ),
				'twitch'    => ( $this->ready )(),
				'live'      => null === $login ? null : ( $this->live )( $creator_id ),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * A giveaway's gift for the creator: where it stands and, while it is an
	 * open link, the link to pass on.
	 *
	 * @param Gift $gift Gift.
	 * @return array<string, mixed>
	 */
	private function gift( Gift $gift ): array {
		return array(
			'status'   => $gift->status,
			'winner'   => $gift->winner_login,
			'draws'    => $gift->draws,
			'link'     => $gift->open_link() && null !== $gift->claim_token ? ( $this->claim_url )( $gift->claim_token ) : null,
			'deadline' => Gift::WON === $gift->status ? $gift->claim_deadline : null,
		);
	}

	/**
	 * A refused action as an error.
	 *
	 * @param GiveawayException $e Why.
	 */
	private static function refused( GiveawayException $e ): WP_Error {
		return match ( $e->getMessage() ) {
			GiveawayException::NOT_FOUND       => self::error( 'merchboard_giveaway_not_found', __( 'The giveaway was not found.', 'merchboard' ), 404 ),
			GiveawayException::DISABLED        => self::error( 'merchboard_community_disabled', __( 'Giveaways are not available.', 'merchboard' ), 404 ),
			GiveawayException::LIMIT           => self::error( 'merchboard_giveaway_limit', __( 'You have the most open giveaways allowed. Finish or cancel one first.', 'merchboard' ), 409 ),
			GiveawayException::PRODUCT         => self::error( 'merchboard_giveaway_product', __( 'This product cannot be given away.', 'merchboard' ), 400 ),
			GiveawayException::BALANCE         => self::error( 'merchboard_giveaway_balance', __( 'Your available balance does not cover the giveaway. Pay it in the shop instead.', 'merchboard' ), 409 ),
			GiveawayException::NOT_CANCELLABLE => self::error( 'merchboard_giveaway_not_cancellable', __( 'The giveaway cannot be cancelled any more.', 'merchboard' ), 409 ),
			default                            => self::error( 'merchboard_giveaway_refused', __( 'The giveaway cannot be changed right now.', 'merchboard' ), 409 ),
		};
	}
}
