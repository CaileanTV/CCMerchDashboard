<?php
/**
 * Creator-scope REST route: /me/sales.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Rest;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\WooCommerce\Dashboard\SalesReports;
use MerchBoard\Modules\WooCommerce\Sales\SalesInterval;
use MerchBoard\Modules\WooCommerce\Sales\SalesQuery;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /merchboard/v1/me/sales` (ADR-040 pkt. 11, docs/API.md): the creator's
 * sales statistics for a period, per currency.
 *
 * Flow: CreatorContext gives the creator (permission callback and again in
 * the endpoint); WordPress' argument validation checks the format of `from`,
 * `to` and `interval` (400 `rest_invalid_param`); SalesQuery applies the
 * defaults in the site's time zone and the limits (400
 * `merchboard_invalid_period`); SalesReports builds the body (in production
 * CachedSalesReports: the object cache around SalesReport, ADR-040 pkt. 10).
 * Other parameters are ignored. Registered by the WooCommerce module, so
 * without WooCommerce it does not exist.
 */
final class MeSalesController extends RestController {

	/**
	 * Route below the namespace.
	 */
	public const ROUTE = '/me/sales';

	/**
	 * Current time.
	 *
	 * @var Closure(): DateTimeImmutable
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @param CreatorContext                      $context Creator access policy.
	 * @param SalesReports                        $report  The creator's sales report.
	 * @param Logger                              $logger  Technical log.
	 * @param (Closure(): DateTimeImmutable)|null $clock   Current time (default: now).
	 */
	public function __construct( private CreatorContext $context, private SalesReports $report, Logger $logger, ?Closure $clock = null ) {
		parent::__construct( $logger );
		$this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now' );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		$date = array(
			'type'              => 'string',
			'validate_callback' => array( self::class, 'validate_date' ),
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_sales' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'from'     => $date,
						'to'       => $date,
						'interval' => array(
							'type'    => 'string',
							'enum'    => array_map( static fn( SalesInterval $interval ): string => $interval->value, SalesInterval::cases() ),
							'default' => SalesInterval::Day->value,
						),
					),
				),
				'schema' => array( $this, 'schema' ),
			)
		);
	}

	/**
	 * Argument validation of `from` and `to`: a string and a real date in the
	 * strict format `YYYY-MM-DD`.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param mixed           $value   Value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Parameter name.
	 * @return true|WP_Error
	 */
	public static function validate_date( mixed $value, WP_REST_Request $request, string $param ): bool|WP_Error {
		$valid = rest_validate_request_arg( $value, $request, $param );

		if ( true !== $valid ) {
			return $valid;
		}

		$date = is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value )
			? DateTimeImmutable::createFromFormat( '!Y-m-d', $value )
			: false;

		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			return new WP_Error( 'rest_invalid_param', __( 'Use a date in the format YYYY-MM-DD.', 'merchboard' ) );
		}

		return true;
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
	 * `GET /me/sales`: the creator's sales statistics for the period.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_sales( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$from     = $request->get_param( 'from' );
				$to       = $request->get_param( 'to' );
				$interval = $request->get_param( 'interval' );
				$interval = SalesInterval::tryFrom( is_string( $interval ) ? $interval : '' ) ?? SalesInterval::Day;

				try {
					$query = SalesQuery::resolve(
						is_string( $from ) ? $from : null,
						is_string( $to ) ? $to : null,
						$interval,
						wp_timezone(),
						( $this->clock )()
					);
				} catch ( InvalidArgumentException ) {
					return self::error( 'merchboard_invalid_period', __( 'The requested period is not valid.', 'merchboard' ), 400 );
				}

				return new WP_REST_Response( $this->report->build( $creator->id, $query ), 200 );
			}
		);
	}

	/**
	 * JSON schema of the `/me/sales` response.
	 *
	 * @return array<string, mixed>
	 */
	public function schema(): array {
		$text    = array(
			'type'     => 'string',
			'readonly' => true,
		);
		$integer = array(
			'type'     => 'integer',
			'readonly' => true,
		);
		// Amounts incl. VAT (ADR-053): null when a row of the period has no stored tax.
		$vat_text = array(
			'type'     => array( 'string', 'null' ),
			'readonly' => true,
		);
		$date     = array(
			'type'     => 'string',
			'format'   => 'date',
			'readonly' => true,
		);

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'merchboard_sales',
			'type'       => 'object',
			'properties' => array(
				'from'       => $date,
				'to'         => $date,
				'interval'   => $text,
				'timezone'   => $text,
				'currencies' => array(
					'type'     => 'array',
					'readonly' => true,
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'currency'  => $text,
							'totals'    => array(
								'type'       => 'object',
								'properties' => array(
									'net_sales'            => $text,
									'sales'                => $text,
									'adjustments'          => $text,
									'net_sales_incl_vat'   => $vat_text,
									'sales_incl_vat'       => $vat_text,
									'adjustments_incl_vat' => $vat_text,
									'units'                => $integer,
									'orders'               => $integer,
								),
							),
							'vat'       => array(
								'type'       => 'object',
								'readonly'   => true,
								'properties' => array(
									'status'       => array(
										'type'     => 'string',
										'enum'     => array( 'complete', 'incomplete' ),
										'readonly' => true,
									),
									'missing_rows' => $integer,
								),
							),
							'series'    => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'start'     => $date,
										'end'       => $date,
										'net_sales' => $text,
										'net_sales_incl_vat' => $vat_text,
										'units'     => $integer,
										'orders'    => $integer,
									),
								),
							),
							'products'  => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'product_id' => $integer,
										'name'       => $text,
										'net_sales'  => $text,
										'net_sales_incl_vat' => $vat_text,
										'units'      => $integer,
									),
								),
							),
							'truncated' => array(
								'type'     => 'boolean',
								'readonly' => true,
							),
						),
					),
				),
			),
		);
	}
}
