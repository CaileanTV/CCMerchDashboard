<?php
/**
 * Merch Dashboard branding of WordPress' own login screen.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard\Branding;

use MerchBoard\Modules\Dashboard\DashboardRoute;

defined( 'ABSPATH' ) || exit;

/**
 * Brands `wp-login.php` only in Merch Dashboard context (ADR-042, decision
 * 8–14). Presentation only: WordPress keeps the whole login flow (form, POST
 * target, credentials, cookies, test cookie, errors, CAPTCHA/2FA plugins,
 * redirects, logout, lost password and reset). No route, form, credential
 * handling, `wp_signon()`, `login_url` change or JavaScript.
 *
 * Context = all of:
 * 1. WordPress resolved the action to `login`: observed passively on
 *    `login_form_login`, which WordPress fires only for that action (sets a
 *    flag; no output, redirect, exit or change of the request);
 * 2. not the interim login (`interim-login`, wp-admin's session modal);
 * 3. `redirect_to` is a dashboard URL on this site
 *    (DashboardRoute::dashboard_url_for()). It is only a condition and never
 *    output; redirects stay WordPress' and LoginRedirect's.
 *
 * Every other screen (lost password, reset, register, …) and every other
 * login stay WordPress' default. The colors follow the system's
 * `prefers-color-scheme` in CSS; the dashboard theme is not involved.
 */
final class LoginBranding {

	/**
	 * Style handle.
	 */
	public const HANDLE = 'merchboard-login';

	/**
	 * Body class that scopes every rule of the stylesheet.
	 */
	public const BODY_CLASS = 'merchboard-login';

	/**
	 * Stylesheet, relative to the plugin root.
	 */
	public const STYLESHEET = 'assets/css/login.css';

	/**
	 * Whether this request is the login screen in Merch Dashboard context.
	 *
	 * @var bool
	 */
	private bool $active = false;

	/**
	 * Constructor.
	 *
	 * @param DashboardRoute $route Dashboard route (deep-link validation).
	 * @param BrandLogo      $logo  The logo.
	 * @param string         $root  Plugin root directory, with trailing slash.
	 */
	public function __construct( private DashboardRoute $route, private BrandLogo $logo, private string $root ) {
	}

	/**
	 * Registers the hooks. Only the observer runs on every login request; the
	 * presentation hooks do nothing unless it found the context.
	 */
	public function register(): void {
		add_action(
			'login_form_login',
			function (): void {
				// Read-only condition; WordPress' login has no nonce to check here.
				$this->observe( wp_unslash( $_REQUEST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared, never stored or printed; see observe().
			}
		);
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'login_body_class', array( $this, 'body_class' ) );
		add_filter( 'login_headerurl', array( $this, 'header_url' ) );
		add_filter( 'login_headertext', array( $this, 'header_text' ) );
	}

	/**
	 * Decides the context from the login request (called on
	 * `login_form_login`, i.e. only for the login action).
	 *
	 * @param array<mixed> $request Unslashed request parameters.
	 */
	public function observe( array $request ): void {
		$target = $request['redirect_to'] ?? null;

		$this->active = ! array_key_exists( 'interim-login', $request )
			&& is_string( $target )
			&& null !== $this->route->dashboard_url_for( $target );
	}

	/**
	 * Whether the branding applies to this request.
	 */
	public function is_active(): bool {
		return $this->active;
	}

	/**
	 * `login_enqueue_scripts`: the static stylesheet (after WordPress' own
	 * `login` style) and the logo URL as an inline rule.
	 */
	public function enqueue(): void {
		if ( ! $this->active ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			plugins_url( self::STYLESHEET, $this->root . 'merchboard.php' ),
			array( 'login' ),
			defined( 'MERCHBOARD_VERSION' ) ? (string) constant( 'MERCHBOARD_VERSION' ) : false
		);

		$css = $this->logo_css();

		if ( '' !== $css ) {
			wp_add_inline_style( self::HANDLE, $css );
		}
	}

	/**
	 * The inline rule with the logo, or '' (then WordPress' own logo stays).
	 * The URL comes from BrandLogo (same origin) and must consist of plain
	 * URL characters, so it cannot break out of `url("…")`.
	 */
	public function logo_css(): string {
		$logo = $this->logo->resolve();

		if ( null === $logo ) {
			return '';
		}

		$url = esc_url_raw( $logo['url'] );

		if ( 1 !== preg_match( '#^https?://[^\s"\'()\\\\<>;{}]+$#', $url ) ) {
			return '';
		}

		return 'body.' . self::BODY_CLASS . ' #login h1 a{background-image:url("' . $url . '");}';
	}

	/**
	 * `login_body_class`: adds the scoping class in context.
	 *
	 * @param mixed $classes Body classes.
	 * @return mixed
	 */
	public function body_class( mixed $classes ): mixed {
		if ( $this->active && is_array( $classes ) ) {
			$classes[] = self::BODY_CLASS;
		}

		return $classes;
	}

	/**
	 * `login_headerurl`: the site's front page instead of wordpress.org.
	 *
	 * @param mixed $url Logo link URL.
	 * @return mixed
	 */
	public function header_url( mixed $url ): mixed {
		return $this->active ? home_url( '/' ) : $url;
	}

	/**
	 * `login_headertext`: the site name as the logo link's accessible name
	 * (WordPress escapes it).
	 *
	 * @param mixed $text Logo link text.
	 * @return mixed
	 */
	public function header_text( mixed $text ): mixed {
		if ( ! $this->active ) {
			return $text;
		}

		$name = (string) get_bloginfo( 'name' );

		return '' === $name ? $text : $name;
	}
}
