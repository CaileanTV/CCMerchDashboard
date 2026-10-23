<?php
/**
 * Public URL of the global alert overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\GlobalOverlay;

use MerchBoard\Modules\Dashboard\DashboardRoute;

defined( 'ABSPATH' ) || exit;

/**
 * `/merchboard-alerts/global-overlay/{id}/` (step 25.9.4) with its own query
 * var, so a creator's overlay id is never looked up as the global one and
 * the global id never as a creator's. While MerchAlert is inactive,
 * MerchAlert's global URL `/ccmerch-alert/overlay/{id}/` is answered too, so
 * an imported overlay keeps its OBS URL (ADR-065 pkt. 5).
 */
final class GlobalOverlayRoute {

	/**
	 * The query var.
	 */
	public const QUERY_VAR = 'merchboard_alert_global_overlay';

	/**
	 * The URL.
	 */
	public const REGEX = '^merchboard-alerts/global-overlay/([0-9a-f]{32})/?$';

	/**
	 * MerchAlert's URL of its global overlay.
	 */
	public const LEGACY_REGEX = '^ccmerch-alert/overlay/([0-9a-f]{32})/?$';

	/**
	 * Constructor.
	 *
	 * @param bool $legacy Whether to answer MerchAlert's URL (only while MerchAlert is inactive).
	 */
	public function __construct( private bool $legacy ) {
	}

	/**
	 * Adds the rewrite rules.
	 */
	public function add_rewrite_rules(): void {
		foreach ( $this->regexes() as $regex ) {
			add_rewrite_rule( $regex, 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		}
	}

	/**
	 * `query_vars`: the query var.
	 *
	 * @param mixed $vars Query vars.
	 * @return mixed
	 */
	public function add_query_vars( mixed $vars ): mixed {
		if ( is_array( $vars ) ) {
			$vars[] = self::QUERY_VAR;
		}

		return $vars;
	}

	/**
	 * Flushes the rules once when one is missing (after an update).
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
	 * The requested id, '' for none.
	 */
	public function requested_id(): string {
		$id = get_query_var( self::QUERY_VAR );

		return is_string( $id ) ? strtolower( $id ) : '';
	}

	/**
	 * The browser source URL.
	 *
	 * @param string $overlay_id Secret id.
	 */
	public static function url( string $overlay_id ): string {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( self::QUERY_VAR, rawurlencode( $overlay_id ), home_url( '/' ) );
		}

		return home_url( '/merchboard-alerts/global-overlay/' . rawurlencode( $overlay_id ) . '/' );
	}

	/**
	 * The rules' patterns.
	 *
	 * @return list<string>
	 */
	private function regexes(): array {
		return $this->legacy ? array( self::REGEX, self::LEGACY_REGEX ) : array( self::REGEX );
	}
}
