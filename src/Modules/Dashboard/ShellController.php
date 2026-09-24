<?php
/**
 * Creator dashboard entry point.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard;

use MerchBoard\Modules\Access\CreatorContext;
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
 * 3. creator access → the standalone shell (no theme, no wp_head(), no JS).
 *
 * Access is decided by CreatorContext alone, on every request. The creator is
 * never taken from the URL.
 */
final class ShellController {

	/**
	 * Constructor.
	 *
	 * @param DashboardRoute $route   Route.
	 * @param CreatorContext $context Creator access policy.
	 */
	public function __construct( private DashboardRoute $route, private CreatorContext $context ) {
	}

	/**
	 * WordPress entry point (`template_redirect`).
	 */
	public function handle_request(): void {
		if ( ! $this->route->is_request() ) {
			return;
		}

		$response = $this->respond();

		foreach ( self::security_headers() as $name => $value ) {
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
			'logout_url' => wp_logout_url( $this->route->url() ),
		);

		$creator = $this->context->current();

		if ( null === $creator ) {
			return ShellResponse::page( 403, ShellResponse::TEMPLATE_NO_ACCESS, $common );
		}

		return ShellResponse::page(
			200,
			ShellResponse::TEMPLATE_SHELL,
			array_merge(
				$common,
				array(
					'creator_name'  => $creator->display_name,
					'dashboard_url' => $this->route->url(),
					'path'          => $path,
				)
			)
		);
	}

	/**
	 * Renders a page response.
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

		ob_start();
		require dirname( __DIR__, 3 ) . '/templates/dashboard/' . $file;

		return (string) ob_get_clean();
	}

	/**
	 * Security headers of every dashboard response (docs/SECURITY.md). The
	 * pages load no scripts, so the policy forbids them entirely.
	 *
	 * @return array<string, string>
	 */
	public static function security_headers(): array {
		return array(
			'X-Robots-Tag'            => 'noindex, nofollow',
			'Referrer-Policy'         => 'strict-origin-when-cross-origin',
			'X-Content-Type-Options'  => 'nosniff',
			'X-Frame-Options'         => 'SAMEORIGIN',
			'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'self'",
		);
	}
}
