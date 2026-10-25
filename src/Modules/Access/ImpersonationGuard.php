<?php
/**
 * What an administrator may not do as a creator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Access;

use MerchBoard\Core\Security\ImpersonationSession;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * During "view as creator" (phase 27, step 27.2, ADR-075) the administrator
 * may act as the creator, except where the result must stay the creator's
 * own (403 `merchboard_impersonation_blocked`, before the endpoint runs):
 *
 * - payouts: request and cancel (money to the creator's account);
 * - a gift card's code (a redeemable value);
 * - connecting a chatbot platform and accepting YouTube's terms (OAuth and
 *   the acceptance are bound to the logged-in user);
 * - new secrets shown once: an alert API key, a webhook signing secret
 *   (also a JSON webhook created without the receiver's secret).
 *
 * Routes match without regard to case, like WordPress' own routing (27.4
 * review). A creator-facing request (`/merchboard/v1/me…`) is marked for the
 * audit trail (ImpersonationSession::act()).
 */
final class ImpersonationGuard {

	/**
	 * The refusal's error code.
	 */
	public const BLOCKED = 'merchboard_impersonation_blocked';

	/**
	 * Refused routes: method and pattern of the route.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private const BLOCKED_ROUTES = array(
		array( 'POST', '#^/merchboard/v1/me/payouts$#i' ),
		array( 'POST', '#^/merchboard/v1/me/payouts/\d+/cancel$#i' ),
		array( 'GET', '#^/merchboard/v1/me/payouts/\d+/gift-card$#i' ),
		array( 'POST', '#^/merchboard/v1/me/alerts/chatbot/[a-z]+/(connect|terms)$#i' ),
		array( 'POST', '#^/merchboard/v1/me/alerts/api/keys$#i' ),
		array( 'POST', '#^/merchboard/v1/me/alerts/api/webhooks/\d+/secret$#i' ),
	);

	/**
	 * Registers the check.
	 */
	public function register(): void {
		add_filter( 'rest_request_before_callbacks', array( $this, 'check' ), 10, 3 );
	}

	/**
	 * Refuses a blocked request during a session; anything else passes.
	 *
	 * @param mixed           $response The response so far (an earlier error passes through).
	 * @param mixed           $handler  The route's handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function check( mixed $response, mixed $handler, WP_REST_Request $request ): mixed {
		if ( null === ImpersonationSession::creator_id() ) {
			return $response;
		}

		ImpersonationSession::act( 1 === preg_match( '#^/merchboard/v1/me(/|$)#i', $request->get_route() ) );

		if ( $response instanceof WP_Error || ! self::blocked( $request ) ) {
			return $response;
		}

		return new WP_Error(
			self::BLOCKED,
			__( 'Only the creator can do this. It is not possible while you view as the creator.', 'merchboard' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Whether the request is one the administrator may not make as the creator.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function blocked( WP_REST_Request $request ): bool {
		$method = strtoupper( $request->get_method() );
		$route  = strtolower( untrailingslashit( $request->get_route() ) );

		foreach ( self::BLOCKED_ROUTES as [ $blocked_method, $pattern ] ) {
			if ( $blocked_method === $method && 1 === preg_match( $pattern, $route ) ) {
				return true;
			}
		}

		// A JSON webhook without the receiver's secret gets one made and shown
		// once; read from the JSON body, like the endpoint does.
		$body   = $request->get_json_params();
		$secret = $body['secret'] ?? '';

		return 'POST' === $method
			&& '/merchboard/v1/me/alerts/api/webhooks' === $route
			&& 'discord' !== ( $body['format'] ?? null )
			&& '' === trim( is_scalar( $secret ) ? (string) $secret : '' );
	}
}
