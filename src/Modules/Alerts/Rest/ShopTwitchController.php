<?php
/**
 * A shop customer's Twitch login and logout.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Rest;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerLogin;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerSession;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Links in the shop's cart and checkout (ADR-106 pkt. 2):
 *
 * - `GET /shop/twitch/login?target=cart|checkout&nonce=…` sends the
 *   customer to Twitch (302).
 * - `GET /shop/twitch/logout?target=cart|checkout&nonce=…` forgets the
 *   customer's Twitch login and remembered account, and goes back (302).
 *
 * Both need the nonce of the page that showed them (action
 * `woocommerce_merchboard_twitch_shop`: WooCommerce binds a guest's nonce of
 * a "woocommerce…" action to the guest's session); a logged-in customer's link also carries the
 * REST nonce, so the request runs as that user. Responses are never cached.
 */
final class ShopTwitchController extends RestController {

	/**
	 * The nonce action of the shop's links.
	 */
	public const NONCE_ACTION = 'woocommerce_merchboard_twitch_shop';

	/**
	 * Query argument of a gift's claim link (ADR-107).
	 */
	public const CLAIM_QUERY = 'merchboard_gift_claim';

	/**
	 * Query argument of the claim page's button (ADR-107).
	 */
	public const REDEEM_QUERY = 'merchboard_gift_redeem';

	/**
	 * Constructor.
	 *
	 * @param TwitchCustomerLogin   $login   The customer's Twitch login.
	 * @param TwitchCustomerSession $session The customer's session.
	 */
	public function __construct( private TwitchCustomerLogin $login, private TwitchCustomerSession $session ) {
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		foreach ( array( 'login', 'logout' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/shop/twitch/' . $action,
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, $action ),
						'permission_callback' => '__return_true',
						'args'                => array(
							'target' => array(
								'type'      => 'string',
								'maxLength' => 40,
							),
							'nonce'  => array(
								'type'      => 'string',
								'maxLength' => 64,
							),
						),
					),
				)
			);
		}
	}

	/**
	 * The shop URL of a login or logout link.
	 *
	 * @param string $action `login` or `logout`.
	 * @param string $target `cart`, `checkout`, `claim:<token>` or `redeem:<token>`.
	 */
	public static function link( string $action, string $target ): string {
		$args = array(
			'target' => $target,
			'nonce'  => wp_create_nonce( self::NONCE_ACTION ),
		);

		if ( is_user_logged_in() ) {
			$args['_wpnonce'] = wp_create_nonce( 'wp_rest' );
		}

		return add_query_arg( $args, rest_url( self::NAMESPACE . '/shop/twitch/' . $action ) );
	}

	/**
	 * `GET /shop/twitch/login`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function login( WP_REST_Request $request ): WP_REST_Response {
		$target = self::target( $request );
		// The customer's session first: a guest's nonce is bound to it.
		$this->session->account();

		if ( ! self::valid_nonce( $request ) ) {
			return self::redirect( add_query_arg( 'twitch', 'denied', self::target_url( $target ) ) );
		}

		return self::redirect( $this->login->start( $target, get_current_user_id() ), false );
	}

	/**
	 * `GET /shop/twitch/logout`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function logout( WP_REST_Request $request ): WP_REST_Response {
		$target = self::target( $request );
		$this->session->account();

		if ( self::valid_nonce( $request ) ) {
			$this->session->forget( get_current_user_id() );
		}

		return self::redirect( self::target_url( $target ) );
	}

	/**
	 * The shop page of a target.
	 *
	 * @param string $target `cart`, `checkout`, `claim:<token>` or `redeem:<token>`.
	 */
	public static function target_url( string $target ): string {
		// A gift's claim page and its button (ADR-107).
		if ( str_starts_with( $target, 'claim:' ) ) {
			return add_query_arg( self::CLAIM_QUERY, substr( $target, 6 ), home_url( '/' ) );
		}

		if ( str_starts_with( $target, 'redeem:' ) ) {
			return add_query_arg(
				array(
					self::CLAIM_QUERY  => substr( $target, 7 ),
					self::REDEEM_QUERY => '1',
				),
				home_url( '/' )
			);
		}

		if ( ! function_exists( 'wc_get_cart_url' ) ) {
			return home_url( '/' );
		}

		return 'checkout' === $target ? wc_get_checkout_url() : wc_get_cart_url();
	}

	/**
	 * The request's target (`cart` by default).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private static function target( WP_REST_Request $request ): string {
		$target = $request->get_param( 'target' );

		return TwitchCustomerLogin::valid_target( $target ) ? (string) $target : 'cart';
	}

	/**
	 * Whether the request carries the page's nonce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private static function valid_nonce( WP_REST_Request $request ): bool {
		$nonce = $request->get_param( 'nonce' );

		return is_string( $nonce ) && false !== wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}

	/**
	 * A redirect that is never cached.
	 *
	 * @param string $location Where to.
	 * @param bool   $local    Whether it must stay on the site (Twitch's own URL does not).
	 */
	private static function redirect( string $location, bool $local = true ): WP_REST_Response {
		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $local ? wp_validate_redirect( $location, home_url( '/' ) ) : $location );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Referrer-Policy', 'no-referrer' );

		return $response;
	}
}
