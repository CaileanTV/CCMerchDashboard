<?php
/**
 * The logo shown in the dashboard and on the Merch Dashboard login.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard\Branding;

use MerchBoard\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the one global logo (ADR-042) for every surface (dashboard header,
 * login): the admin-chosen attachment when it is still usable
 * (LogoAttachment, checked on every read), otherwise the plugin's own
 * default SVG (assets/brand/logo.svg), otherwise null so callers show the
 * site name as text. Every URL is on the site's own origin.
 *
 * The default logo is a graphic mark without text; the admin logo is
 * expected to carry the brand name. Callers use `custom` to decide the
 * accessible name: an admin logo replaces the site name (alt = site name),
 * the default mark sits next to the site name as text (alt = "").
 */
final class BrandLogo {

	/**
	 * Default logo, relative to the plugin root.
	 */
	public const DEFAULT_PATH = 'assets/brand/logo.svg';

	/**
	 * Intrinsic size of the default logo (its viewBox is square).
	 */
	public const DEFAULT_SIZE = 64;

	/**
	 * Constructor.
	 *
	 * @param LogoAttachment $attachments Attachment validation.
	 * @param string         $root        Plugin root directory, with trailing slash.
	 */
	public function __construct( private LogoAttachment $attachments, private string $root ) {
	}

	/**
	 * The logo to show, or null for "site name as text".
	 *
	 * @return array{url: string, width: int, height: int, custom: bool}|null
	 */
	public function resolve(): ?array {
		$id = Settings::logo_attachment_id();

		if ( $id > 0 ) {
			$image = $this->attachments->usable( $id );

			if ( null !== $image ) {
				return $image + array( 'custom' => true );
			}
		}

		return $this->default_logo();
	}

	/**
	 * The default logo, or null when its file or URL is unusable.
	 *
	 * @return array{url: string, width: int, height: int, custom: bool}|null
	 */
	public function default_logo(): ?array {
		if ( ! is_file( $this->root . self::DEFAULT_PATH ) ) {
			return null;
		}

		$url = plugins_url( self::DEFAULT_PATH, $this->root . 'merchboard.php' );

		if ( defined( 'MERCHBOARD_VERSION' ) ) {
			$url = add_query_arg( array( 'ver' => rawurlencode( (string) constant( 'MERCHBOARD_VERSION' ) ) ), $url );
		}

		if ( ! self::is_same_origin( $url ) ) {
			return null;
		}

		return array(
			'url'    => $url,
			'width'  => self::DEFAULT_SIZE,
			'height' => self::DEFAULT_SIZE,
			'custom' => false,
		);
	}

	/**
	 * Whether a URL is an absolute http(s) URL on the site's origin (scheme,
	 * host and port of `home_url()`), without credentials. Images from
	 * elsewhere are never shown (dashboard CSP `img-src 'self'`).
	 *
	 * @param string $url URL.
	 */
	public static function is_same_origin( string $url ): bool {
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );

		if ( ! is_array( $parts ) || ! is_array( $home ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		return in_array( $scheme, array( 'http', 'https' ), true )
			&& strtolower( (string) ( $home['scheme'] ?? '' ) ) === $scheme
			&& strtolower( (string) ( $parts['host'] ?? '' ) ) === strtolower( (string) ( $home['host'] ?? '' ) )
			&& ( $parts['port'] ?? null ) === ( $home['port'] ?? null )
			&& '' !== (string) ( $parts['host'] ?? '' );
	}
}
