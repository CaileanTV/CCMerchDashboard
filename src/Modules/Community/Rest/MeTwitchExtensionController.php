<?php
/**
 * The creator's Twitch Extension panel.
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
use MerchBoard\Modules\Community\TwitchExtension\TwitchExtensionApp;
use MerchBoard\Modules\Community\TwitchExtension\TwitchExtensionPanel;
use MerchBoard\Modules\Community\TwitchExtension\TwitchExtensionRepository;
use MerchBoard\Modules\Community\TwitchExtension\TwitchExtensionSettings;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET`/`POST /merchboard/v1/me/community/twitch-extension` (ADR-112 pkt. 4):
 * the creator's panel (on/off and chosen products), a preview of what it
 * shows, the connected Twitch channel and where to install the extension.
 * 404 `merchboard_community_disabled` while the shop has it switched off.
 */
final class MeTwitchExtensionController extends RestController {

	/**
	 * The route.
	 */
	public const ROUTE = '/me/community/twitch-extension';

	/**
	 * The login of the creator's connected Twitch channel, or null.
	 *
	 * @var Closure(int): ?string
	 */
	private Closure $channel;

	/**
	 * Whether Twitch is set up on the site (the app and the bot).
	 *
	 * @var Closure(): bool
	 */
	private Closure $twitch;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): ?string $channel
	 * @phpstan-param Closure(): bool $twitch
	 *
	 * @param CreatorContext            $context  The current creator.
	 * @param TwitchExtensionRepository $settings The creators' panels.
	 * @param TwitchExtensionPanel      $panel    What a panel shows.
	 * @param TwitchExtensionApp        $app      The extension's setup.
	 * @param Closure                   $channel  The creator's Twitch login.
	 * @param Closure                   $twitch   Whether Twitch is set up on the site.
	 * @param Logger                    $logger   Technical log.
	 */
	public function __construct( private CreatorContext $context, private TwitchExtensionRepository $settings, private TwitchExtensionPanel $panel, private TwitchExtensionApp $app, Closure $channel, Closure $twitch, Logger $logger ) {
		parent::__construct( $logger );
		$this->channel = $channel;
		$this->twitch  = $twitch;
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
	 * A creator, while the shop has the panel switched on.
	 */
	public function permission(): bool|WP_Error {
		if ( ! Settings::twitch_extension_enabled() ) {
			return self::error( 'merchboard_community_disabled', __( 'The Twitch Extension is not available.', 'merchboard' ), 404 );
		}

		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * The creator's panel.
	 */
	public function get_settings(): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function (): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				return $creator instanceof WP_Error ? $creator : $this->answer( $creator->id, $this->settings->find( $creator->id ) );
			}
		);
	}

	/**
	 * Saves the creator's panel.
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

				$body = (array) $request->get_json_params();

				try {
					$saved = $this->settings->save(
						$creator->id,
						array(
							'enabled'     => $body['enabled'] ?? null,
							'product_ids' => $body['product_ids'] ?? null,
							'note'        => $body['note'] ?? '',
						)
					);
				} catch ( TwitchSubDiscountInvalid $e ) {
					return self::error( 'merchboard_invalid_twitch_extension', __( 'Some fields of the Twitch Extension panel are missing or invalid.', 'merchboard' ), 400, array( 'fields' => $e->fields() ) );
				}

				return $this->answer( $creator->id, $saved );
			}
		);
	}

	/**
	 * The answer to the creator.
	 *
	 * @param int                     $creator_id Creator.
	 * @param TwitchExtensionSettings $settings   Settings.
	 */
	private function answer( int $creator_id, TwitchExtensionSettings $settings ): WP_REST_Response {
		$login    = ( $this->channel )( $creator_id );
		$response = new WP_REST_Response(
			array(
				'enabled'      => $settings->enabled,
				'product_ids'  => $settings->product_ids,
				'max_products' => TwitchExtensionSettings::MAX_PRODUCTS,
				'note'         => $settings->note,
				'max_note'     => TwitchExtensionSettings::MAX_NOTE,
				// What the panel shows: the chosen products, or the newest.
				'preview'      => $this->panel->products( $creator_id ),
				'channel'      => null === $login ? null : array( 'login' => $login ),
				'twitch'       => ( $this->twitch )(),
				'ready'        => $this->app->ready(),
				'install_url'  => '' === $this->app->install_url() ? null : $this->app->install_url(),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
