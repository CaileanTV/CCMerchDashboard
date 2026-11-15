<?php
/**
 * The Twitch Extension's terms of service and privacy policy.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchExtension;

use MerchBoard\Modules\Alerts\Legal\CompanyDetails;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Twitch requires a EULA/terms of service URL and a privacy policy URL for
 * every extension (ADR-112). The shop serves both itself, in English (for
 * Twitch's review) and Danish:
 *
 *   /?merchboard_extension=terms   /?merchboard_extension=terms&lang=da
 *   /?merchboard_extension=privacy /?merchboard_extension=privacy&lang=da
 *
 * A standalone page without the theme, with a strict CSP; the company's
 * name, CVR, address and e-mail come from Settings → Company details.
 */
final class TwitchExtensionLegal {

	/**
	 * The query argument.
	 */
	public const QUERY = 'merchboard_extension';

	/**
	 * The pages.
	 */
	public const PAGES = array( 'terms', 'privacy' );

	/**
	 * Hooks the pages.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle_request' ), 1 );
	}

	/**
	 * A page's URL.
	 *
	 * @param string $page     `terms` or `privacy`.
	 * @param string $language `en` or `da`.
	 */
	public static function url( string $page, string $language = 'en' ): string {
		$args = array( self::QUERY => in_array( $page, self::PAGES, true ) ? $page : 'terms' );

		if ( 'da' === $language ) {
			$args['lang'] = 'da';
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * `template_redirect`: shows a page and stops.
	 */
	public function handle_request(): void {
		$page = isset( $_GET[ self::QUERY ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A public page; nothing is changed.

		if ( ! in_array( $page, self::PAGES, true ) ) {
			return;
		}

		$language = isset( $_GET['lang'] ) && 'da' === sanitize_key( wp_unslash( $_GET['lang'] ) ) ? 'da' : 'en'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A public page; nothing is changed.
		$html     = $this->render( $page, $language );

		header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'" );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'X-Content-Type-Options: nosniff' );
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes every value.
		exit;
	}

	/**
	 * A page's HTML.
	 *
	 * @param string $page     `terms` or `privacy`.
	 * @param string $language `en` or `da`.
	 * @throws Throwable When the template fails (after its output is discarded).
	 */
	public function render( string $page, string $language ): string {
		// The page's own language, also when the site uses another.
		$switched = switch_to_locale( 'da' === $language ? 'da_DK' : 'en_US' );
		ob_start();

		try {
			$details         = CompanyDetails::values();
			$merchboard_view = array(
				'page'     => $page,
				'language' => $language,
				'site'     => get_bloginfo( 'name' ),
				'home'     => home_url( '/' ),
				'company'  => $details['[COMPANY]'],
				'cvr'      => $details['[CVR]'],
				'address'  => $details['[ADDRESS]'],
				'email'    => $details['[EMAIL]'],
				'links'    => array(
					'terms'   => self::url( 'terms', $language ),
					'privacy' => self::url( 'privacy', $language ),
					'other'   => self::url( $page, 'da' === $language ? 'en' : 'da' ),
				),
			);

			require dirname( __DIR__, 4 ) . '/templates/community/extension-legal.php';

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
}
