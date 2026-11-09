<?php
/**
 * MitCCMerch's web manifest for the home screen.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard\Branding;

use MerchBoard\Modules\Dashboard\DashboardRoute;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-103 pkt. 5: `/<slug>/manifest` answers MitCCMerch's web manifest
 * (BrandIcons::manifest()) as `application/manifest+json`, before the
 * shell's login requirement, because browsers fetch it without cookies.
 * It holds no personal data: the app name, the icon, the dashboard as
 * start URL and colours. Without an icon it answers 404. No service worker.
 */
final class ManifestPage {

	/**
	 * Constructor.
	 *
	 * @param DashboardRoute $route The dashboard route.
	 * @param BrandIcons     $icons Favicon and app icon.
	 */
	public function __construct( private DashboardRoute $route, private BrandIcons $icons ) {
	}

	/**
	 * WordPress entry point (`template_redirect`, before the shell).
	 */
	public function handle_request(): void {
		if ( ! $this->route->is_request() || BrandIcons::MANIFEST_PATH !== $this->route->path() ) {
			return;
		}

		[ $status, $headers, $body ] = $this->respond();

		status_header( $status );

		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with JSON_HEX_TAG etc., served as application/manifest+json with nosniff.
		exit;
	}

	/**
	 * The answer: status, headers and body.
	 *
	 * @return array{0: int, 1: array<string, string>, 2: string}
	 */
	public function respond(): array {
		$manifest = $this->icons->manifest();
		$headers  = array(
			'X-Robots-Tag'           => 'noindex, nofollow',
			'X-Content-Type-Options' => 'nosniff',
			'Cache-Control'          => 'public, max-age=3600',
		);

		if ( null === $manifest ) {
			return array( 404, $headers + array( 'Content-Type' => 'text/plain; charset=utf-8' ), '' );
		}

		return array(
			200,
			$headers + array( 'Content-Type' => 'application/manifest+json; charset=utf-8' ),
			(string) wp_json_encode( $manifest, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES ),
		);
	}
}
