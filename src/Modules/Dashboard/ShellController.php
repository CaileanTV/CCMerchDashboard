<?php
/**
 * Creator dashboard entry point.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard;

use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Dashboard\Branding\BrandLogo;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Answers every dashboard request on `template_redirect` (priority 0, before
 * WordPress' canonical redirects and the theme):
 *
 * 1. logged out → redirect to the login form, back to the requested page;
 * 2. logged in without creator access (no capability, no linked creator,
 *    inactive creator, stale or deleted link, database error) → 403 page
 *    with a logout link, never a link into wp-admin;
 * 3. creator access → the standalone React shell (no theme, no wp_head();
 *    only the dashboard bundle and its WordPress dependencies), or 503 when
 *    the bundle has not been built.
 *
 * Access is decided by CreatorContext alone, on every request. The creator is
 * never taken from the URL, and the frontend gets no authority: it only
 * renders what the server allows.
 */
final class ShellController {

	/**
	 * Constructor.
	 *
	 * @param DashboardRoute    $route    Route.
	 * @param CreatorContext    $context  Creator access policy.
	 * @param DashboardAssets   $assets   Built frontend assets.
	 * @param DashboardSections $sections Section registry.
	 * @param ThemePreference   $themes   The user's dashboard theme.
	 * @param BrandLogo         $logo     The logo (ADR-042).
	 */
	public function __construct( private DashboardRoute $route, private CreatorContext $context, private DashboardAssets $assets, private DashboardSections $sections, private ThemePreference $themes, private BrandLogo $logo ) {
	}

	/**
	 * WordPress entry point (`template_redirect`).
	 */
	public function handle_request(): void {
		if ( ! $this->route->is_request() ) {
			return;
		}

		$response = $this->respond();

		foreach ( self::security_headers( $response->script_nonce ) as $name => $value ) {
			header( $name . ': ' . $value );
		}

		nocache_headers();

		if ( null !== $response->redirect ) {
			wp_safe_redirect( $response->redirect );
			exit;
		}

		status_header( $response->status );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		echo $this->render( $response ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The templates escape every value.
		exit;
	}

	/**
	 * The response for the current dashboard request.
	 */
	public function respond(): ShellResponse {
		$path = $this->route->path();

		if ( ! is_user_logged_in() ) {
			return ShellResponse::redirect( wp_login_url( $this->route->url( $path ) ) );
		}

		$common = array(
			'site_name'  => get_bloginfo( 'name' ),
			'logout_url' => $this->logout_url(),
		);

		if ( null === $this->context->current() ) {
			return ShellResponse::page( 403, ShellResponse::TEMPLATE_NO_ACCESS, $common );
		}

		if ( ! $this->assets->register( $this->bootstrap() ) ) {
			return ShellResponse::page( 503, ShellResponse::TEMPLATE_SHELL, array_merge( $common, array( 'assets_ready' => false ) ) );
		}

		// The user's explicit theme on <html> (ADR-044), so the first paint
		// already uses it. Null: no attribute, and CSS follows the system.
		$theme = $this->themes->for_user( get_current_user_id() );

		return ShellResponse::page(
			200,
			ShellResponse::TEMPLATE_SHELL,
			array_merge(
				$common,
				array(
					'assets_ready' => true,
					'theme'        => $theme,
				)
			),
			self::nonce()
		);
	}

	/**
	 * Bootstrap data for the frontend (docs/FRONTEND.md#bootstrap-data): only
	 * non-sensitive values. Nothing identifies the creator; that comes from
	 * the REST API (`GET /merchboard/v1/me`). The REST root and nonce are
	 * configured by WordPress' own `wp-api-fetch` script.
	 *
	 * @return array<string, mixed>
	 */
	public function bootstrap(): array {
		return array(
			'site_name'     => get_bloginfo( 'name' ),
			'dashboard_url' => $this->route->url(),
			'routing'       => '' === (string) get_option( 'permalink_structure' ) ? 'query' : 'path',
			'logout_url'    => $this->logout_url(),
			'login_url'     => wp_login_url( $this->route->url() ),
			'password_url'  => wp_lostpassword_url(),
			'locale'        => determine_locale(),
			'sections'      => $this->sections->for_current_user(),
			'logo'          => $this->logo_data(),
		);
	}

	/**
	 * The header logo (ADR-042): site configuration, not creator data. An
	 * admin logo replaces the site name, so its alt text is the site name;
	 * the default logo is a mark next to the site name, so it has an empty
	 * alt text. Null: no usable logo, the site name is shown as text.
	 *
	 * @return array{url: string, width: int, height: int, alt: string, custom: bool}|null
	 */
	private function logo_data(): ?array {
		$logo = $this->logo->resolve();

		if ( null === $logo ) {
			return null;
		}

		return array(
			'url'    => $logo['url'],
			'width'  => $logo['width'],
			'height' => $logo['height'],
			'alt'    => $logo['custom'] ? (string) get_bloginfo( 'name' ) : '',
			'custom' => $logo['custom'],
		);
	}

	/**
	 * Renders a page response. While rendering, every script tag WordPress
	 * prints (the bundle, its dependencies and their inline scripts) carries
	 * the response's CSP nonce.
	 *
	 * @param ShellResponse $response Page response.
	 * @throws RuntimeException When the response has no known template.
	 */
	public function render( ShellResponse $response ): string {
		$file = match ( $response->template ) {
			ShellResponse::TEMPLATE_SHELL     => 'shell.php',
			ShellResponse::TEMPLATE_NO_ACCESS => 'no-access.php',
			default                           => throw new RuntimeException( 'Unknown dashboard template.' ),
		};

		$merchboard_view = $response->view;
		$add_nonce       = null;

		if ( null !== $response->script_nonce ) {
			$nonce     = $response->script_nonce;
			$add_nonce = static function ( mixed $attributes ) use ( $nonce ): mixed {
				if ( is_array( $attributes ) ) {
					$attributes['nonce'] = $nonce;
				}

				return $attributes;
			};
			add_filter( 'wp_script_attributes', $add_nonce );
			add_filter( 'wp_inline_script_attributes', $add_nonce );
		}

		ob_start();

		try {
			require dirname( __DIR__, 3 ) . '/templates/dashboard/' . $file;
		} finally {
			if ( null !== $add_nonce ) {
				remove_filter( 'wp_script_attributes', $add_nonce );
				remove_filter( 'wp_inline_script_attributes', $add_nonce );
			}
		}

		return (string) ob_get_clean();
	}

	/**
	 * Security headers of every dashboard response (docs/SECURITY.md).
	 * Without a nonce the page may run no script at all; with one, only
	 * same-origin scripts and inline scripts carrying the nonce.
	 *
	 * @param string|null $script_nonce CSP nonce of the React shell.
	 * @return array<string, string>
	 */
	public static function security_headers( ?string $script_nonce = null ): array {
		$policy = null === $script_nonce
			? "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'self'"
			: "default-src 'none'; script-src 'self' 'nonce-" . $script_nonce . "'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data:; font-src 'self' https://fonts.gstatic.com; connect-src 'self'; form-action 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'self'";

		return array(
			'X-Robots-Tag'            => 'noindex, nofollow',
			'Referrer-Policy'         => 'strict-origin-when-cross-origin',
			'X-Content-Type-Options'  => 'nosniff',
			'X-Frame-Options'         => 'SAMEORIGIN',
			'Content-Security-Policy' => $policy,
		);
	}

	/**
	 * A fresh CSP nonce (128 bits, base64).
	 */
	private static function nonce(): string {
		return base64_encode( random_bytes( 16 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- CSP nonces are base64 by specification.
	}

	/**
	 * Logout URL back to the dashboard, as a plain URL: wp_logout_url()
	 * returns it HTML-escaped (`&amp;`), which must not end up in JSON or in
	 * attributes that are escaped again.
	 */
	private function logout_url(): string {
		return html_entity_decode( wp_logout_url( $this->route->url() ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
