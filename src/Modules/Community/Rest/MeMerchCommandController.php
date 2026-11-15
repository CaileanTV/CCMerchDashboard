<?php
/**
 * The creator's !merch chat command.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Rest;

use Closure;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Community\MerchCommand\MerchCommandRepository;
use MerchBoard\Modules\Community\MerchCommand\MerchCommandSettings;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET`/`POST /merchboard/v1/me/alerts/chatbot/merch-command` (ADR-113 pkt.
 * 5): on/off, the text and the cooldown, the default text, an example of
 * the answer with the creator's collection link and the connected channels
 * (`twitch`, `kick`) the command works in.
 */
final class MeMerchCommandController extends RestController {

	/**
	 * The route.
	 */
	public const ROUTE = '/me/alerts/chatbot/merch-command';

	/**
	 * The link to a creator's collection.
	 *
	 * @var Closure(int): string
	 */
	private Closure $link;

	/**
	 * The creator's connected platforms that answer commands.
	 *
	 * @var Closure(int): list<string>
	 */
	private Closure $channels;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int): string $link
	 * @phpstan-param Closure(int): list<string> $channels
	 *
	 * @param CreatorContext         $context  The current creator.
	 * @param MerchCommandRepository $settings The creators' commands.
	 * @param Closure                $link     A creator's collection URL.
	 * @param Closure                $channels A creator's connected Twitch/Kick channels.
	 * @param Logger                 $logger   Technical log.
	 */
	public function __construct( private CreatorContext $context, private MerchCommandRepository $settings, Closure $link, Closure $channels, Logger $logger ) {
		parent::__construct( $logger );
		$this->link     = $link;
		$this->channels = $channels;
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
	 * A creator.
	 */
	public function permission(): bool|WP_Error {
		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * The creator's command.
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
	 * Saves the creator's command.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
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
							'enabled'  => $body['enabled'] ?? null,
							'text'     => $body['text'] ?? '',
							'cooldown' => $body['cooldown'] ?? null,
						)
					);
				} catch ( TwitchSubDiscountInvalid $e ) {
					return self::error( 'merchboard_invalid_merch_command', __( 'Some fields of the !merch command are missing or invalid.', 'merchboard' ), 400, array( 'fields' => $e->fields() ) );
				}

				return $this->answer( $creator->id, $saved );
			}
		);
	}

	/**
	 * The answer to the creator.
	 *
	 * @param int                  $creator_id Creator.
	 * @param MerchCommandSettings $settings   Settings.
	 */
	private function answer( int $creator_id, MerchCommandSettings $settings ): WP_REST_Response {
		$link     = ( $this->link )( $creator_id );
		$response = new WP_REST_Response(
			array(
				'enabled'      => $settings->enabled,
				'text'         => $settings->text,
				'cooldown'     => $settings->cooldown,
				'default_text' => MerchCommandSettings::default_text(),
				'example'      => MerchCommandSettings::message( $settings->text, $link ),
				'link'         => $link,
				'channels'     => ( $this->channels )( $creator_id ),
				'limits'       => array(
					'max_text' => MerchCommandSettings::MAX_TEXT,
					'cooldown' => array( MerchCommandSettings::MIN_COOLDOWN, MerchCommandSettings::MAX_COOLDOWN ),
				),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
