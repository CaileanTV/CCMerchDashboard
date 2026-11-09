<?php
/**
 * Company data from the CVR register through cvrapi.dk.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Settlements;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * "Fill in from the CVR number" (ADR-105): the name and address of a company
 * from cvrapi.dk, which shows the Danish CVR register. Only the server calls
 * it, over HTTPS with a 5 second timeout and no redirects, sending only the
 * CVR number, with a User-Agent of the business' name and contact e-mail
 * (Settings → Company details) as cvrapi.dk requires; without them it is not
 * available.
 *
 * Answers are cached for a day per number (also "not found"); at most
 * SITE_LIMIT calls a day for the site and CREATOR_LIMIT per creator keep the
 * free 50 a day from being reached, and QUOTA_EXCEEDED stops calls for the
 * rest of the day. The answer is untrusted: only the name, address, postcode
 * and city are read as short single-line texts. Nothing is stored for the
 * creator; numbers and answers are never logged.
 */
final class CvrLookup {

	/**
	 * The API.
	 */
	public const URL = 'https://cvrapi.dk/api';

	/**
	 * Most calls to cvrapi.dk a day for the whole site (it allows 50).
	 */
	public const SITE_LIMIT = 40;

	/**
	 * Most calls to cvrapi.dk a day for one creator.
	 */
	public const CREATOR_LIMIT = 10;

	/**
	 * Refusal and failure codes.
	 */
	public const NOT_FOUND   = 'cvr_not_found';
	public const INVALID     = 'invalid_cvr';
	public const LIMIT       = 'cvr_lookup_limit';
	public const UNAVAILABLE = 'cvr_lookup_unavailable';

	/**
	 * Transient prefix.
	 */
	private const PREFIX = 'merchboard_cvr_';

	/**
	 * HTTP GET: `Closure(string, array<string, mixed>): mixed`.
	 *
	 * @var Closure
	 */
	private Closure $http;

	/**
	 * Current time: `Closure(): DateTimeImmutable`.
	 *
	 * @var Closure
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @phpstan-param (Closure(string, array<string, mixed>): mixed)|null $http
	 * @phpstan-param (Closure(): DateTimeImmutable)|null $clock
	 *
	 * @param Logger       $logger Technical log (codes only).
	 * @param Closure|null $http   HTTP GET; defaults to wp_remote_get().
	 * @param Closure|null $clock  Current time; defaults to now (UTC).
	 */
	public function __construct( private Logger $logger, ?Closure $http = null, ?Closure $clock = null ) {
		$this->http  = $http ?? static fn( string $url, array $args ): mixed => wp_remote_get( $url, $args );
		$this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * The User-Agent cvrapi.dk requires, or null when the business' name or
	 * contact e-mail is missing.
	 */
	public static function user_agent(): ?string {
		$settings = Settings::all();
		$name     = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $settings[ Settings::COMPANY_NAME ] ) );
		$email    = trim( (string) $settings[ Settings::COMPANY_EMAIL ] );

		return '' === $name || '' === $email ? null : $name . ' - Merch Dashboard - ' . $email;
	}

	/**
	 * Whether lookups can be made.
	 */
	public function available(): bool {
		return null !== self::user_agent();
	}

	/**
	 * The company's name and address for the form.
	 *
	 * @param string $cvr        CVR number (spaces allowed).
	 * @param int    $creator_id The creator asking (for the limit).
	 * @return array{legal_name: string, address_1: string, postcode: string, city: string, country: string}
	 * @throws CvrLookupException With one of the codes.
	 */
	public function lookup( string $cvr, int $creator_id ): array {
		$cvr = preg_replace( '/\s+/', '', $cvr ) ?? '';

		if ( 1 !== preg_match( '/^\d{8}$/', $cvr ) ) {
			throw new CvrLookupException( self::INVALID );
		}

		$agent = self::user_agent() ?? throw new CvrLookupException( self::UNAVAILABLE );
		$cache = get_transient( self::PREFIX . $cvr );

		if ( is_array( $cache ) ) {
			return self::cached( $cache );
		}

		$now  = ( $this->clock )()->setTimezone( new DateTimeZone( 'Europe/Copenhagen' ) );
		$day  = $now->format( 'Ymd' );
		$left = max( 60, (int) $now->setTime( 0, 0 )->modify( '+1 day' )->format( 'U' ) - (int) $now->format( 'U' ) );

		if ( false !== get_transient( self::PREFIX . 'quota' ) ) {
			throw new CvrLookupException( self::LIMIT );
		}

		$site    = (int) get_transient( self::PREFIX . 'site_' . $day );
		$creator = (int) get_transient( self::PREFIX . 'creator_' . $day . '_' . $creator_id );

		if ( $site >= self::SITE_LIMIT || $creator >= self::CREATOR_LIMIT ) {
			throw new CvrLookupException( self::LIMIT );
		}

		// Counted before the call: every attempt uses the daily allowance.
		set_transient( self::PREFIX . 'site_' . $day, $site + 1, $left );
		set_transient( self::PREFIX . 'creator_' . $day . '_' . $creator_id, $creator + 1, $left );

		$response = ( $this->http )(
			add_query_arg(
				array(
					'search'  => $cvr,
					'country' => 'dk',
				),
				self::URL
			),
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'user-agent'  => $agent,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);

		if ( $response instanceof WP_Error || ! is_array( $response ) ) {
			$this->logger->warning( 'The CVR lookup failed.', array( 'error' => $response instanceof WP_Error ? $response->get_error_code() : 'no_response' ) );
			throw new CvrLookupException( self::UNAVAILABLE );
		}

		$data  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$error = is_array( $data ) && is_string( $data['error'] ?? null ) ? $data['error'] : null;

		if ( 'QUOTA_EXCEEDED' === $error ) {
			set_transient( self::PREFIX . 'quota', 1, $left );
			throw new CvrLookupException( self::LIMIT );
		}

		if ( 'NOT_FOUND' === $error || 'INVALID_VAT' === $error ) {
			set_transient( self::PREFIX . $cvr, array( 'found' => false ), DAY_IN_SECONDS );
			throw new CvrLookupException( self::NOT_FOUND );
		}

		if ( null !== $error || ! is_array( $data ) ) {
			$this->logger->warning( 'The CVR lookup was refused.', array( 'error' => null === $error ? 'unreadable' : substr( (string) preg_replace( '/[^A-Z_]/', '', $error ), 0, 40 ) ) );
			throw new CvrLookupException( self::UNAVAILABLE );
		}

		$values = self::values( $data );

		if ( '' === $values['legal_name'] ) {
			set_transient( self::PREFIX . $cvr, array( 'found' => false ), DAY_IN_SECONDS );
			throw new CvrLookupException( self::NOT_FOUND );
		}

		set_transient(
			self::PREFIX . $cvr,
			array(
				'found'  => true,
				'values' => $values,
			),
			DAY_IN_SECONDS
		);

		return $values;
	}

	/**
	 * The values of the answer, each a valid single-line text or ''.
	 *
	 * @param array<mixed> $data Answer.
	 * @return array{legal_name: string, address_1: string, postcode: string, city: string, country: string}
	 */
	private static function values( array $data ): array {
		$text = static function ( mixed $value, int $max ): string {
			$value = is_int( $value ) ? (string) $value : $value;

			if ( ! is_string( $value ) ) {
				return '';
			}

			$value = trim( $value );

			return mb_strlen( $value ) <= $max && 1 === preg_match( '//u', $value ) && 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value ) ? $value : '';
		};

		return array(
			'legal_name' => $text( $data['name'] ?? null, CreatorBillingDetails::FIELDS['legal_name'] ),
			'address_1'  => $text( $data['address'] ?? null, CreatorBillingDetails::FIELDS['address_1'] ),
			'postcode'   => $text( $data['zipcode'] ?? null, CreatorBillingDetails::FIELDS['postcode'] ),
			'city'       => $text( $data['city'] ?? null, CreatorBillingDetails::FIELDS['city'] ),
			'country'    => __( 'Denmark', 'merchboard' ),
		);
	}

	/**
	 * A cached answer.
	 *
	 * @param array<mixed> $cache Cached value.
	 * @return array{legal_name: string, address_1: string, postcode: string, city: string, country: string}
	 * @throws CvrLookupException When it was not found.
	 */
	private static function cached( array $cache ): array {
		if ( true !== ( $cache['found'] ?? null ) || ! is_array( $cache['values'] ?? null ) ) {
			throw new CvrLookupException( self::NOT_FOUND );
		}

		$values = $cache['values'];
		$text   = static fn( string $key ): string => is_string( $values[ $key ] ?? null ) ? $values[ $key ] : '';

		return array(
			'legal_name' => $text( 'legal_name' ),
			'address_1'  => $text( 'address_1' ),
			'postcode'   => $text( 'postcode' ),
			'city'       => $text( 'city' ),
			'country'    => $text( 'country' ),
		);
	}
}
