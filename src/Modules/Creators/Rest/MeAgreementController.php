<?php
/**
 * The creator's agreement in the dashboard.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Rest;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Creators\Agreement\Agreement;
use MerchBoard\Modules\Creators\Agreement\AgreementException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /merchboard/v1/me/agreement` (ADR-092): where the creator stands with
 * the agreement and the current text:
 * `{ required, must_accept, satisfied, accepted, version, published_at, text }`
 * (`version` 0 and `text` null before the first is published). A creator who
 * need not accept it gets the text all the same.
 *
 * `POST /merchboard/v1/me/agreement` with `{ version, adult }` accepts the
 * version the creator read; 400 `merchboard_agreement_refused` with
 * `data.reason` (`agreement_outdated`, `adult_required`,
 * `agreement_not_published`, `impersonating`).
 *
 * The creator is the one CreatorContext resolves (ADR-007); the routes take
 * no creator id.
 */
final class MeAgreementController extends RestController {

	public const ROUTE = '/me/agreement';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext $context   Creator access policy.
	 * @param Agreement      $agreement The agreement.
	 * @param Logger         $logger    Technical log.
	 */
	public function __construct( private CreatorContext $context, private Agreement $agreement, Logger $logger ) {
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
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'accept' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'version' => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
						),
						'adult'   => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Permission callback: an active creator for the current user.
	 *
	 * @return true|WP_Error
	 */
	public function permission(): bool|WP_Error {
		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * `GET /me/agreement`.
	 */
	public function show(): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function (): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$status  = $this->agreement->status( $creator );
				$current = $this->agreement->current();

				$response = new WP_REST_Response(
					array(
						'required'     => $status['required'],
						'must_accept'  => $status['must_accept'],
						'satisfied'    => $status['satisfied'],
						'accepted'     => $status['accepted'],
						'version'      => $current->version ?? 0,
						'published_at' => null === $current ? null : $current->published_at->format( 'Y-m-d\TH:i:s\Z' ),
						'text'         => null === $current ? null : Agreement::rendered( $current->body ),
					)
				);
				$response->header( 'Cache-Control', 'no-store' );

				return $response;
			}
		);
	}

	/**
	 * `POST /me/agreement`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function accept( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				try {
					$user = get_current_user_id();
					$this->agreement->accept( $creator, $user > 0 ? $user : null, (int) $request->get_param( 'version' ), true === $request->get_param( 'adult' ) );
				} catch ( AgreementException $e ) {
					return self::error( 'merchboard_agreement_refused', __( 'The agreement was not accepted.', 'merchboard' ), 400, array( 'reason' => $e->reason() ) );
				}

				return $this->show();
			}
		);
	}
}
