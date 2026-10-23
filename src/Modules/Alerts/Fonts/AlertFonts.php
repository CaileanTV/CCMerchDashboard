<?php
/**
 * The alert font library (Google Fonts).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Fonts;

use Closure;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The fonts creators may choose for their overlay (ADR-067).
 *
 * - **API key:** an administrator's Google Fonts API key, stored in an
 *   option without autoload and never shown again or logged.
 * - **Catalogue:** fetched on request from the Google Fonts Developer API
 *   (the only call to Google from the server) and kept for 30 days.
 * - **Approved fonts:** only families from the catalogue; creators can
 *   choose nothing else, and a font that is removed falls back to the
 *   default font everywhere.
 *
 * Every change requires `merchboard_manage_alerts` and is audited (the key
 * without its value). The library is stored in options instead of the
 * table ADR-065 pkt. 8 planned: it is small and read as a whole (ADR-065,
 * status note).
 */
final class AlertFonts {

	public const KEY_OPTION      = 'merchboard_alert_fonts_key';
	public const APPROVED_OPTION = 'merchboard_alert_fonts_approved';
	public const CATALOG_KEY     = 'merchboard_alert_fonts_catalog';

	/**
	 * The catalogue endpoint (ADR-067 pkt. 1).
	 */
	public const API_URL = 'https://www.googleapis.com/webfonts/v1/webfonts';

	/**
	 * Most approved fonts.
	 */
	public const MAX_APPROVED = 100;

	/**
	 * Sends a GET request; returns wp_remote_get()'s result.
	 *
	 * @var Closure(string, array<string, mixed>): mixed
	 */
	private Closure $http;

	/**
	 * Constructor.
	 *
	 * @param AuditTrail                                          $audit       Audit trail.
	 * @param Transaction                                         $transaction Database transaction.
	 * @param (Closure(string, array<string, mixed>): mixed)|null $http        HTTP GET; defaults to wp_remote_get().
	 */
	public function __construct( private AuditTrail $audit, private Transaction $transaction, ?Closure $http = null ) {
		$this->http = $http ?? static fn( string $url, array $args ): mixed => wp_remote_get( $url, $args );
	}

	/**
	 * Whether an API key is stored.
	 */
	public function has_key(): bool {
		return '' !== $this->key();
	}

	/**
	 * Stores the API key ('' removes it).
	 *
	 * @param string $key Google API key.
	 * @throws AlertFontsException When not authorized or the key has an invalid format.
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function set_key( string $key ): void {
		$this->authorize();
		$key = self::valid_key( $key );

		$this->transaction->run(
			function () use ( $key ): void {
				$this->audit->record( AuditAction::AlertFontsKeyChanged, null, array( 'removed' => '' === $key ) );

				if ( '' === $key ) {
					delete_option( self::KEY_OPTION );
				} elseif ( ! update_option( self::KEY_OPTION, $key, false ) && get_option( self::KEY_OPTION ) !== $key ) {
					throw new DatabaseException( 'Could not save the Google Fonts API key.' );
				}
			}
		);
	}

	/**
	 * Fetches the catalogue from Google and keeps it for 30 days.
	 *
	 * @return int Number of families.
	 * @throws AlertFontsException When not authorized, without a key, or when Google cannot be reached or answers with an error.
	 */
	public function sync(): int {
		$this->authorize();

		if ( ! $this->has_key() ) {
			throw new AlertFontsException( AlertFontsException::NO_KEY );
		}

		$response = ( $this->http )(
			add_query_arg(
				array(
					'key'  => rawurlencode( $this->key() ),
					'sort' => 'popularity',
				),
				self::API_URL
			),
			array(
				'timeout'     => 15,
				'redirection' => 0,
			)
		);

		$code = is_array( $response ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
		$body = is_array( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;

		if ( 200 !== $code || ! is_array( $body ) || ! is_array( $body['items'] ?? null ) ) {
			throw new AlertFontsException( 400 === $code || 403 === $code ? AlertFontsException::INVALID_KEY : AlertFontsException::UNAVAILABLE );
		}

		$catalog = array();

		foreach ( $body['items'] as $item ) {
			$font = AlertFont::from_array( $item );

			if ( null !== $font && ! isset( $catalog[ $font->family ] ) ) {
				$catalog[ $font->family ] = $font->to_array();
			}
		}

		set_transient( self::CATALOG_KEY, array_values( $catalog ), 30 * DAY_IN_SECONDS );
		$this->audit->record( AuditAction::AlertFontsSynced, null, array( 'families' => count( $catalog ) ) );

		return count( $catalog );
	}

	/**
	 * The catalogue families matching a search (case-insensitive), in
	 * Google's popularity order; empty before a sync.
	 *
	 * @param string $search Search text ('' for all).
	 * @param int    $limit  At most this many.
	 * @return list<AlertFont>
	 */
	public function catalog( string $search, int $limit ): array {
		$stored = get_transient( self::CATALOG_KEY );
		$fonts  = array();
		$search = strtolower( trim( $search ) );

		foreach ( is_array( $stored ) ? $stored : array() as $data ) {
			$font = AlertFont::from_array( $data );

			if ( null !== $font && ( '' === $search || str_contains( strtolower( $font->family ), $search ) ) ) {
				$fonts[] = $font;

				if ( count( $fonts ) >= $limit ) {
					break;
				}
			}
		}

		return $fonts;
	}

	/**
	 * Whether a catalogue is stored.
	 */
	public function has_catalog(): bool {
		$stored = get_transient( self::CATALOG_KEY );

		return is_array( $stored ) && array() !== $stored;
	}

	/**
	 * The approved fonts, by family name.
	 *
	 * @return array<string, AlertFont>
	 */
	public function approved(): array {
		$stored = get_option( self::APPROVED_OPTION, array() );
		$fonts  = array();

		foreach ( is_array( $stored ) ? $stored : array() as $data ) {
			$font = AlertFont::from_array( $data );

			if ( null !== $font ) {
				$fonts[ $font->family ] = $font;
			}
		}

		ksort( $fonts, SORT_NATURAL | SORT_FLAG_CASE );

		return $fonts;
	}

	/**
	 * An approved font by family name, or null.
	 *
	 * @param string $family Family name.
	 */
	public function find( string $family ): ?AlertFont {
		return $this->approved()[ $family ] ?? null;
	}

	/**
	 * Approves a family of the catalogue.
	 *
	 * @param string $family Family name.
	 * @return bool Whether it was newly approved.
	 * @throws AlertFontsException When not authorized, or the family is not in the catalogue.
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function approve( string $family ): bool {
		$this->authorize();

		$approved = $this->approved();

		if ( isset( $approved[ $family ] ) ) {
			return false;
		}

		$approved[ $family ] = $this->catalog_font( $family, count( $approved ) );
		$this->store( $approved, AuditAction::AlertFontApproved, $family );

		return true;
	}

	/**
	 * Approves fonts MerchAlert approved (the import, step 25.10.1), without
	 * the catalogue: they came from Google's catalogue there. Already
	 * approved ones stay; at most MAX_APPROVED in all. One audit record per
	 * new font, all in one transaction.
	 *
	 * @phpstan-param list<AlertFont> $fonts
	 *
	 * @param AlertFont[] $fonts Fonts.
	 * @return int How many were newly approved.
	 * @throws AlertFontsException When not authorized.
	 * @throws DatabaseException When they cannot be stored (nothing is kept).
	 */
	public function import_approved( array $fonts ): int {
		$this->authorize();

		$approved = $this->approved();
		$added    = array();

		foreach ( $fonts as $font ) {
			if ( count( $approved ) >= self::MAX_APPROVED ) {
				break;
			}

			if ( ! isset( $approved[ $font->family ] ) ) {
				$approved[ $font->family ] = $font;
				$added[]                   = $font->family;
			}
		}

		if ( array() === $added ) {
			return 0;
		}

		$value = array_values( array_map( static fn( AlertFont $font ): array => $font->to_array(), $approved ) );

		$this->transaction->run(
			function () use ( $value, $added ): void {
				foreach ( $added as $family ) {
					$this->audit->record( AuditAction::AlertFontApproved, null, array( 'family' => $family ) );
				}

				if ( ! update_option( self::APPROVED_OPTION, $value, false ) && get_option( self::APPROVED_OPTION ) !== $value ) {
					throw new DatabaseException( 'Could not save the approved fonts.' );
				}
			}
		);

		return count( $added );
	}

	/**
	 * Removes an approved font; overlays that use it show the default font.
	 *
	 * @param string $family Family name.
	 * @return bool Whether it was approved.
	 * @throws AlertFontsException When not authorized.
	 * @throws DatabaseException When it cannot be stored (nothing is kept).
	 */
	public function remove( string $family ): bool {
		$this->authorize();

		$approved = $this->approved();

		if ( ! isset( $approved[ $family ] ) ) {
			return false;
		}

		unset( $approved[ $family ] );
		$this->store( $approved, AuditAction::AlertFontRemoved, $family );

		return true;
	}

	/**
	 * Stores the approved fonts with an audit record in one transaction.
	 *
	 * @param array<string, AlertFont> $approved Approved fonts.
	 * @param AuditAction              $action   Audit action.
	 * @param string                   $family   Changed family.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function store( array $approved, AuditAction $action, string $family ): void {
		$value = array_values( array_map( static fn( AlertFont $font ): array => $font->to_array(), $approved ) );

		$this->transaction->run(
			function () use ( $value, $action, $family ): void {
				$this->audit->record( $action, null, array( 'family' => $family ) );

				if ( ! update_option( self::APPROVED_OPTION, $value, false ) && get_option( self::APPROVED_OPTION ) !== $value ) {
					throw new DatabaseException( 'Could not save the approved fonts.' );
				}
			}
		);
	}

	/**
	 * A trimmed API key, or '' to remove it.
	 *
	 * @param string $key Submitted key.
	 * @throws AlertFontsException When the key has an invalid format.
	 */
	private static function valid_key( string $key ): string {
		$key = trim( $key );

		if ( '' !== $key && 1 !== preg_match( '/^[A-Za-z0-9_-]{20,64}$/D', $key ) ) {
			throw new AlertFontsException( AlertFontsException::INVALID_KEY );
		}

		return $key;
	}

	/**
	 * A family of the catalogue that can still be approved.
	 *
	 * @param string $family   Family name.
	 * @param int    $approved Number of approved fonts.
	 * @throws AlertFontsException When the family is not in the catalogue or the limit is reached.
	 */
	private function catalog_font( string $family, int $approved ): AlertFont {
		if ( $approved < self::MAX_APPROVED ) {
			foreach ( $this->catalog( '', PHP_INT_MAX ) as $candidate ) {
				if ( $candidate->family === $family ) {
					return $candidate;
				}
			}
		}

		throw new AlertFontsException( AlertFontsException::UNKNOWN_FONT );
	}

	/**
	 * The stored API key, '' when none.
	 */
	private function key(): string {
		$key = get_option( self::KEY_OPTION, '' );

		return is_string( $key ) ? $key : '';
	}

	/**
	 * Requires the alerts capability (independent of the UI).
	 *
	 * @throws AlertFontsException When the current user lacks it.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			throw new AlertFontsException( AlertFontsException::NOT_AUTHORIZED );
		}
	}
}
