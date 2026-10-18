<?php
/**
 * Creator-scope REST routes: /me/payouts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Rest;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Http\Pagination;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\WooCommerce\Payouts\Payout;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalance;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutBalances;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutException;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutGiftCards;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutMethod;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRepository;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutRequests;
use MerchBoard\Modules\WooCommerce\Payouts\PayoutStatus;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\CommissionVat;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementCorrection;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocument;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementDocuments;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\SettlementPdf;
use MerchBoard\Modules\WooCommerce\Payouts\Settlements\VatDeduction;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's payouts (ADR-054, ADR-055, ADR-056):
 *
 * - `GET /me/payouts`: the creator's payouts, newest first, a page at a time
 *   (plain JSON array with `X-WP-Total` and `X-WP-TotalPages`);
 * - `GET /me/payouts/balance`: balance per currency, holding period,
 *   settlement start and whether bank and PayPal are available;
 * - `POST /me/payouts`: a new request (`currency`, `amount` or "all",
 *   `method`, `details`, `idempotency_key`); a resent key returns the stored
 *   payout with the same status code;
 * - `POST /me/payouts/{id}/cancel`: cancels the creator's own pending payout;
 * - `GET /me/payouts/{id}/gift-card`: the card of the creator's own completed
 *   gift card payout with its code, read live from the gift card plugin
 *   (ADR-057); never part of a list;
 * - `GET /me/payouts/{id}/settlement-document`: the settlement document of
 *   the creator's own completed payout (ADR-060);
 * - `GET /me/payouts/{id}/settlement-document/pdf`: the same document as a
 *   PDF download (SettlementPdf), made from the stored document on every
 *   request and never stored; with `?correction=K1` one of its correction
 *   documents with amounts (ADR-062 K10 and K15), whose amounts are part of
 *   the document's `corrections`.
 *
 * The creator comes only from CreatorContext (permission callback and again
 * in each endpoint, ADR-007); no creator id is read from the request. All
 * rules (lock, transaction, idempotency, limits, balance, encryption, audit)
 * are in PayoutRequests; this controller only parses input, maps refusals
 * to REST errors and shapes output. Payment details are only ever returned
 * as the masked hint. Every response is `Cache-Control: no-store, private`.
 * Registered by the WooCommerce module, so without WooCommerce the routes
 * do not exist.
 */
final class MePayoutsController extends RestController {

	/**
	 * Route below the namespace.
	 */
	public const ROUTE = '/me/payouts';

	/**
	 * The refusal error code; `data.reason` is PayoutException's reason.
	 */
	public const REFUSED = 'merchboard_payout_refused';

	/**
	 * The PDF route below the namespace (as registered).
	 */
	public const PDF_ROUTE = '/' . self::NAMESPACE . self::ROUTE . '/(?P<id>\d+)/settlement-document/pdf';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext           $context    Creator access policy.
	 * @param PayoutRequests           $requests   Requests and cancellations.
	 * @param PayoutBalances           $balances   Balances.
	 * @param PayoutRepository         $payouts    The creator's payouts (read only).
	 * @param Logger                   $logger     Technical log.
	 * @param PayoutGiftCards|null     $gift_cards The creator's view of gift cards; null = none.
	 * @param SettlementDocuments|null $documents The creator's settlement documents; null = none.
	 * @param SettlementPdf|null       $pdf       PDFs of settlement documents; null = none.
	 */
	public function __construct(
		private CreatorContext $context,
		private PayoutRequests $requests,
		private PayoutBalances $balances,
		private PayoutRepository $payouts,
		Logger $logger,
		private ?PayoutGiftCards $gift_cards = null,
		private ?SettlementDocuments $documents = null,
		private ?SettlementPdf $pdf = null
	) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_payouts' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => self::pagination_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_payout' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/balance',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_balance' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/(?P<id>\d+)/settlement-document',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settlement_document' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/(?P<id>\d+)/settlement-document/pdf',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settlement_document_pdf' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'id'         => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'correction' => array(
							'type'    => 'string',
							'pattern' => '^K[1-9][0-9]{0,2}$',
						),
					),
				),
			)
		);
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_pdf' ), 10, 3 );
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/(?P<id>\d+)/gift-card',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_gift_card' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/(?P<id>\d+)/cancel',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'cancel_payout' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
			)
		);
	}

	/**
	 * `GET /me/payouts/{id}/gift-card`: the card of the creator's own
	 * completed gift card payout, with its code. Another creator's id, or a
	 * payout without a card, is 404; a card that cannot be read now is 503
	 * with reason `gift_cards_unavailable` (the payout stays completed).
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_gift_card( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/{id}/gift-card',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$id = $request->get_param( 'id' );

				try {
					if ( null === $this->gift_cards ) {
						throw new PayoutException( PayoutException::NOT_FOUND );
					}

					$shown = $this->gift_cards->for_creator( $creator->id, is_numeric( $id ) ? (int) $id : 0 );
				} catch ( PayoutException $e ) {
					return self::refused( $e );
				}

				$card = $shown['card'];

				return self::no_store(
					new WP_REST_Response(
						array(
							'code'       => $shown['code'],
							'amount'     => SalesAmount::format( $card->amount_units ),
							'balance'    => SalesAmount::format( $card->balance_units ),
							'currency'   => $card->currency,
							'status'     => $card->status->value,
							'expires_at' => self::time( $card->expires_at?->setTimezone( new DateTimeZone( 'UTC' ) ) ),
						),
						200
					)
				);
			}
		);
	}

	/**
	 * `GET /me/payouts/{id}/settlement-document`: the settlement document of
	 * the creator's own completed payout with its rows and corrections
	 * (ADR-060), as issued; never recalculated. Another creator's id, or a
	 * payout without a document, is 404.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_settlement_document( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/{id}/settlement-document',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$id = $request->get_param( 'id' );

				try {
					if ( null === $this->documents ) {
						throw new PayoutException( PayoutException::NOT_FOUND );
					}

					$shown = $this->documents->for_creator( $creator->id, is_numeric( $id ) ? (int) $id : 0 );
				} catch ( PayoutException $e ) {
					return self::refused( $e );
				}

				return self::no_store( new WP_REST_Response( self::settlement_document( $shown ), 200 ) );
			}
		);
	}

	/**
	 * `GET /me/payouts/{id}/settlement-document/pdf`: the settlement document
	 * of the creator's own completed payout as a PDF (SettlementPdf). Another
	 * creator's id, or a payout without a document, is 404; a document that
	 * does not add up gives no PDF but a 500 (logged), and nothing is
	 * changed. The body is the PDF; serve_pdf() sends it as it is.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_settlement_document_pdf( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/{id}/settlement-document/pdf',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$id = $request->get_param( 'id' );

				try {
					if ( null === $this->documents || null === $this->pdf ) {
						throw new PayoutException( PayoutException::NOT_FOUND );
					}

					$shown = $this->documents->for_creator( $creator->id, is_numeric( $id ) ? (int) $id : 0 );
				} catch ( PayoutException $e ) {
					return self::refused( $e );
				}

				$suffix   = $request->get_param( 'correction' );
				$document = $shown['document'];

				if ( is_string( $suffix ) && '' !== $suffix ) {
					// A correction document with amounts of this payout's chain (ADR-062 K10 and K15).
					$document = SettlementPdf::correction( $shown, $suffix );

					if ( null === $document ) {
						return self::refused( new PayoutException( PayoutException::NOT_FOUND ) );
					}

					$pdf = $this->pdf->render_correction( $shown, $document );
				} else {
					$pdf = $this->pdf->render( $shown );
				}

				$response = new WP_REST_Response( $pdf, 200 );

				foreach ( SettlementPdf::headers( $document ) as $name => $value ) {
					$response->header( $name, $value );
				}

				return $response;
			}
		);
	}

	/**
	 * `rest_pre_serve_request`: sends a successful response of the PDF route
	 * as the PDF itself instead of JSON. The REST server has already sent the
	 * response's headers (`application/pdf`, attachment, `no-store`,
	 * `nosniff`); every other response is left to the server.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param bool            $served  Whether the request was already served.
	 * @param mixed           $result  The response.
	 * @param WP_REST_Request $request Request.
	 */
	public function serve_pdf( bool $served, mixed $result, WP_REST_Request $request ): bool {
		if ( $served || ! $result instanceof WP_REST_Response || 'GET' !== $request->get_method() || 200 !== $result->get_status()
			|| self::PDF_ROUTE !== $result->get_matched_route() || 'application/pdf' !== ( $result->get_headers()['Content-Type'] ?? null ) ) {
			return $served;
		}

		$body = $result->get_data();

		if ( ! is_string( $body ) || ! str_starts_with( $body, '%PDF-' ) ) {
			return $served;
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A PDF file, sent as application/pdf with nosniff.

		return true;
	}

	/**
	 * A settlement document as shown to its creator.
	 *
	 * @param array{document: SettlementDocument, rows: list<array<string, mixed>>, corrections: list<SettlementDocument>} $shown Document, rows and corrections.
	 * @return array<string, mixed>
	 */
	public static function settlement_document( array $shown ): array {
		$document = $shown['document'];
		$snapshot = $document->snapshot;
		$text     = static fn( mixed $value ): string => is_scalar( $value ) ? (string) $value : '';
		$creator  = is_array( $snapshot['creator'] ?? null ) ? $snapshot['creator'] : array();
		$company  = is_array( $snapshot['company'] ?? null ) ? $snapshot['company'] : array();
		$payout   = is_array( $snapshot['payout'] ?? null ) ? $snapshot['payout'] : array();
		$amount   = static fn( mixed $value ): string => SalesAmount::format( SalesAmount::parse( is_scalar( $value ) ? (string) $value : '0' ) );

		return array(
			'number'          => $document->number,
			'type'            => $document->type,
			'preliminary'     => SettlementPdf::preliminary( $document ),
			'issued_at'       => self::time( $document->issued_at ),
			'period_from'     => self::time( $document->period_from ),
			'period_to'       => self::time( $document->period_to ),
			'currency'        => $document->currency,
			'profit'          => SalesAmount::format( $document->profit_units ),
			'commission'      => SalesAmount::format( $document->commission_units ),
			'commission_vat'  => self::commission_vat( $document ),
			'vat_deduction'   => self::vat_deduction( $document ),
			'adjustment'      => SalesAmount::format( $document->adjustment_units ),
			'adjustment_refs' => SettlementPdf::adjustment_references( $document ),
			'opening_balance' => SalesAmount::format( $document->opening_units ),
			'payout'          => SalesAmount::format( $document->payout_units ),
			'closing_balance' => SalesAmount::format( $document->closing_units ),
			'vat_registered'  => $document->vat_registered,
			'creator_name'    => $text( $creator['name'] ?? '' ),
			'company_name'    => $text( $company['name'] ?? '' ),
			'company_address' => array_values( array_map( $text, is_array( $company['address'] ?? null ) ? $company['address'] : array() ) ),
			'master_data'     => self::master_data( $document ),
			'method'          => $text( $payout['method'] ?? '' ),
			'destination'     => $text( $payout['destination_hint'] ?? '' ),
			'rows'            => array_map(
				static fn( array $row ): array => array(
					'order_id'  => (int) $row['order_id'],
					'item_name' => (string) $row['item_name'],
					'type'      => (string) $row['type'],
					'quantity'  => (int) $row['quantity'],
					'net'       => $amount( $row['net_amount'] ),
					'tax'       => $amount( $row['tax_amount'] ),
					'payout'    => $amount( $row['payout_amount'] ),
				),
				$shown['rows']
			),
			'corrections'     => array_map(
				static fn( SettlementDocument $correction ): array => array(
					'number'      => $correction->number,
					'issued_at'   => self::time( $correction->issued_at ),
					'reason'      => is_scalar( $correction->snapshot['correction_reason'] ?? null ) ? (string) $correction->snapshot['correction_reason'] : '',
					'amounts'     => self::correction_amounts( $shown, $correction ),
					'master_data' => SettlementPdf::correction_master_data( $shown, $correction ),
				),
				$shown['corrections']
			),
		);
	}

	/**
	 * The amounts of a correction document (template version 5, ADR-062 K10
	 * and K15): the suffix of its PDF, the original and the corrected
	 * document, the VAT rate and the amounts before, after and the difference.
	 * Null for a correction document without amounts (D11 a), and for one
	 * whose amounts do not add up or do not match its chain (it has no PDF
	 * either).
	 *
	 * @param array{document: SettlementDocument, rows: list<array<string, mixed>>, corrections: list<SettlementDocument>} $shown      The original with its rows and corrections.
	 * @param SettlementDocument                                                                                           $correction Correction document.
	 * @return array<string, mixed>|null
	 */
	private static function correction_amounts( array $shown, SettlementDocument $correction ): ?array {
		$stored = SettlementPdf::verified_correction( $shown, $correction );

		if ( null === $stored ) {
			return null;
		}

		$format = static function ( array $amounts ): array {
			$result = array();

			foreach ( SettlementCorrection::AMOUNTS as $key ) {
				$result[ $key ] = SalesAmount::format( $amounts[ $key ] );
			}

			return $result;
		};

		return array(
			'suffix'     => 'K' . $stored['sequence'],
			'original'   => $stored['original'],
			'corrects'   => $stored['corrects'],
			'rate_bps'   => $stored['rate_bps'],
			'before'     => $format( $stored['before'] ),
			'after'      => $format( $stored['after'] ),
			'difference' => $format( $stored['difference'] ),
			'payout'     => SalesAmount::format( $stored['payout'] ),
		);
	}

	/**
	 * The VAT on the commission of a document (template version 3, ADR-062
	 * K05 and K12), or null when none was calculated: documents of earlier
	 * template versions, and commission of sales while the creator was not
	 * VAT registered (CommissionVat::shown()).
	 *
	 * @param SettlementDocument $document Document.
	 * @return array{rate_bps: int, excl: string, vat: string, incl: string, untaxed: string}|null
	 */
	private static function commission_vat( SettlementDocument $document ): ?array {
		$split = CommissionVat::shown( $document );

		return null === $split ? null : array(
			'rate_bps' => $split['rate_bps'],
			'excl'     => SalesAmount::format( $split['excl_units'] ),
			'vat'      => SalesAmount::format( $split['vat_units'] ),
			'incl'     => SalesAmount::format( $split['incl_units'] ),
			'untaxed'  => SalesAmount::format( $split['untaxed_units'] ),
		);
	}

	/**
	 * The VAT deducted from the payout of sales while the creator was not VAT
	 * registered (template version 6, ADR-064), or null when none was
	 * deducted or the document is older (VatDeduction::shown()).
	 *
	 * @param SettlementDocument $document Document.
	 * @return array{gross: string, deduction: string, net: string}|null
	 */
	private static function vat_deduction( SettlementDocument $document ): ?array {
		$deduction = VatDeduction::shown( $document );

		return null === $deduction ? null : array(
			'gross'     => SalesAmount::format( $deduction['gross_units'] ),
			'deduction' => SalesAmount::format( $deduction['deduction_units'] ),
			'net'       => SalesAmount::format( $deduction['net_units'] ),
		);
	}

	/**
	 * The master data of both parties as issued (template version 4, ADR-062
	 * K09), or null for earlier documents. '' and [] mean not registered at
	 * issue.
	 *
	 * @param SettlementDocument $document Document.
	 * @return array{creator_legal_name: string, creator_address: list<string>, creator_cvr: string, company_cvr: string}|null
	 */
	private static function master_data( SettlementDocument $document ): ?array {
		$master = SettlementPdf::master_data( $document );

		return null === $master ? null : array(
			'creator_legal_name' => $master['creator_legal_name'],
			'creator_address'    => $master['creator_address'],
			'creator_cvr'        => $master['creator_cvr'],
			'company_cvr'        => $master['company_cvr'],
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
	 * `GET /me/payouts`: a page of the creator's payouts.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_payouts( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$pagination = Pagination::from_request( $request );
				$total      = $this->payouts->count_for_creator( $creator->id );
				$payouts    = $pagination->offset() < $total ? $this->payouts->page_for_creator( $creator->id, $pagination->per_page, $pagination->offset() ) : array();
				$numbers    = null === $this->documents ? array() : $this->documents->numbers_for_payouts( array_map( static fn( Payout $payout ): int => $payout->id, $payouts ) );
				$items      = array_map( static fn( Payout $payout ): array => array( 'settlement_document' => $numbers[ $payout->id ] ?? null ) + self::payout( $payout ), $payouts );

				return self::no_store( self::paginated( $items, $total, $pagination ) );
			}
		);
	}

	/**
	 * `GET /me/payouts/balance`: balances, holding period, settlement start
	 * and method availability. An unknown balance has `status: unknown` and
	 * no amounts (null), never 0.
	 */
	public function get_balance(): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/balance',
			function (): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$start = $this->payouts->settlement_start( $creator->id );

				return self::no_store(
					new WP_REST_Response(
						array(
							'currencies'       => array_values( array_map( array( self::class, 'balance' ), $this->balances->for_creator( $creator->id ) ) ),
							'holding_days'     => Settings::payout_holding_days(),
							'payout_start'     => self::time( $start ),
							'methods'          => array_map( static fn( PayoutMethod $method ): string => $method->value, PayoutMethod::cases() ),
							'methods_usable'   => $this->requests->methods_available(),
							'gift_card_usable' => $this->requests->gift_cards_available(),
						),
						200
					)
				);
			}
		);
	}

	/**
	 * `POST /me/payouts`: requests a payout. 201 with the payout, also for a
	 * resent request key (the stored payout).
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_payout( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE,
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$body    = self::body( $request );
				$details = $body['details'] ?? null;
				$fields  = array();

				foreach ( array( 'currency', 'amount', 'method', 'idempotency_key' ) as $field ) {
					if ( ! is_string( $body[ $field ] ?? null ) || '' === $body[ $field ] ) {
						$fields[ $field ] = 'invalid';
					}
				}

				if ( ! is_array( $details ) ) {
					$fields['details'] = 'invalid';
				}

				if ( array() !== $fields || ! is_array( $details ) ) {
					return self::error( 'merchboard_invalid_payout', __( 'Some payout fields are missing or invalid.', 'merchboard' ), 400, array( 'fields' => $fields ) );
				}

				try {
					$payout = $this->requests->request(
						$creator->id,
						(string) $body['currency'],
						(string) $body['amount'],
						(string) $body['method'],
						$details,
						(string) $body['idempotency_key'],
						get_current_user_id()
					);
				} catch ( PayoutException $e ) {
					return self::refused( $e );
				}

				return self::no_store( new WP_REST_Response( self::payout( $payout ), 201 ) );
			}
		);
	}

	/**
	 * `POST /me/payouts/{id}/cancel`: cancels the creator's own pending
	 * payout. Another creator's id is 404, as a missing one.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function cancel_payout( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->run(
			self::ROUTE . '/{id}/cancel',
			function () use ( $request ): WP_REST_Response|WP_Error {
				$creator = $this->require_creator( $this->context );

				if ( $creator instanceof WP_Error ) {
					return $creator;
				}

				$id = $request->get_param( 'id' );

				try {
					$payout = $this->requests->cancel( $creator->id, is_numeric( $id ) ? (int) $id : 0, get_current_user_id() );
				} catch ( PayoutException $e ) {
					return self::refused( $e );
				}

				return self::no_store( new WP_REST_Response( self::payout( $payout ), 200 ) );
			}
		);
	}

	/**
	 * A refusal as a REST error with a status per reason.
	 *
	 * @param PayoutException $e Refusal.
	 */
	private static function refused( PayoutException $e ): WP_Error {
		$reason = $e->reason();

		if ( PayoutException::NOT_FOUND === $reason ) {
			return self::error( 'merchboard_not_found', __( 'The payout could not be found.', 'merchboard' ), 404 );
		}

		$status = match ( $reason ) {
			PayoutException::BALANCE_UNKNOWN, PayoutException::INSUFFICIENT_BALANCE, PayoutException::NOT_PENDING, PayoutException::BUSY => 409,
			PayoutException::TOO_MANY_PENDING, PayoutException::TOO_MANY_REQUESTS => 429,
			PayoutException::GIFT_CARDS_UNAVAILABLE => 503,
			default => 400,
		};

		return self::error( self::REFUSED, __( 'The payout request was not accepted.', 'merchboard' ), $status, array( 'reason' => $reason ) );
	}

	/**
	 * A payout as returned to its creator: the masked destination only.
	 *
	 * @param Payout $payout Payout.
	 * @return array<string, mixed>
	 */
	public static function payout( Payout $payout ): array {
		return array(
			'id'                  => $payout->id,
			'status'              => $payout->status->value,
			'currency'            => $payout->currency,
			'amount'              => SalesAmount::format( $payout->amount_units ),
			'method'              => $payout->method->value,
			'destination_hint'    => $payout->destination_hint,
			'requested_at'        => self::time( $payout->requested_at ),
			'decided_at'          => self::time( $payout->decided_at ),
			'rejection_reason'    => PayoutStatus::Rejected === $payout->status ? $payout->decision_reason : null,
			'gift_card'           => PayoutStatus::Completed === $payout->status && null !== $payout->gift_card_external_id,
			'settlement_document' => null,
		);
	}

	/**
	 * A balance; amounts are null when unknown.
	 *
	 * @param PayoutBalance $balance Balance.
	 * @return array<string, mixed>
	 */
	public static function balance( PayoutBalance $balance ): array {
		$amount = static fn( ?int $units ): ?string => null === $units ? null : SalesAmount::format( $units );

		return array(
			'currency'     => $balance->currency,
			'status'       => $balance->status(),
			'earned'       => $amount( $balance->earned_units ),
			'completed'    => SalesAmount::format( $balance->completed_units ),
			'reserved'     => SalesAmount::format( $balance->reserved_units ),
			'available'    => $amount( $balance->available_units() ),
			'outstanding'  => $amount( $balance->outstanding_units() ),
			'unknown_rows' => $balance->unknown_rows,
		);
	}

	/**
	 * A UTC time in ISO 8601, or null.
	 *
	 * @param DateTimeImmutable|null $time Time.
	 */
	private static function time( ?DateTimeImmutable $time ): ?string {
		return $time?->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Marks a response as private and never cached.
	 *
	 * @param WP_REST_Response $response Response.
	 */
	private static function no_store( WP_REST_Response $response ): WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * The JSON body (apiFetch) or form-encoded body; never query parameters.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<mixed>
	 */
	private static function body( WP_REST_Request $request ): array {
		$body = (array) $request->get_json_params();

		return array() === $body ? $request->get_body_params() : $body;
	}
}
