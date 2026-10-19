<?php
/**
 * The public overlay page for OBS.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

use MerchBoard\Core\Logging\Logger;
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
 *   connections or images elsewhere), `noindex`, no caching, and no referrer,
 *   so the secret id never leaves the page in a Referer header.
 * - **Styles:** every value in the inline style sheet comes from the
 *   validated settings (OverlaySettings), never raw input.
 */
final class OverlayPage {

	/**
	 * Constructor.
	 *
	 * @param OverlayRoute  $route  Route.
	 * @param OverlayFeed   $feed   Overlay lookup.
	 * @param OverlayAssets $assets Bundle.
	 * @param Logger        $logger Technical log.
	 */
	public function __construct( private OverlayRoute $route, private OverlayFeed $feed, private OverlayAssets $assets, private Logger $logger ) {
	}

	/**
	 * WordPress entry point (`template_redirect`).
	 */
	public function handle_request(): void {
		$id = $this->route->requested_id();

		if ( '' === $id ) {
			return;
		}

		$nonce = base64_encode( random_bytes( 18 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A CSP nonce.
		$html  = $this->render( $id, $nonce );

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

		$settings        = $overlay->settings;
		$merchboard_view = array(
			'ready'     => $this->assets->register(),
			'variables' => $settings->css_variables(),
			'boxes'     => array(
				'image'     => $settings->element_style( 'product_image' ),
				'text'      => $settings->element_style( 'main_text' ),
				'secondary' => $settings->element_style( 'secondary_text' ),
			),
			'config'    => array(
				'feed'            => rest_url( 'merchboard/v1/alerts/overlay/' . $overlay->overlay_id . '/feed' ),
				'version'         => $overlay->version(),
				'displayDuration' => $settings->display_duration(),
				'animDuration'    => $settings->anim_duration(),
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
			'Content-Security-Policy' => "default-src 'none'; script-src 'self' 'nonce-" . $nonce . "'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; media-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
			'X-Robots-Tag'            => 'noindex, nofollow',
			'Referrer-Policy'         => 'no-referrer',
			'X-Content-Type-Options'  => 'nosniff',
		);
	}
}
