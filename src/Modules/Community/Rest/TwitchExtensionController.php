<?php
/**
 * The Twitch Extension panel's data (public, the extension's JWT).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Rest;

use Closure;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Community\TwitchExtension\ExtensionJwt;
use MerchBoard\Modules\Community\TwitchExtension\TwitchExtensionApp;
use MerchBoard\Modules\Community\TwitchExtension\TwitchExtensionPanel;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /merchboard/v1/twitch-extension/panel` (ADR-112 pkt. 3): called by the
 * extension in a viewer's browser on twitch.tv with `Authorization: Bearer
 * <JWT>`. The channel comes only from the verified token. Answers the
 * creator's name and products (name and image); never a price, link,
 * supplier or personal data.
 *
 * - 503 `merchboard_twitch_extension_unavailable`: the extension is not set up.
 * - 401 `merchboard_twitch_extension_token`: no valid token.
 */
final class TwitchExtensionController extends RestController {

	/**
	 * The route.
	 */
	public const ROUTE = '/twitch-extension/panel';

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
	 * @param TwitchExtensionApp   $app    The extension's setup.
	 * @param TwitchExtensionPanel $panel  The panels.
	 * @param Logger               $logger Technical log.
	 * @param Closure|null         $now    Unix time now (tests).
	 */
	public function __construct( private TwitchExtensionApp $app, private TwitchExtensionPanel $panel, Logger $logger, ?Closure $now = null ) {
		parent::__construct( $logger );
		$this->now = $now ?? static fn(): int => time();
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
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'panel' ),
					// The extension's JWT is verified in panel(); viewers are not WordPress users.
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * The panel of the token's channel.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function panel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function () use ( $request ): WP_REST_Response|WP_Error {
				$key = $this->app->key();

				if ( null === $key ) {
					return self::error( 'merchboard_twitch_extension_unavailable', __( 'The Twitch Extension is not set up.', 'merchboard' ), 503 );
				}

				$header  = (string) $request->get_header( 'authorization' );
				$token   = 1 === preg_match( '/^Bearer\s+(\S+)$/i', trim( $header ), $match ) ? $match[1] : '';
				$channel = ExtensionJwt::channel( $token, $key, ( $this->now )() );

				if ( null === $channel ) {
					return self::error( 'merchboard_twitch_extension_token', __( 'The Twitch Extension token is missing or invalid.', 'merchboard' ), 401 );
				}

				$response = new WP_REST_Response( $this->panel->for_channel( $channel ), 200 );
				// The URL is the same for every channel: the browser must never reuse an answer (the shop caches it).
				$response->header( 'Cache-Control', 'no-store' );
				$response->header( 'Vary', 'Origin, Authorization' );

				return $response;
			}
		);
	}
}
