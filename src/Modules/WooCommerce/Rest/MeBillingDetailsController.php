<?php
/**
 * Creator-scope REST routes: /me/billing-details.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Rest;

use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\BillingDetailsInvalid;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\BillingDetailsReview;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\BillingDetailsState;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CreatorBillingDetails;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CvrLookup;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CvrLookupException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's own master data for settlement documents (ADR-104):
 * - `GET /me/billing-details`: the status, the saved master data, whether
 *   the first payout still needs them and whether one waits, and whether a
 *   private person's CPR number is stored (never the number);
 * - `POST /me/billing-details`: saves master data (every field of
 *   CreatorBillingDetails::FIELDS, `type` and, for a private person, `cpr`;
 *   blocked while viewing as the creator, ImpersonationGuard);
 * - `GET /me/billing-details/cvr/{cvr}`: name and address of a company from
 *   the CVR register for the form (ADR-105); nothing is stored.
 * The creator comes only from CreatorContext (ADR-007). Every response is
 * `Cache-Control: no-store, private`; values are never logged.
 */
final class MeBillingDetailsController extends RestController {

	/**
	 * Route below the namespace.
	 */
	public const ROUTE = '/me/billing-details';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext       $context Creator access policy.
	 * @param BillingDetailsReview $review  Master data and their review.
	 * @param Logger               $logger  Technical log.
	 * @param CvrLookup|null       $cvr     Lookups in the CVR register (ADR-105); null = none.
	 */
	public function __construct( private CreatorContext $context, private BillingDetailsReview $review, Logger $logger, private ?CvrLookup $cvr = null ) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$fields = array();

		foreach ( CreatorBillingDetails::FIELDS as $field => $max ) {
			$fields[ $field ] = array(
				'type'      => 'string',
				'maxLength' => 'cvr' === $field ? 20 : $max,
			);
		}

		$fields['cpr']  = array(
			'type'      => 'string',
			'maxLength' => 20,
		);
		$fields['type'] = array(
			'type' => 'string',
			'enum' => array( CreatorBillingDetails::BUSINESS, CreatorBillingDetails::PRIVATE ),
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_details' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => $fields,
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/cvr/(?P<cvr>\d{8})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'lookup_cvr' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);
	}

	/**
	 * `GET /me/billing-details/cvr/{cvr}`: the company's name and address.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function lookup_cvr( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/cvr/{cvr}',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				if ( null === $this->cvr ) {
					return self::error( 'merchboard_cvr_lookup', __( 'The CVR number could not be looked up.', 'merchboard' ), 503, array( 'reason' => CvrLookup::UNAVAILABLE ) );
				}

				try {
					$values = $this->cvr->lookup( (string) $request->get_param( 'cvr' ), $creator->id );
				} catch ( CvrLookupException $e ) {
					$status = match ( $e->reason() ) {
						CvrLookup::NOT_FOUND => 404,
						CvrLookup::INVALID   => 400,
						CvrLookup::LIMIT     => 429,
						default              => 503,
					};

					return self::error( 'merchboard_cvr_lookup', __( 'The CVR number could not be looked up.', 'merchboard' ), $status, array( 'reason' => $e->reason() ) );
				}

				return self::no_store( new WP_REST_Response( array( 'values' => $values ), 200 ) );
			}
		);
	}

	/**
	 * Only creators with dashboard access.
	 */
	public function permission(): bool|WP_Error {
		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * `GET /me/billing-details`.
	 */
	public function get_details(): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function (): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				return self::no_store( new WP_REST_Response( self::state( $this->review->state( $creator->id ) ) + array( 'cvr_lookup' => null !== $this->cvr && $this->cvr->available() ), 200 ) );
			}
		);
	}

	/**
	 * `POST /me/billing-details`: every field is read from the body (JSON or
	 * form, never the query); a missing field is empty.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$body   = (array) $request->get_json_params();
				$body   = array() === $body ? $request->get_body_params() : $body;
				$values = array();

				foreach ( array_merge( array_keys( CreatorBillingDetails::FIELDS ), array( 'type', 'cpr' ) ) as $field ) {
					$values[ $field ] = $body[ $field ] ?? '';
				}

				try {
					$this->review->submit( $creator->id, $values );
				} catch ( BillingDetailsInvalid $e ) {
					return self::error( 'merchboard_invalid_billing_details', __( 'Some master data fields are missing or invalid.', 'merchboard' ), 400, array( 'fields' => $e->fields() ) );
				} catch ( PayoutException $e ) {
					return self::error( 'merchboard_not_found', __( 'The creator could not be found.', 'merchboard' ), PayoutException::NOT_FOUND === $e->reason() ? 404 : 400 );
				}

				return self::no_store( new WP_REST_Response( self::state( $this->review->state( $creator->id ) ) + array( 'cvr_lookup' => null !== $this->cvr && $this->cvr->available() ), 200 ) );
			}
		);
	}

	/**
	 * The state as returned to the creator.
	 *
	 * @param BillingDetailsState $state State.
	 * @return array<string, mixed>
	 */
	public static function state( BillingDetailsState $state ): array {
		return array(
			'status'              => $state->status,
			'confirmed'           => null === $state->confirmed ? null : $state->confirmed->values(),
			'confirmed_at'        => null === $state->confirmed_at ? null : str_replace( ' ', 'T', $state->confirmed_at ) . 'Z',
			'required_for_payout' => $state->required,
			'payout_waiting'      => $state->payout_waiting,
			'cpr_required'        => $state->cpr_required,
			'cpr_saved'           => $state->cpr_saved,
		);
	}

	/**
	 * Never cached.
	 *
	 * @param WP_REST_Response $response Response.
	 */
	private static function no_store( WP_REST_Response $response ): WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
