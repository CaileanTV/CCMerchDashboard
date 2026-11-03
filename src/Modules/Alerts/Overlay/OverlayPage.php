<?php
/**
 * The public overlay page for OBS.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

use Closure;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Images\AlertImages;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Answers an overlay URL (OverlayRoute) with a standalone page outside the
 * theme (ADR-065 pkt. 4): three empty boxes (image, text, secondary text)
 * placed and styled from the overlay settings, the overlay bundle and its
 * configuration. The bundle fills the boxes from the feed.
 *
 * - **Access:** the secret id is the credential; an unknown id, a
 *   regenerated id or an inactive creator get the same bare 404.
 * - **Headers:** its own CSP (scripts only from this site with a nonce, no
 *   connections or images elsewhere; style sheets and fonts also from Google
 *   Fonts, ADR-067), `noindex`, no caching, and no referrer,
 *   so the secret id never leaves the page in a Referer header.
 * - **Styles:** every value in the inline style sheet comes from the
 *   validated settings (OverlaySettings), never raw input.
 * - **Text fills:** a preset by name, or an image only when the creator may
 *   use it (AlertImages); the bundle builds the CSS from these.
 */
final class OverlayPage {

	/**
	 * Constructor.
	 *
	 * @param OverlayRoute     $route  Route.
	 * @param OverlayFeed      $feed   Overlay lookup.
	 * @param OverlayAssets    $assets Bundle.
	 * @param Logger           $logger Technical log.
	 * @param AlertFonts|null  $fonts  Approved fonts; null for none.
	 * @param AlertImages|null $images Text fill images; null shows only the preset fills.
	 */
	public function __construct( private OverlayRoute $route, private OverlayFeed $feed, private OverlayAssets $assets, private Logger $logger, private ?AlertFonts $fonts = null, private ?AlertImages $images = null ) {
	}

	/**
	 * WordPress entry point (`template_redirect`).
	 */
	public function handle_request(): void {
		$id = $this->route->requested_id();

		if ( '' === $id ) {
			return;
		}

		$nonce = self::nonce();

		self::send( $this->render( $id, $nonce ), $nonce );
	}

	/**
	 * A fresh CSP nonce for a page's script.
	 */
	public static function nonce(): string {
		return base64_encode( random_bytes( 18 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A CSP nonce.
	}

	/**
	 * Sends an overlay page (or the 404 page for null) with its security
	 * headers and ends the request. Shared with the global overlay.
	 *
	 * @param string|null $html  The page, or null for 404.
	 * @param string      $nonce CSP nonce of the page's script.
	 */
	public static function send( ?string $html, string $nonce ): void {
		foreach ( self::security_headers( $nonce ) as $name => $value ) {
			header( $name . ': ' . $value );
		}

		nocache_headers();
		status_header( null === $html ? 404 : 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html ?? '<!DOCTYPE html><title>404</title>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes every value; the 404 page is static.
		exit;
	}

	/**
	 * The page for an overlay id, or null when there is no such overlay.
	 *
	 * @param string $overlay_id Requested id.
	 * @param string $nonce      CSP nonce of the page's script.
	 * @throws Throwable When the template fails (after its output is discarded).
	 */
	public function render( string $overlay_id, string $nonce ): ?string {
		try {
			$overlay = OverlayId::valid( $overlay_id ) ? $this->feed->active_overlay( $overlay_id ) : null;
		} catch ( Throwable $e ) {
			$this->logger->error( 'The alert overlay could not be loaded.', array( 'error' => $e::class ) );
			$overlay = null;
		}

		if ( null === $overlay ) {
			return null;
		}

		return $this->page(
			$overlay->settings,
			rest_url( 'merchboard/v1/alerts/overlay/' . $overlay->overlay_id . '/feed' ),
			$overlay->version(),
			fn( array $fill ): ?array => AlertImages::look( $this->images, $overlay->creator_id, $fill ),
			$nonce
		);
	}

	/**
	 * An overlay page from its settings: the template, the overlay script
	 * and its configuration. Shared with the global overlay (step 25.9.4),
	 * which passes its own feed and its own text fills.
	 *
	 * @param OverlaySettings $settings The overlay's settings.
	 * @param string          $feed     The feed's URL.
	 * @param string          $version  The overlay's version.
	 * @param Closure         $fill     A text fill's look (AlertImages::look()).
	 * @param string          $nonce    CSP nonce of the page's script.
	 * @phpstan-param Closure(array{type: string, preset: string, image: int, motion: string}): (array<string, mixed>|null) $fill
	 * @throws Throwable When the template fails (after its output is discarded).
	 */
	public function page( OverlaySettings $settings, string $feed, string $version, Closure $fill, string $nonce ): string {
		// A font that is no longer approved is never loaded; the default font is shown instead (ADR-067).
		$settings = $settings->with_fonts( fn( string $family ): bool => null !== $this->fonts?->find( $family ) );
		$looks    = array( '' => $settings->render_look( $fill ) );
		$fonts    = array();

		// Each variation's look (ADR-088); the feed names the one an alert is shown with.
		foreach ( $settings->variations() as $variation ) {
			$looks[ $variation['id'] ] = $settings->look( $variation['id'] )->render_look( $fill );
		}

		foreach ( $looks as $look ) {
			$font = is_string( $look['font_family'] ) && 'inherit' !== $look['font_family'] ? $this->fonts?->find( $look['font_family'] ) : null;

			if ( null !== $font ) {
				$fonts[ $font->family ] = $font->css_url();
			}
		}

		$merchboard_view = array(
			'font_urls' => array_values( $fonts ),
			'ready'     => $this->assets->register(),
			'config'    => array(
				'feed'    => $feed,
				'version' => $version,
				'looks'   => $looks,
			),
		);

		$add_nonce = static function ( mixed $attributes ) use ( $nonce ): mixed {
			if ( is_array( $attributes ) ) {
				$attributes['nonce'] = $nonce;
			}

			return $attributes;
		};

		add_filter( 'wp_script_attributes', $add_nonce );
		ob_start();

		try {
			require dirname( __DIR__, 4 ) . '/templates/alerts/overlay.php';

			return (string) ob_get_clean();
		} catch ( Throwable $e ) {
			ob_end_clean();

			throw $e;
		} finally {
			remove_filter( 'wp_script_attributes', $add_nonce );
		}
	}

	/**
	 * Security headers of the overlay page.
	 *
	 * @param string $nonce CSP nonce of the page's script.
	 * @return array<string, string>
	 */
	public static function security_headers( string $nonce ): array {
		return array(
			'Content-Security-Policy' => "default-src 'none'; script-src 'self' 'nonce-" . $nonce . "'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data:; font-src 'self' https://fonts.gstatic.com; media-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
			'X-Robots-Tag'            => 'noindex, nofollow',
			'Referrer-Policy'         => 'no-referrer',
			'X-Content-Type-Options'  => 'nosniff',
		);
	}
}
