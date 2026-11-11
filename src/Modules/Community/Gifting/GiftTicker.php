<?php
/**
 * The clock of a gift's window.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

use Closure;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a gift's reminders, closing and winner on time (ADR-107 status note
 * 2026-11-11). Jobs run at most once a minute and only with traffic, and a
 * quiet chat sends nothing, so while a window is open the site calls itself:
 * a request waits until the next thing is due (at most MAX_WAIT seconds),
 * does it and calls itself again, until the gift has left its window.
 *
 * - `POST /merchboard/v1/gifts/{id}/tick` with `chain` and `token`: public,
 *   but only with the HMAC token of the gift's current chain; anything else
 *   is 403. A new window starts a new chain, which ends the old one.
 *
 * The jobs and the chat messages still do the same, should a call get lost.
 */
final class GiftTicker extends RestController {

	/**
	 * Route below the namespace.
	 */
	public const ROUTE = '/gifts/(?P<id>\d+)/tick';

	/**
	 * The longest a request waits before it calls itself again (hosts stop
	 * long requests).
	 */
	public const MAX_WAIT = 20;

	/**
	 * Calls the site without waiting for the answer.
	 *
	 * @var Closure(string, array<string, string>): void
	 */
	private Closure $post;

	/**
	 * Waits a number of seconds.
	 *
	 * @var Closure(int): void
	 */
	private Closure $sleep;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(string, array<string, string>): void $post
	 * @phpstan-param (Closure(int): void)|null $sleep
	 *
	 * @param GiftDraws    $draws  The draws.
	 * @param Closure      $post   Calls a URL of the site with a body, without waiting.
	 * @param Logger       $logger Technical log.
	 * @param Closure|null $sleep  Waits (default sleep()).
	 */
	public function __construct( private GiftDraws $draws, Closure $post, Logger $logger, ?Closure $sleep = null ) {
		parent::__construct( $logger );
		$this->post  = $post;
		$this->sleep = $sleep ?? static function ( int $seconds ): void {
			if ( $seconds > 0 ) {
				sleep( $seconds );
			}
		};
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle' ),
					// The HMAC token of the gift's current chain is checked in handle().
					'permission_callback' => '__return_true',
					'args'                => array(
						'chain' => array(
							'type'    => 'string',
							'pattern' => '^[0-9a-f]{32}$',
						),
						'token' => array(
							'type'    => 'string',
							'pattern' => '^[0-9a-f]{64}$',
						),
					),
				),
			)
		);
	}

	/**
	 * Starts the clock of a window that just opened (a new chain ends any
	 * older one).
	 *
	 * @param int $gift_id Gift.
	 */
	public function start( int $gift_id ): void {
		$chain = bin2hex( random_bytes( 16 ) );
		set_transient( self::chain_key( $gift_id ), $chain, HOUR_IN_SECONDS );
		$this->spawn( $gift_id, $chain );
	}

	/**
	 * `POST /gifts/{id}/tick`.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$gift_id = absint( $request->get_param( 'id' ) );
		$chain   = $request->get_param( 'chain' );
		$token   = $request->get_param( 'token' );

		if ( ! is_string( $chain ) || ! is_string( $token ) || ! hash_equals( self::token( $gift_id, $chain ), $token ) || ! $this->current( $gift_id, $chain ) ) {
			return new WP_REST_Response( null, 403 );
		}

		// The caller does not wait; the work goes on without it.
		ignore_user_abort( true );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( self::MAX_WAIT + 30 );
		}

		$this->step( $gift_id, $chain );

		return new WP_REST_Response( null, 202 );
	}

	/**
	 * Waits until the next thing in the window is due (at most MAX_WAIT
	 * seconds), does it and calls the site again while the window goes on.
	 *
	 * @param int    $gift_id Gift.
	 * @param string $chain   The chain.
	 */
	public function step( int $gift_id, string $chain ): void {
		try {
			$due = $this->draws->due_at( $gift_id );

			if ( null === $due ) {
				delete_transient( self::chain_key( $gift_id ) );

				return;
			}

			$wait = $due - time();

			if ( $wait > self::MAX_WAIT ) {
				( $this->sleep )( self::MAX_WAIT );
			} else {
				( $this->sleep )( max( 0, $wait ) );
				$this->draws->advance( $gift_id );
			}

			if ( $this->current( $gift_id, $chain ) && null !== $this->draws->due_at( $gift_id ) ) {
				$this->spawn( $gift_id, $chain );
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The clock of a gift could not go on; its jobs take over.', $e->context() );
		}
	}

	/**
	 * Calls the site for the next step of a chain.
	 *
	 * @param int    $gift_id Gift.
	 * @param string $chain   The chain.
	 */
	private function spawn( int $gift_id, string $chain ): void {
		( $this->post )(
			rest_url( self::NAMESPACE . '/gifts/' . $gift_id . '/tick' ),
			array(
				'chain' => $chain,
				'token' => self::token( $gift_id, $chain ),
			)
		);
	}

	/**
	 * Whether a chain is the gift's current one.
	 *
	 * @param int    $gift_id Gift.
	 * @param string $chain   The chain.
	 */
	private function current( int $gift_id, string $chain ): bool {
		$current = get_transient( self::chain_key( $gift_id ) );

		return is_string( $current ) && hash_equals( $current, $chain );
	}

	/**
	 * The token of a chain.
	 *
	 * @param int    $gift_id Gift.
	 * @param string $chain   The chain.
	 */
	public static function token( int $gift_id, string $chain ): string {
		return hash_hmac( 'sha256', 'gift-tick|' . $gift_id . '|' . $chain, wp_salt( 'auth' ) );
	}

	/**
	 * The transient of a gift's current chain.
	 *
	 * @param int $gift_id Gift.
	 */
	private static function chain_key( int $gift_id ): string {
		return 'merchboard_gift_tick_' . $gift_id;
	}
}
