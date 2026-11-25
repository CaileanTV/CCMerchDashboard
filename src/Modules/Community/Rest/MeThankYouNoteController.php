<?php
/**
 * The creator's thank-you note on the packing slip.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Rest;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Community\ThankYouNote\ThankYouNoteRepository;
use MerchBoard\Modules\Community\ThankYouNote\ThankYouNoteSettings;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET`/`POST /merchboard/v1/me/thank-you-note` (phase 58): on/off and the
 * text the customers find on the packing slip of an order with the
 * creator's products.
 */
final class MeThankYouNoteController extends RestController {

	/**
	 * The route.
	 */
	public const ROUTE = '/me/thank-you-note';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext         $context The current creator.
	 * @param ThankYouNoteRepository $notes   The creators' notes.
	 * @param Logger                 $logger  Technical log.
	 */
	public function __construct( private CreatorContext $context, private ThankYouNoteRepository $notes, Logger $logger ) {
		parent::__construct( $logger );
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
					'callback'            => array( $this, 'get_note' ),
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
	 * The creator's note.
	 */
	public function get_note(): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function (): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				return $creator instanceof WP_Error ? $creator : self::answer( $this->notes->find( $creator->id ) );
			}
		);
	}

	/**
	 * Saves the creator's note.
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
					$saved = $this->notes->save(
						$creator->id,
						array(
							'enabled' => $body['enabled'] ?? null,
							'text'    => $body['text'] ?? '',
						)
					);
				} catch ( TwitchSubDiscountInvalid $e ) {
					return self::error( 'merchboard_invalid_thank_you_note', __( 'The thank-you note is missing or invalid.', 'merchboard' ), 400, array( 'fields' => $e->fields() ) );
				}

				return self::answer( $saved );
			}
		);
	}

	/**
	 * The answer to the creator.
	 *
	 * @param ThankYouNoteSettings $note The note.
	 */
	private static function answer( ThankYouNoteSettings $note ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'enabled' => $note->enabled,
				'text'    => $note->text,
				'limits'  => array(
					'max_text'  => ThankYouNoteSettings::MAX_TEXT,
					'max_lines' => ThankYouNoteSettings::MAX_LINES,
				),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
