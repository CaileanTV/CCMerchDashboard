<?php
/**
 * The branding of MitCCMerch: login screen, logos and icons.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Dashboard\Branding;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-103: everything the administrator can set for MitCCMerch's own login
 * screen, its logos and its icons, in one option (`merchboard_branding`,
 * autoloaded: read on every MitCCMerch page). The main logo stays in
 * `merchboard_settings` (ADR-042).
 *
 * The reader, read(), gives every key with a valid value (defaults for
 * missing or broken ones), so callers never see raw input. validate() turns the form's input
 * into that shape and lists what it refused; a refused value keeps the
 * stored one. Images are attachment ids, checked with LogoAttachment by
 * kind; colours are #rrggbb; texts are plain text with a length limit;
 * the support link is https or mailto.
 */
final class BrandingDesign {

	public const OPTION = 'merchboard_branding';

	/**
	 * The four screens of the login (ADR-103 pkt. 2).
	 */
	public const SCREENS = array( 'login', 'lostpassword', 'resetpass', 'loggedout' );

	public const LAYOUTS = array( 'split', 'centered' );

	/**
	 * Logo height in MitCCMerch's top bar, in px.
	 */
	public const LOGO_SIZES = array(
		'small'  => 32,
		'medium' => 48,
		'large'  => 64,
	);

	public const TITLE_MAX = 80;

	public const TEXT_MAX = 300;

	public const LABEL_MAX = 40;

	public const APP_NAME_MAX = 30;

	/**
	 * The smallest contrast of text on its colour (WCAG AA for normal text).
	 */
	public const MIN_CONTRAST = 4.5;

	/**
	 * The form's surface on the dark login (Dark Black, login.css).
	 */
	public const DARK_SURFACE = '#16181b';

	/**
	 * Images by key and kind.
	 */
	public const IMAGES = array(
		'login_logo_id' => LogoAttachment::KIND_LOGO,
		'dark_logo_id'  => LogoAttachment::KIND_LOGO,
		'background_id' => LogoAttachment::KIND_BACKGROUND,
		'favicon_id'    => LogoAttachment::KIND_FAVICON,
		'app_icon_id'   => LogoAttachment::KIND_APP_ICON,
	);

	/**
	 * Problem codes of validate().
	 */
	public const BAD_IMAGE = 'branding_bad_image';

	public const BAD_COLOR = 'branding_bad_color';

	public const LOW_CONTRAST = 'branding_low_contrast';

	public const BAD_LINK = 'branding_bad_link';

	/**
	 * The defaults.
	 *
	 * @return array{layout: string, background_id: int, background_color: string, panel_text: string, accent: string, login_logo_id: int, texts: array<string, array{title: string, text: string}>, signup_link: bool, support_url: string, support_label: string, shop_link: bool, logo_size: string, logo_plate: bool, dark_logo_id: int, favicon_id: int, app_icon_id: int, app_name: string}
	 */
	public static function defaults(): array {
		return array(
			'layout'           => 'split',
			'background_id'    => 0,
			'background_color' => '#007279',
			'panel_text'       => '#ffffff',
			'accent'           => '#007279',
			'login_logo_id'    => 0,
			'texts'            => array_fill_keys(
				self::SCREENS,
				array(
					'title' => '',
					'text'  => '',
				)
			),
			'signup_link'      => true,
			'support_url'      => '',
			'support_label'    => '',
			'shop_link'        => true,
			'logo_size'        => 'small',
			'logo_plate'       => true,
			'dark_logo_id'     => 0,
			'favicon_id'       => 0,
			'app_icon_id'      => 0,
			'app_name'         => '',
		);
	}

	/**
	 * The stored design, every key valid.
	 *
	 * @return array{layout: string, background_id: int, background_color: string, panel_text: string, accent: string, login_logo_id: int, texts: array<string, array{title: string, text: string}>, signup_link: bool, support_url: string, support_label: string, shop_link: bool, logo_size: string, logo_plate: bool, dark_logo_id: int, favicon_id: int, app_icon_id: int, app_name: string}
	 */
	public static function read(): array {
		$stored = get_option( self::OPTION, array() );

		return self::clean( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * A stored or submitted design with every key valid (defaults for the
	 * rest); images are not checked here (see validate()).
	 *
	 * @param array<mixed> $raw Raw values.
	 * @return array{layout: string, background_id: int, background_color: string, panel_text: string, accent: string, login_logo_id: int, texts: array<string, array{title: string, text: string}>, signup_link: bool, support_url: string, support_label: string, shop_link: bool, logo_size: string, logo_plate: bool, dark_logo_id: int, favicon_id: int, app_icon_id: int, app_name: string}
	 */
	public static function clean( array $raw ): array {
		$design = self::defaults();

		$design['layout']    = in_array( $raw['layout'] ?? null, self::LAYOUTS, true ) ? (string) $raw['layout'] : $design['layout'];
		$design['logo_size'] = is_string( $raw['logo_size'] ?? null ) && isset( self::LOGO_SIZES[ $raw['logo_size'] ] ) ? $raw['logo_size'] : $design['logo_size'];

		foreach ( array( 'background_color', 'panel_text', 'accent' ) as $key ) {
			$color          = self::color( $raw[ $key ] ?? null );
			$design[ $key ] = null === $color ? $design[ $key ] : $color;
		}

		foreach ( array_keys( self::IMAGES ) as $key ) {
			$design[ $key ] = is_numeric( $raw[ $key ] ?? null ) ? max( 0, (int) $raw[ $key ] ) : 0;
		}

		foreach ( array( 'signup_link', 'shop_link', 'logo_plate' ) as $key ) {
			$design[ $key ] = array_key_exists( $key, $raw ) ? (bool) $raw[ $key ] : $design[ $key ];
		}

		$texts = is_array( $raw['texts'] ?? null ) ? $raw['texts'] : array();

		foreach ( self::SCREENS as $screen ) {
			$entry                      = is_array( $texts[ $screen ] ?? null ) ? $texts[ $screen ] : array();
			$design['texts'][ $screen ] = array(
				'title' => self::text( $entry['title'] ?? '', self::TITLE_MAX ),
				'text'  => self::text( $entry['text'] ?? '', self::TEXT_MAX ),
			);
		}

		$design['support_label'] = self::text( $raw['support_label'] ?? '', self::LABEL_MAX );
		$design['support_url']   = self::link( $raw['support_url'] ?? '' ) ?? '';
		$design['app_name']      = self::text( $raw['app_name'] ?? '', self::APP_NAME_MAX );

		return $design;
	}

	/**
	 * The form's input as a design to store, and the problems found. A
	 * refused image, colour or link keeps the stored value.
	 *
	 * @param array<mixed>         $input  Unslashed form input.
	 * @param array<string, mixed> $stored The stored design (read()).
	 * @param LogoAttachment       $images Image checks.
	 * @return array{0: array<string, mixed>, 1: list<string>}
	 */
	public static function validate( array $input, array $stored, LogoAttachment $images ): array {
		$problems = array();
		$raw      = $input;

		foreach ( self::IMAGES as $key => $kind ) {
			$id = is_scalar( $input[ $key ] ?? null ) && 1 === preg_match( '/^\d{1,19}$/', (string) $input[ $key ] ) ? (int) $input[ $key ] : 0;

			if ( $id > 0 && null !== $images->problem( $id, $kind ) ) {
				$problems[] = self::BAD_IMAGE;
				$id         = (int) ( $stored[ $key ] ?? 0 );
			}

			$raw[ $key ] = $id;
		}

		foreach ( array( 'background_color', 'panel_text', 'accent' ) as $key ) {
			if ( null === self::color( $input[ $key ] ?? null ) ) {
				$problems[]  = self::BAD_COLOR;
				$raw[ $key ] = $stored[ $key ] ?? null;
			}
		}

		if ( '' !== trim( is_scalar( $input['support_url'] ?? null ) ? (string) $input['support_url'] : '' ) && null === self::link( $input['support_url'] ) ) {
			$problems[]         = self::BAD_LINK;
			$raw['support_url'] = $stored['support_url'] ?? '';
		}

		// Unticked checkboxes are not sent.
		foreach ( array( 'signup_link', 'shop_link', 'logo_plate' ) as $key ) {
			$raw[ $key ] = array_key_exists( $key, $input );
		}

		$design = self::clean( $raw );

		// Text on the panel and white button text on the accent must be readable.
		if ( self::contrast( $design['panel_text'], $design['background_color'] ) < self::MIN_CONTRAST || self::contrast( '#ffffff', $design['accent'] ) < self::MIN_CONTRAST ) {
			$problems[]                 = self::LOW_CONTRAST;
			$fallback                   = self::clean( $stored );
			$design['panel_text']       = $fallback['panel_text'];
			$design['background_color'] = $fallback['background_color'];
			$design['accent']           = $fallback['accent'];
		}

		return array( $design, array_values( array_unique( $problems ) ) );
	}

	/**
	 * The logo height in px for a size key.
	 *
	 * @param string $size Size key.
	 */
	public static function logo_height( string $size ): int {
		return self::LOGO_SIZES[ $size ] ?? self::LOGO_SIZES['small'];
	}

	/**
	 * A #rrggbb colour in lower case, or null.
	 *
	 * @param mixed $value Value.
	 */
	public static function color( mixed $value ): ?string {
		return is_string( $value ) && 1 === preg_match( '/^#[0-9a-fA-F]{6}$/', trim( $value ) ) ? strtolower( trim( $value ) ) : null;
	}

	/**
	 * The contrast ratio of two #rrggbb colours (WCAG 2).
	 *
	 * @param string $a Colour.
	 * @param string $b Colour.
	 */
	public static function contrast( string $a, string $b ): float {
		$light = array( self::luminance( $a ), self::luminance( $b ) );

		return ( max( $light ) + 0.05 ) / ( min( $light ) + 0.05 );
	}

	/**
	 * The colour lightened with white in 5 % steps until text in it reads on
	 * the dark surface (contrast at least 4.5:1); the colour itself when it
	 * already does. Used for the button colour as text on the dark login.
	 *
	 * @param string $color   #rrggbb.
	 * @param string $surface #rrggbb.
	 */
	public static function on_dark( string $color, string $surface = self::DARK_SURFACE ): string {
		$channels = array_map( 'hexdec', str_split( substr( $color, 1 ), 2 ) );

		for ( $white = 0; $white <= 100; $white += 5 ) {
			$mixed = '#' . implode( '', array_map( static fn( int|float $channel ): string => sprintf( '%02x', (int) round( $channel + ( 255 - $channel ) * $white / 100 ) ), $channels ) );

			if ( self::contrast( $mixed, $surface ) >= self::MIN_CONTRAST ) {
				return $mixed;
			}
		}

		return '#ffffff';
	}

	/**
	 * A support link: https or mailto, or null.
	 *
	 * @param mixed $value Value.
	 */
	public static function link( mixed $value ): ?string {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value || strlen( $value ) > 2000 ) {
			return null;
		}

		$scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );

		if ( 'mailto' === $scheme ) {
			$address = substr( $value, strlen( 'mailto:' ) );

			return is_email( $address ) ? 'mailto:' . $address : null;
		}

		$clean = esc_url_raw( $value, array( 'https' ) );

		return 'https' === $scheme && '' !== $clean && is_string( wp_parse_url( $clean, PHP_URL_HOST ) ) ? $clean : null;
	}

	/**
	 * Plain text of at most a number of characters.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Most characters.
	 */
	private static function text( mixed $value, int $max ): string {
		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return '';
		}

		return mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, $max );
	}

	/**
	 * The relative luminance of a #rrggbb colour.
	 *
	 * @param string $hex Colour.
	 */
	private static function luminance( string $hex ): float {
		$channels = array_map(
			static function ( string $pair ): float {
				$value = hexdec( $pair ) / 255;

				return $value <= 0.03928 ? $value / 12.92 : ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
			},
			str_split( substr( $hex, 1 ), 2 )
		);

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}
}
