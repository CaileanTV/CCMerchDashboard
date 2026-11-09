<?php
/**
 * MitCCMerch's own login screen on WordPress' wp-login.php.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard\Branding;

use Closure;
use MerchBoard\Modules\Dashboard\DashboardRoute;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-103 (replaces ADR-042 pkt. 8, 10, 11 and 13): `wp-login.php` gets
 * MitCCMerch's own layout on four screens, only in MitCCMerch context.
 * WordPress keeps the whole login flow (form, POST target, credentials,
 * cookies, test cookie, errors, CAPTCHA/2FA/lockout plugins, nonces,
 * redirects, logout and reset). No route, form, credential handling,
 * `wp_signon()`, `authenticate`, `wp_login`, `login_init`,
 * `secure_signon_cookie` or `login_errors`, and no JavaScript.
 *
 * The context, decided by passive observers on `login_form_{action}` (no
 * output, redirect or exit), only changes the look, never access:
 * - **login:** the login action, not the interim login, and a `redirect_to`
 *   that passes DashboardRoute::dashboard_url_for(). It also covers "logged
 *   out" (`loggedout`) and the receipt after a lost password
 *   (`checkemail=confirm`). `redirect_to` is only a condition, never output.
 * - **lostpassword:** the actions `lostpassword`/`retrievepassword` with the
 *   marker `merchboard=1`, which the "Lost your password?" link of the
 *   branded login carries (`lostpassword_url`, WordPress' own page also
 *   when WooCommerce is active) and the form keeps (`lostpassword_form`).
 *   After sending, `lostpassword_redirect` leads to the receipt on the
 *   branded login.
 * - **resetpass:** the actions `rp`/`resetpass` for a user (from the link or
 *   WordPress' own reset cookie `login:key`; only the login is read, the key
 *   never) who is linked to a creator, also a pending signup. WordPress
 *   validates the key itself and sends invalid links away. After the reset,
 *   "Log in" leads to the branded login (`login_url`).
 *
 * Every other login screen stays WordPress' default.
 */
final class LoginBranding {

	public const HANDLE = 'merchboard-login';

	/**
	 * Body class that scopes every rule of the stylesheet.
	 */
	public const BODY_CLASS = 'merchboard-login';

	public const STYLESHEET = 'assets/css/login.css';

	/**
	 * The marker of the lost-password screens (only a condition for the look).
	 */
	public const MARKER = 'merchboard';

	/**
	 * The screen in context ('' outside it): login, lostpassword, resetpass or loggedout.
	 *
	 * @var string
	 */
	private string $screen = '';

	/**
	 * Whether the lost-password screen is the receipt (an e-mail was sent).
	 *
	 * @var bool
	 */
	private bool $receipt = false;

	/**
	 * Whether a user is linked to a creator (also a pending signup).
	 *
	 * @var Closure(int): bool
	 */
	private Closure $is_creator;

	/**
	 * Constructor.
	 *
	 * @param DashboardRoute $route      Dashboard route (deep-link validation).
	 * @param BrandLogo      $logo       The main logo.
	 * @param BrandIcons     $icons      Favicon and app icon.
	 * @param string         $root       Plugin root directory, with trailing slash.
	 * @param Closure|null   $is_creator Whether a user is linked to a creator.
	 * @param Closure|null   $signup_url The signup page's URL while signing up is open, else null.
	 *
	 * @phpstan-param (Closure(int): bool)|null $is_creator
	 * @phpstan-param (Closure(): ?string)|null $signup_url
	 */
	public function __construct(
		private DashboardRoute $route,
		private BrandLogo $logo,
		private BrandIcons $icons,
		private string $root,
		?Closure $is_creator = null,
		private ?Closure $signup_url = null
	) {
		// Without a lookup no reset screen is branded.
		$this->is_creator = $is_creator ?? static fn( int $user_id ): bool => 0 > $user_id;
	}

	/**
	 * Registers the hooks. Only the observers run on every login request;
	 * the rest do nothing outside the context.
	 */
	public function register(): void {
		// Read-only conditions; WordPress' login screens have no nonce to check here, and nothing is stored or printed.
		add_action( 'login_form_login', fn() => $this->observe( wp_unslash( $_REQUEST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See observe().
		add_action( 'login_form_lostpassword', fn() => $this->observe_lost( wp_unslash( $_REQUEST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See observe_lost().
		add_action( 'login_form_retrievepassword', fn() => $this->observe_lost( wp_unslash( $_REQUEST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See observe_lost().
		add_action( 'login_form_rp', fn() => $this->observe_reset( wp_unslash( $_GET ), $_COOKIE ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See observe_reset().
		add_action( 'login_form_resetpass', fn() => $this->observe_reset( wp_unslash( $_GET ), $_COOKIE ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See observe_reset().
		add_action( 'login_form_checkemail', fn() => $this->observe_receipt( wp_unslash( $_REQUEST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See observe_receipt().

		add_action( 'login_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'login_head', array( $this, 'head' ) );
		add_filter( 'login_body_class', array( $this, 'body_class' ) );
		add_filter( 'login_headerurl', array( $this, 'header_url' ) );
		add_filter( 'login_headertext', array( $this, 'header_text' ) );
		add_action( 'login_header', array( $this, 'open_layout' ) );
		add_action( 'login_footer', array( $this, 'close_layout' ) );
		add_filter( 'login_message', array( $this, 'message' ) );
		add_filter( 'lostpassword_url', array( $this, 'lostpassword_url' ), 20 );
		add_action( 'lostpassword_form', array( $this, 'lostpassword_marker' ) );
		add_filter( 'lostpassword_redirect', array( $this, 'lostpassword_redirect' ) );
		add_filter( 'login_url', array( $this, 'login_url' ), 10, 2 );
	}

	/**
	 * The login action: in context when it leads to the dashboard and is not
	 * the interim login.
	 *
	 * @param array<mixed> $request Unslashed request parameters.
	 */
	public function observe( array $request ): void {
		$target = $request['redirect_to'] ?? null;

		if ( array_key_exists( 'interim-login', $request ) || ! is_string( $target ) || null === $this->route->dashboard_url_for( $target ) ) {
			$this->screen  = '';
			$this->receipt = false;

			return;
		}

		$this->receipt = 'confirm' === ( $request['checkemail'] ?? null );
		$this->screen  = match ( true ) {
			'true' === ( $request['loggedout'] ?? null ) => 'loggedout',
			$this->receipt => 'lostpassword',
			default => 'login',
		};
	}

	/**
	 * The receipt after a lost-password request (WordPress' own `checkemail`
	 * action): in context only for `checkemail=confirm` on its way to the
	 * dashboard, as sent by lostpassword_redirect(). Other receipts, such as
	 * WordPress' "registered", stay WordPress' own.
	 *
	 * @param array<mixed> $request Unslashed request parameters.
	 */
	public function observe_receipt( array $request ): void {
		if ( 'confirm' !== ( $request['checkemail'] ?? null ) ) {
			$this->screen  = '';
			$this->receipt = false;

			return;
		}

		$this->observe( $request );
	}

	/**
	 * The lost-password actions: in context with the marker.
	 *
	 * @param array<mixed> $request Unslashed request parameters.
	 */
	public function observe_lost( array $request ): void {
		$this->screen  = '1' === ( $request[ self::MARKER ] ?? null ) ? 'lostpassword' : '';
		$this->receipt = false;
	}

	/**
	 * The reset actions: in context for a user linked to a creator. The user
	 * comes from the link (`login`) or WordPress' reset cookie (`login:key`);
	 * the key is never read.
	 *
	 * @param array<mixed> $query  Unslashed query parameters.
	 * @param array<mixed> $cookie Cookies.
	 */
	public function observe_reset( array $query, array $cookie ): void {
		$login = is_string( $query['login'] ?? null ) ? $query['login'] : '';
		$name  = 'wp-resetpass-' . ( defined( 'COOKIEHASH' ) ? (string) constant( 'COOKIEHASH' ) : '' );

		if ( '' === $login && is_string( $cookie[ $name ] ?? null ) && str_contains( $cookie[ $name ], ':' ) ) {
			$login = explode( ':', wp_unslash( $cookie[ $name ] ), 2 )[0];
		}

		$user          = '' === $login ? false : get_user_by( 'login', sanitize_user( $login ) );
		$this->screen  = false !== $user && ( $this->is_creator )( (int) $user->ID ) ? 'resetpass' : '';
		$this->receipt = false;
	}

	/**
	 * Whether this request is a login screen in MitCCMerch context.
	 */
	public function is_active(): bool {
		return '' !== $this->screen;
	}

	/**
	 * The screen in context, or ''.
	 */
	public function screen(): string {
		return $this->screen;
	}

	/**
	 * `login_enqueue_scripts`: the stylesheet and the design's values as an
	 * inline rule.
	 */
	public function enqueue(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			plugins_url( self::STYLESHEET, $this->root . 'merchboard.php' ),
			array( 'login' ),
			defined( 'MERCHBOARD_VERSION' ) ? (string) constant( 'MERCHBOARD_VERSION' ) : false
		);

		wp_add_inline_style( self::HANDLE, $this->inline_css( BrandingDesign::read() ) );
	}

	/**
	 * The design as custom properties and the logo as a background. Colours
	 * are #rrggbb from BrandingDesign; URLs must consist of plain URL
	 * characters, so they cannot break out of `url("…")`.
	 *
	 * @param array<string, mixed> $design The design (BrandingDesign::read()).
	 */
	public function inline_css( array $design ): string {
		$rules = array(
			'--merchboard-login-bg:' . (string) $design['background_color'],
			'--merchboard-login-panel-text:' . (string) $design['panel_text'],
			'--merchboard-login-accent:' . (string) $design['accent'],
		);
		$css   = '';

		$background = $this->logo->image( (int) $design['background_id'], LogoAttachment::KIND_BACKGROUND );
		$background = null === $background ? '' : self::css_url( $background['url'] );

		if ( '' !== $background ) {
			$rules[] = '--merchboard-login-image:url("' . $background . '")';
		}

		$logo = $this->logo->image( (int) $design['login_logo_id'], LogoAttachment::KIND_LOGO ) ?? $this->logo->resolve();
		$logo = null === $logo ? '' : self::css_url( $logo['url'] );

		if ( '' !== $logo ) {
			$css .= 'body.' . self::BODY_CLASS . ' #login h1 a{background-image:url("' . $logo . '");}';
		}

		return 'body.' . self::BODY_CLASS . '{' . implode( ';', $rules ) . ';}' . $css;
	}

	/**
	 * The logo rule alone (the main logo), or '' (ADR-042, kept for callers).
	 */
	public function logo_css(): string {
		$logo = $this->logo->resolve();
		$url  = null === $logo ? '' : self::css_url( $logo['url'] );

		return '' === $url ? '' : 'body.' . self::BODY_CLASS . ' #login h1 a{background-image:url("' . $url . '");}';
	}

	/**
	 * `login_head`: favicon and app icon.
	 */
	public function head(): void {
		if ( $this->is_active() ) {
			echo $this->icons->links(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- BrandIcons::links() escapes.
		}
	}

	/**
	 * `login_body_class`: the scoping class, the layout and the screen.
	 *
	 * @param mixed $classes Body classes.
	 * @return mixed
	 */
	public function body_class( mixed $classes ): mixed {
		if ( ! $this->is_active() || ! is_array( $classes ) ) {
			return $classes;
		}

		$design    = BrandingDesign::read();
		$classes[] = self::BODY_CLASS;
		$classes[] = self::BODY_CLASS . '--' . $design['layout'];
		$classes[] = self::BODY_CLASS . '--' . $this->screen;

		if ( ! $design['shop_link'] ) {
			$classes[] = self::BODY_CLASS . '--no-shop-link';
		}

		if ( null !== $this->logo->image( (int) $design['background_id'], LogoAttachment::KIND_BACKGROUND ) ) {
			$classes[] = self::BODY_CLASS . '--image';
		}

		return $classes;
	}

	/**
	 * `login_headerurl`: the site's front page.
	 *
	 * @param mixed $url Logo link URL.
	 * @return mixed
	 */
	public function header_url( mixed $url ): mixed {
		return $this->is_active() ? home_url( '/' ) : $url;
	}

	/**
	 * `login_headertext`: the site name as the logo link's accessible name.
	 *
	 * @param mixed $text Logo link text.
	 * @return mixed
	 */
	public function header_text( mixed $text ): mixed {
		if ( ! $this->is_active() ) {
			return $text;
		}

		$name = (string) get_bloginfo( 'name' );

		return '' === $name ? $text : $name;
	}

	/**
	 * `login_header`: opens the layout around WordPress' own `#login`. In
	 * the split layout the heading and text stand on the panel beside the
	 * form; in the centred one above the form (message()).
	 */
	public function open_layout(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		$design = BrandingDesign::read();

		echo '<div class="merchboard-login-layout"><div class="merchboard-login-panel">';

		if ( 'split' === $design['layout'] ) {
			echo $this->intro( $design ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intro() escapes.
		}

		echo '</div><div class="merchboard-login-main">';
	}

	/**
	 * `login_footer`: the links, then closes the layout.
	 */
	public function close_layout(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		$design = BrandingDesign::read();
		$links  = array();
		$signup = null === $this->signup_url ? null : ( $this->signup_url )();

		if ( $design['signup_link'] && is_string( $signup ) && '' !== $signup ) {
			$links[] = '<a class="merchboard-login-button" href="' . esc_url( $signup ) . '">' . esc_html__( 'Become a creator', 'merchboard' ) . '</a>';
		}

		if ( '' !== $design['support_url'] ) {
			$label   = '' === $design['support_label'] ? __( 'Help and support', 'merchboard' ) : $design['support_label'];
			$links[] = '<a href="' . esc_url( $design['support_url'], array( 'https', 'mailto' ) ) . '">' . esc_html( $label ) . '</a>';
		}

		if ( array() !== $links ) {
			echo '<nav class="merchboard-login-links" aria-label="' . esc_attr__( 'More', 'merchboard' ) . '">' . implode( '', $links ) . '</nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each link is escaped above.
		}

		echo '</div></div>';
	}

	/**
	 * `login_message`: the screen's heading and welcome text before
	 * WordPress' own message.
	 *
	 * @param mixed $message WordPress' message (HTML).
	 * @return mixed
	 */
	public function message( mixed $message ): mixed {
		if ( ! $this->is_active() ) {
			return $message;
		}

		$design = BrandingDesign::read();
		$intro  = 'split' === $design['layout'] ? '' : $this->intro( $design );

		return $intro . ( is_string( $message ) ? $message : '' );
	}

	/**
	 * The screen's heading and text, escaped.
	 *
	 * @param array<string, mixed> $design The design.
	 */
	public function intro( array $design ): string {
		[ $title, $text ] = $this->texts( $design );

		return '<div class="merchboard-login-intro"><h2 class="merchboard-login-intro__title">' . esc_html( $title ) . '</h2>' . ( '' === $text ? '' : '<p class="merchboard-login-intro__text">' . esc_html( $text ) . '</p>' ) . '</div>';
	}

	/**
	 * The heading and text of the screen: the administrator's, else the
	 * translated default.
	 *
	 * @param array<string, mixed> $design The design.
	 * @return array{0: string, 1: string}
	 */
	public function texts( array $design ): array {
		$own      = is_array( $design['texts'][ $this->screen ] ?? null ) ? $design['texts'][ $this->screen ] : array();
		$defaults = match ( $this->screen ) {
			'lostpassword' => array( __( 'Forgot your password?', 'merchboard' ), __( 'Enter your username or e-mail address, and we will send you a link to choose a new password.', 'merchboard' ) ),
			'resetpass'    => array( __( 'Choose a new password', 'merchboard' ), __( 'Choose a strong password that you do not use anywhere else.', 'merchboard' ) ),
			'loggedout'    => array( __( 'You are logged out', 'merchboard' ), __( 'See you soon.', 'merchboard' ) ),
			default        => array( __( 'Welcome back', 'merchboard' ), __( 'Log in to your creator dashboard.', 'merchboard' ) ),
		};

		$title = is_string( $own['title'] ?? null ) && '' !== $own['title'] ? $own['title'] : $defaults[0];
		$text  = is_string( $own['text'] ?? null ) && '' !== $own['text'] ? $own['text'] : $defaults[1];

		// The receipt keeps the screen's heading; the instruction no longer fits.
		if ( $this->receipt ) {
			$text = __( 'Check your e-mail: we have sent you a link to choose a new password.', 'merchboard' );
		}

		return array( $title, $text );
	}

	/**
	 * `lostpassword_url`: on the branded login, WordPress' own lost-password
	 * page with the marker (also when WooCommerce has its own page).
	 *
	 * @param mixed $url Lost-password URL.
	 * @return mixed
	 */
	public function lostpassword_url( mixed $url ): mixed {
		if ( 'login' !== $this->screen && 'loggedout' !== $this->screen ) {
			return $url;
		}

		return add_query_arg(
			array(
				'action'     => 'lostpassword',
				self::MARKER => '1',
			),
			network_site_url( 'wp-login.php', 'login' )
		);
	}

	/**
	 * `lostpassword_form`: keeps the marker in the form.
	 */
	public function lostpassword_marker(): void {
		if ( 'lostpassword' === $this->screen ) {
			echo '<input type="hidden" name="' . esc_attr( self::MARKER ) . '" value="1">';
		}
	}

	/**
	 * `lostpassword_redirect`: after sending, the receipt on the branded login.
	 *
	 * @param mixed $url Redirect URL.
	 * @return mixed
	 */
	public function lostpassword_redirect( mixed $url ): mixed {
		if ( 'lostpassword' !== $this->screen ) {
			return $url;
		}

		return add_query_arg(
			array(
				'checkemail'  => 'confirm',
				'redirect_to' => rawurlencode( $this->route->url() ),
			),
			network_site_url( 'wp-login.php', 'login' )
		);
	}

	/**
	 * `login_url`: after a reset in context, "Log in" leads to the branded
	 * login with the dashboard as `redirect_to`.
	 *
	 * @param mixed $url      Login URL.
	 * @param mixed $redirect Requested redirect.
	 * @return mixed
	 */
	public function login_url( mixed $url, mixed $redirect = '' ): mixed {
		if ( 'resetpass' !== $this->screen || ! is_string( $url ) || ( is_string( $redirect ) && '' !== $redirect ) ) {
			return $url;
		}

		return add_query_arg( 'redirect_to', rawurlencode( $this->route->url() ), $url );
	}

	/**
	 * A URL that may stand inside `url("…")`, or ''.
	 *
	 * @param string $url URL.
	 */
	private static function css_url( string $url ): string {
		$url = esc_url_raw( $url );

		return 1 === preg_match( '#^https?://[^\s"\'()\\\\<>;{}]+$#', $url ) ? $url : '';
	}
}
