<?php
/**
 * The look of notification e-mails.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications\Email;

use MerchBoard\Modules\Alerts\Legal\CompanyDetails;

defined( 'ABSPATH' ) || exit;

/**
 * Colors, font, logo, footer and sender of the e-mails (ADR-079 pkt. 5):
 * from WooCommerce → Settings → Emails when WooCommerce is active, so the
 * notifications look like the shop's own e-mails; otherwise the design's
 * defaults and the site's name and e-mail.
 */
final class EmailStyle {

	public const DEFAULT_BASE = '#1f7a68';

	public const DEFAULT_BACKGROUND = '#f3f3f1';

	public const DEFAULT_BODY = '#ffffff';

	public const DEFAULT_TEXT = '#1e1e1e';

	public const DEFAULT_FONT = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';

	/**
	 * Constructor.
	 *
	 * @param string $base       Accent color (top border, links, button).
	 * @param string $background Page background.
	 * @param string $body       Card background.
	 * @param string $text       Text color.
	 * @param string $font       CSS font stack.
	 * @param string $logo       Header image URL ('' for the shop name).
	 * @param string $store      Shop name.
	 * @param string $footer     Footer text (placeholders replaced).
	 * @param string $from_name  Sender name.
	 * @param string $from_email Sender e-mail.
	 */
	public function __construct(
		public readonly string $base,
		public readonly string $background,
		public readonly string $body,
		public readonly string $text,
		public readonly string $font,
		public readonly string $logo,
		public readonly string $store,
		public readonly string $footer,
		public readonly string $from_name,
		public readonly string $from_email
	) {
	}

	/**
	 * The site's style now.
	 */
	public static function current(): self {
		$store   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$address = class_exists( CompanyDetails::class ) ? CompanyDetails::address() : '';
		$footer  = (string) get_option( 'woocommerce_email_footer_text', '' );
		$footer  = '' === trim( $footer ) ? '{site_title}' . ( '' === $address ? '' : ' · {store_address}' ) : $footer;
		$footer  = strtr(
			$footer,
			array(
				'{site_title}'    => $store,
				'{site_address}'  => wp_parse_url( home_url(), PHP_URL_HOST ) ?? '',
				'{site_url}'      => wp_parse_url( home_url(), PHP_URL_HOST ) ?? '',
				'{store_address}' => $address,
				'{store_email}'   => (string) get_option( 'woocommerce_email_from_address', '' ),
				'{WooCommerce}'   => 'WooCommerce',
			)
		);

		$font = (string) get_option( 'woocommerce_email_font_family', '' );

		return new self(
			self::color( 'woocommerce_email_base_color', self::DEFAULT_BASE ),
			self::color( 'woocommerce_email_background_color', self::DEFAULT_BACKGROUND ),
			self::color( 'woocommerce_email_body_background_color', self::DEFAULT_BODY ),
			self::color( 'woocommerce_email_text_color', self::DEFAULT_TEXT ),
			'' === $font || 1 !== preg_match( '/^[A-Za-z0-9 ,"\'-]{1,120}$/', $font ) ? self::DEFAULT_FONT : $font . ', ' . self::DEFAULT_FONT,
			self::url( (string) get_option( 'woocommerce_email_header_image', '' ) ),
			$store,
			wp_strip_all_tags( $footer ),
			self::text( (string) get_option( 'woocommerce_email_from_name', '' ), $store ),
			is_email( (string) get_option( 'woocommerce_email_from_address', '' ) ) ? (string) get_option( 'woocommerce_email_from_address', '' ) : (string) get_option( 'admin_email', '' )
		);
	}

	/**
	 * A mix of two colors, e.g. the tinted info box or a faint line.
	 *
	 * @param string $color  Foreground (#rrggbb).
	 * @param string $ground Background (#rrggbb).
	 * @param float  $amount Share of the foreground, 0–1.
	 */
	public static function mix( string $color, string $ground, float $amount ): string {
		$a = self::rgb( $color );
		$b = self::rgb( $ground );

		return sprintf( '#%02x%02x%02x', (int) round( $a[0] * $amount + $b[0] * ( 1 - $amount ) ), (int) round( $a[1] * $amount + $b[1] * ( 1 - $amount ) ), (int) round( $a[2] * $amount + $b[2] * ( 1 - $amount ) ) );
	}

	/**
	 * A hex color option, or the default.
	 *
	 * @param string $option  Option name.
	 * @param string $fallback Default color.
	 */
	private static function color( string $option, string $fallback ): string {
		$value = strtolower( trim( (string) get_option( $option, '' ) ) );

		if ( 1 === preg_match( '/^#[0-9a-f]{3}$/', $value ) ) {
			$value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
		}

		return 1 === preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : $fallback;
	}

	/**
	 * An http(s) URL, or ''.
	 *
	 * @param string $url URL.
	 */
	private static function url( string $url ): string {
		$url = trim( $url );

		return 1 === preg_match( '#^https?://#i', $url ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * A non-empty text, or the fallback.
	 *
	 * @param string $value    Value.
	 * @param string $fallback Fallback.
	 */
	private static function text( string $value, string $fallback ): string {
		$value = trim( wp_specialchars_decode( $value, ENT_QUOTES ) );

		return '' === $value ? $fallback : $value;
	}

	/**
	 * The channels of a #rrggbb color.
	 *
	 * @param string $color Color.
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function rgb( string $color ): array {
		$hex = 1 === preg_match( '/^#[0-9a-f]{6}$/', $color ) ? substr( $color, 1 ) : '000000';

		return array( (int) hexdec( substr( $hex, 0, 2 ) ), (int) hexdec( substr( $hex, 2, 2 ) ), (int) hexdec( substr( $hex, 4, 2 ) ) );
	}
}
