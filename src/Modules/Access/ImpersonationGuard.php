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
		array( 'POST', '#^/merchboard/v1/me/payouts$#' ),
		array( 'POST', '#^/merchboard/v1/me/payouts/\d+/cancel$#' ),
		array( 'GET', '#^/merchboard/v1/me/payouts/\d+/gift-card$#' ),
		array( 'POST', '#^/merchboard/v1/me/alerts/chatbot/[a-z]+/(connect|terms)$#' ),
		array( 'POST', '#^/merchboard/v1/me/alerts/api/keys$#' ),
		array( 'POST', '#^/merchboard/v1/me/alerts/api/webhooks/\d+/secret$#' ),
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
		if ( $response instanceof WP_Error || null === ImpersonationSession::creator_id() || ! self::blocked( $request ) ) {
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
		$route  = untrailingslashit( $request->get_route() );

		foreach ( self::BLOCKED_ROUTES as [ $blocked_method, $pattern ] ) {
			if ( $blocked_method === $method && 1 === preg_match( $pattern, $route ) ) {
				return true;
			}
		}

		// A JSON webhook without the receiver's secret gets one made and shown once.
		return 'POST' === $method
			&& '/merchboard/v1/me/alerts/api/webhooks' === $route
			&& 'discord' !== $request->get_param( 'format' )
			&& '' === trim( (string) ( is_scalar( $request->get_param( 'secret' ) ) ? $request->get_param( 'secret' ) : '' ) );
	}
}
