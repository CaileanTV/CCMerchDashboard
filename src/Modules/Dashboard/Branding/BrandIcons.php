<?php
/**
 * MitCCMerch's favicon, app icon and web manifest.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard\Branding;

use MerchBoard\Modules\Dashboard\DashboardRoute;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-103 pkt. 5: the favicon (own, else the site icon of WordPress) on
 * MitCCMerch's pages and on its login, and the app icon for the home screen
 * (`apple-touch-icon` and a small web manifest at `/<slug>/manifest`, no
 * service worker). Every URL is on the site's own origin; every tag is
 * escaped here.
 */
final class BrandIcons {

	/**
	 * The manifest's path below the dashboard.
	 */
	public const MANIFEST_PATH = 'manifest';

	/**
	 * Constructor.
	 *
	 * @param BrandLogo      $logo  Images of the branding.
	 * @param DashboardRoute $route The dashboard route.
	 */
	public function __construct( private BrandLogo $logo, private DashboardRoute $route ) {
	}

	/**
	 * The favicon's URL, or null.
	 */
	public function favicon(): ?string {
		$own = $this->logo->image( (int) BrandingDesign::read()['favicon_id'], LogoAttachment::KIND_FAVICON );

		if ( null !== $own ) {
			return $own['url'];
		}

		$site = get_site_icon_url( 512 );

		return '' !== $site && BrandLogo::is_same_origin( $site ) ? $site : null;
	}

	/**
	 * The app icon (own, else the favicon), or null.
	 *
	 * @return array{url: string, width: int, height: int}|null
	 */
	public function app_icon(): ?array {
		$design = BrandingDesign::read();

		return $this->logo->image( (int) $design['app_icon_id'], LogoAttachment::KIND_APP_ICON )
			?? $this->logo->image( (int) $design['favicon_id'], LogoAttachment::KIND_FAVICON );
	}

	/**
	 * The `<link>` tags for the `<head>`, escaped.
	 */
	public function links(): string {
		$tags    = array();
		$favicon = $this->favicon();
		$app     = $this->app_icon();

		if ( null !== $favicon ) {
			$tags[] = '<link rel="icon" href="' . esc_url( $favicon ) . '">';
		}

		if ( null !== $app ) {
			$tags[] = '<link rel="apple-touch-icon" href="' . esc_url( $app['url'] ) . '">';
			$tags[] = '<link rel="manifest" href="' . esc_url( $this->route->url( self::MANIFEST_PATH ) ) . '">';
		}

		return implode( "\n", $tags ) . ( array() === $tags ? '' : "\n" );
	}

	/**
	 * The web manifest, or null without an icon.
	 *
	 * @return array<string, mixed>|null
	 */
	public function manifest(): ?array {
		$app = $this->app_icon();

		if ( null === $app ) {
			return null;
		}

		$design = BrandingDesign::read();
		$name   = '' === $design['app_name'] ? wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) : $design['app_name'];
		$name   = '' === $name ? __( 'Creator dashboard', 'merchboard' ) : $name;
		$type   = (string) wp_check_filetype( (string) wp_parse_url( $app['url'], PHP_URL_PATH ) )['type'];

		return array(
			'name'             => $name,
			'short_name'       => mb_substr( $name, 0, 12 ),
			'start_url'        => $this->route->url(),
			'scope'            => $this->route->url(),
			'display'          => 'standalone',
			'background_color' => '#ffffff',
			'theme_color'      => $design['accent'],
			'icons'            => array(
				array_filter(
					array(
						'src'     => $app['url'],
						'sizes'   => $app['width'] . 'x' . $app['height'],
						'type'    => $type,
						'purpose' => 'any',
					)
				),
			),
		);
	}
}
