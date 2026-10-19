<?php
/**
 * The public URL of an alert overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

use MerchBoard\Modules\Dashboard\DashboardRoute;

defined( 'ABSPATH' ) || exit;

/**
 * `/merchboard-alerts/overlay/{id}/`, and MerchAlert's
 * `/ccmerch-alert/creator-overlay/{id}/`, so OBS browser sources set up
 * with MerchAlert keep working after the import (ADR-065 pkt. 5, the
 * exception to ADR-018). The legacy rule is only added while MerchAlert is
 * inactive: until then MerchAlert answers its own URL. Without pretty
 * permalinks the overlay is `/?merchboard_alert_overlay={id}`.
 */
final class OverlayRoute {

	/**
	 * Query var holding the overlay id.
	 */
	public const QUERY_VAR = 'merchboard_alert_overlay';

	/**
	 * Rule of the overlay URL.
	 */
	public const REGEX = '^merchboard-alerts/overlay/([0-9a-f]{32})/?$';

	/**
	 * Rule of MerchAlert's creator overlay URL (ADR-065 pkt. 5).
	 */
	public const LEGACY_REGEX = '^ccmerch-alert/creator-overlay/([0-9a-f]{32})/?$';

	/**
	 * Constructor.
	 *
	 * @param bool $legacy Whether to answer MerchAlert's URL (false while MerchAlert is active).
	 */
	public function __construct( private bool $legacy ) {
	}

	/**
	 * Registers the rewrite rules (on `init`).
	 */
	public function add_rewrite_rules(): void {
		foreach ( $this->regexes() as $regex ) {
			add_rewrite_rule( $regex, 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		}
	}

	/**
	 * Makes the query var public.
	 *
	 * @param mixed $vars Public query vars.
	 * @return mixed
	 */
	public function add_query_vars( mixed $vars ): mixed {
		if ( is_array( $vars ) ) {
			$vars[] = self::QUERY_VAR;
		}

		return $vars;
	}

	/**
	 * Makes WordPress rebuild its rewrite rules on the next request when the
	 * stored rules lack one of the overlay rules (plugin updates skip
	 * activation; the legacy rule appears when MerchAlert is deactivated).
	 * One autoloaded option read.
	 */
	public function ensure_rewrite_rules(): void {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return;
		}

		$rules = get_option( 'rewrite_rules' );

		if ( ! is_array( $rules ) ) {
			return;
		}

		foreach ( $this->regexes() as $regex ) {
			if ( ! isset( $rules[ $regex ] ) ) {
				DashboardRoute::invalidate_rewrite_rules();
				return;
			}
		}
	}

	/**
	 * The requested overlay id, '' when this is not an overlay request.
	 */
	public function requested_id(): string {
		$id = get_query_var( self::QUERY_VAR );

		return is_string( $id ) ? strtolower( $id ) : '';
	}

	/**
	 * The overlay URL for OBS.
	 *
	 * @param string $overlay_id Secret overlay id.
	 */
	public static function url( string $overlay_id ): string {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( self::QUERY_VAR, rawurlencode( $overlay_id ), home_url( '/' ) );
		}

		return home_url( '/merchboard-alerts/overlay/' . rawurlencode( $overlay_id ) . '/' );
	}

	/**
	 * The rules in force.
	 *
	 * @return list<string>
	 */
	private function regexes(): array {
		return $this->legacy ? array( self::REGEX, self::LEGACY_REGEX ) : array( self::REGEX );
	}
}
