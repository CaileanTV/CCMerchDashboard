<?php
/**
 * Creator dashboard route.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard;

use MerchBoard\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The dashboard's own URL (docs/FRONTEND.md): `/<slug>/` and every deep link
 * below it map to the query var `merchboard_dashboard`. The slug comes from
 * the settings (default `creator-dashboard`) and is read on every call, so a
 * changed slug applies from the next request.
 *
 * Without pretty permalinks the dashboard is `/?merchboard_dashboard=1`.
 *
 * The route only identifies the request. It never identifies a creator: who
 * the creator is comes from CreatorContext (ADR-007), never from the URL.
 */
final class DashboardRoute {

	/**
	 * Query var that marks a dashboard request.
	 */
	public const QUERY_VAR = 'merchboard_dashboard';

	/**
	 * Query var with the deep-link path below the dashboard slug.
	 */
	public const PATH_VAR = 'merchboard_dashboard_path';

	/**
	 * WordPress' stored rewrite rules.
	 */
	private const RULES_OPTION = 'rewrite_rules';

	/**
	 * Current slug.
	 */
	public function slug(): string {
		return Settings::dashboard_slug();
	}

	/**
	 * Rewrite regex for the current slug.
	 */
	public function regex(): string {
		return '^' . $this->slug() . '(?:/(.*?))?/?$';
	}

	/**
	 * Registers the rewrite rule (on `init`). Added at the top so the dashboard
	 * is never shadowed by a page with the same slug; the settings refuse such
	 * slugs (SlugAvailability).
	 */
	public function add_rewrite_rule(): void {
		add_rewrite_rule( $this->regex(), 'index.php?' . self::QUERY_VAR . '=1&' . self::PATH_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Makes the query vars public.
	 *
	 * @param mixed $vars Public query vars.
	 * @return mixed
	 */
	public function add_query_vars( mixed $vars ): mixed {
		if ( is_array( $vars ) ) {
			$vars[] = self::QUERY_VAR;
			$vars[] = self::PATH_VAR;
		}

		return $vars;
	}

	/**
	 * Makes WordPress rebuild its rewrite rules on the next request if the
	 * stored rules lack the current dashboard rule. Covers plugin updates
	 * (which skip activation), slug changes and rules flushed while the plugin
	 * was inactive. One autoloaded option read.
	 */
	public function ensure_rewrite_rules(): void {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return;
		}

		$rules = get_option( self::RULES_OPTION );

		if ( is_array( $rules ) && ! isset( $rules[ $this->regex() ] ) ) {
			self::invalidate_rewrite_rules();
		}
	}

	/**
	 * Deletes the stored rewrite rules; WordPress rebuilds them on the next
	 * request from the rules registered then. Used on activation, deactivation
	 * and slug changes: unlike flush_rewrite_rules() it does not rebuild with
	 * the rules of the current request (which still contain the old slug, or
	 * the rule of a plugin being deactivated).
	 */
	public static function invalidate_rewrite_rules(): void {
		delete_option( self::RULES_OPTION );
	}

	/**
	 * Whether the main query is a dashboard request.
	 */
	public function is_request(): bool {
		return '1' === (string) get_query_var( self::QUERY_VAR );
	}

	/**
	 * The requested deep-link path, normalized to lowercase segments of
	 * letters, digits and hyphens ('' for the start page or anything else).
	 */
	public function path(): string {
		$path = get_query_var( self::PATH_VAR );

		return self::normalize_path( is_string( $path ) ? $path : '' );
	}

	/**
	 * Normalizes a deep-link path; '' when it is not a plain path.
	 *
	 * @param string $path Raw path.
	 */
	public static function normalize_path( string $path ): string {
		$path = trim( strtolower( $path ), '/' );

		if ( '' === $path || strlen( $path ) > 200 || 1 !== preg_match( '#^[a-z0-9-]+(?:/[a-z0-9-]+)*$#', $path ) ) {
			return '';
		}

		return $path;
	}

	/**
	 * Absolute URL of the dashboard or a deep link below it.
	 *
	 * @param string $path Deep-link path ('' for the start page).
	 */
	public function url( string $path = '' ): string {
		$path = self::normalize_path( $path );

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$args = array( self::QUERY_VAR => '1' );

			if ( '' !== $path ) {
				$args[ self::PATH_VAR ] = $path;
			}

			return add_query_arg( $args, home_url( '/' ) );
		}

		return home_url( '/' . $this->slug() . '/' . ( '' === $path ? '' : $path . '/' ) );
	}

	/**
	 * The dashboard URL a redirect target points to, or null when the target
	 * is anything else. Only same-site URLs whose path is exactly the dashboard
	 * or below it qualify (not `/<slug>-evil/`, not `..` segments, not other
	 * hosts); the result is rebuilt from the normalized path, so nothing else
	 * from the target (query, fragment, credentials) survives.
	 *
	 * @param string $target Redirect target from a request.
	 */
	public function dashboard_url_for( string $target ): ?string {
		$target = trim( $target );

		if ( '' === $target || '' === wp_validate_redirect( $target, '' ) ) {
			return null;
		}

		$decoded = rawurldecode( $target );

		if ( str_contains( $decoded, '..' ) || str_contains( $decoded, '\\' ) || str_contains( $decoded, '@' ) ) {
			return null;
		}

		$parts = wp_parse_url( $target );
		$home  = wp_parse_url( home_url( '/' ) );

		if ( ! is_array( $parts ) || ! is_array( $home ) ) {
			return null;
		}

		if ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return null;
		}

		if ( isset( $parts['host'] ) && strtolower( $parts['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
			return null;
		}

		$home_path = '/' . trim( (string) ( $home['path'] ?? '' ), '/' );
		$home_path = '/' === $home_path ? '/' : $home_path . '/';
		$path      = (string) ( $parts['path'] ?? '/' );

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			parse_str( (string) ( $parts['query'] ?? '' ), $query );

			if ( rtrim( $path, '/' ) . '/' !== $home_path || '1' !== ( $query[ self::QUERY_VAR ] ?? null ) ) {
				return null;
			}

			$deep = $query[ self::PATH_VAR ] ?? '';

			return $this->url( is_string( $deep ) ? $deep : '' );
		}

		$base = $home_path . $this->slug();

		if ( rtrim( $path, '/' ) === $base ) {
			return $this->url();
		}

		if ( ! str_starts_with( $path, $base . '/' ) ) {
			return null;
		}

		return $this->url( substr( $path, strlen( $base ) + 1 ) );
	}
}
