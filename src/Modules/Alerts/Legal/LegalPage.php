<?php
/**
 * The public pages of the YouTube chatbot's privacy policy and terms.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Legal;

use MerchBoard\Modules\Dashboard\DashboardRoute;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Public pages below the dashboard's address (ADR-071), for the people who
 * use the YouTube chatbot and for Google's review of the app: an overview
 * (the app's homepage), the privacy policy and the terms, in English and
 * Danish.
 *
 * - **Only these paths:** an exact whitelist, answered on
 *   `template_redirect` before the dashboard (priority −1). Every other
 *   dashboard path still requires a login (ShellController).
 * - **Only when published:** while the texts are a draft, the pages answer
 *   404.
 * - **Headers:** a CSP without scripts, no framing, `nosniff`. The pages are
 *   meant to be found, so they are not `noindex`.
 */
final class LegalPage {

	/**
	 * The pages by dashboard path: [page, language].
	 */
	private const PAGES = array(
		'legal/youtube'            => array( 'home', 'en' ),
		'legal/youtube/da'         => array( 'home', 'da' ),
		'legal/youtube-privacy'    => array( 'privacy', 'en' ),
		'legal/youtube-privacy/da' => array( 'privacy', 'da' ),
		'legal/youtube-terms'      => array( 'terms', 'en' ),
		'legal/youtube-terms/da'   => array( 'terms', 'da' ),
	);

	/**
	 * Constructor.
	 *
	 * @param DashboardRoute $route The dashboard's address.
	 * @param YouTubeLegal   $legal The texts.
	 */
	public function __construct( private DashboardRoute $route, private YouTubeLegal $legal ) {
	}

	/**
	 * The dashboard path of a page.
	 *
	 * @param string $page     'home', 'privacy' or 'terms'.
	 * @param string $language 'en' or 'da'.
	 */
	public static function path( string $page, string $language ): string {
		$path = array_search( array( $page, $language ), self::PAGES, true );

		return is_string( $path ) ? $path : 'legal/youtube';
	}

	/**
	 * The page of a dashboard path, or null for every other path (which then
	 * requires a login as before).
	 *
	 * @param string $path Normalized dashboard path.
	 * @return array{0: string, 1: string}|null [page, language]
	 */
	public static function page( string $path ): ?array {
		return self::PAGES[ $path ] ?? null;
	}

	/**
	 * WordPress entry point (`template_redirect`, priority −1).
	 */
	public function handle_request(): void {
		if ( ! $this->route->is_request() ) {
			return;
		}

		$page = self::page( $this->route->path() );

		if ( null === $page ) {
			return;
		}

		$html = $this->render( $page[0], $page[1] );

		foreach ( self::security_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}

		nocache_headers();
		status_header( null === $html ? 404 : 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html ?? '<!DOCTYPE html><title>404</title>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes every value and limits the texts to wp_kses_post(); the 404 page is static.
		exit;
	}

	/**
	 * A page, or null while the texts are a draft.
	 *
	 * @param string $page     'home', 'privacy' or 'terms'.
	 * @param string $language 'en' or 'da'.
	 * @throws Throwable When the template fails (after its output is discarded).
	 */
	public function render( string $page, string $language ): ?string {
		if ( ! $this->legal->published() ) {
			return null;
		}

		// The page's own language, also for its labels (the site may use another).
		$switched = switch_to_locale( 'da' === $language ? 'da_DK' : 'en_US' );
		ob_start();

		try {
			$other           = 'da' === $language ? 'en' : 'da';
			$merchboard_view = array(
				'page'      => $page,
				'language'  => $language,
				'site'      => get_bloginfo( 'name' ),
				'body'      => 'home' === $page ? '' : $this->legal->rendered( $page, $language ),
				'version'   => $this->legal->version(),
				'published' => $this->legal->published_at(),
				'links'     => array(
					'home'      => $this->legal->url( 'home', $language ),
					'privacy'   => $this->legal->url( 'privacy', $language ),
					'terms'     => $this->legal->url( 'terms', $language ),
					'other'     => $this->legal->url( $page, $other ),
					'dashboard' => $this->route->url(),
				),
				'other'     => 'da' === $other ? 'Dansk' : 'English',
			);

			require dirname( __DIR__, 4 ) . '/templates/alerts/legal.php';

			return (string) ob_get_clean();
		} catch ( Throwable $e ) {
			ob_end_clean();

			throw $e;
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Security headers of the pages.
	 *
	 * @return array<string, string>
	 */
	public static function security_headers(): array {
		return array(
			'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
			'Referrer-Policy'         => 'strict-origin-when-cross-origin',
			'X-Content-Type-Options'  => 'nosniff',
		);
	}
}
