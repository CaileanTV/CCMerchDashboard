<?php
/**
 * Gelato's catalog and templates for base products.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-082 pkt. 2 (step 30.13, catalog import from review 30.15): the
 * administrator picks a product catalog at Gelato and one template made of
 * a product from it, which is only Gelato's render engine: its own design
 * is always replaced by our files. The colours and sizes come from each
 * template variant's `productUid` (the catalog attributes `gco_` and `gsi_`;
 * the variant's own options, "Standard" or "One size" when it has none),
 * the sides from the first variant's image placeholders with their print
 * size in millimetres. The blank photos and print areas come from two
 * products made from the template (CatalogImport): every picked colour with
 * a transparent file in each placeholder, and the first colour with a
 * marker per placeholder. The products stay hidden in the API store
 * (Gelato has no call to delete them). The catalogs are cached for an hour.
 */
class GelatoCatalog implements CatalogRenderer {

	public const SOURCE = 'gelato';

	/**
	 * The cached catalogs (transient).
	 */
	public const CACHE = 'merchboard_gelato_catalogs';

	/**
	 * The colour of a variant without one.
	 */
	public const STANDARD_COLOR = 'Standard';

	/**
	 * The size of a variant without one.
	 */
	public const ONE_SIZE = 'One size';

	/**
	 * Most sides offered.
	 */
	private const MAX_SIDES = 6;

	/**
	 * The print resolution used when the print size allows it.
	 */
	private const DPI = 300;

	private const POLL_DELAY = 30;

	/**
	 * Constructor.
	 *
	 * @param GelatoApi $api Gelato.
	 */
	public function __construct( private GelatoApi $api ) {
	}

	/**
	 * Whether Gelato may be used.
	 */
	public function available(): bool {
		return $this->api->available();
	}

	/**
	 * The product catalogs whose title holds every word.
	 *
	 * @param string $query Words.
	 * @return list<array{uid: string, title: string}>
	 * @throws GelatoException When Gelato refuses or fails.
	 */
	public function catalogs( string $query = '' ): array {
		$cached   = get_transient( self::CACHE );
		$catalogs = array();

		if ( is_array( $cached ) ) {
			$list = $cached;
		} else {
			$answer = $this->api->catalogs();
			$list   = is_array( $answer['data'] ?? null ) ? $answer['data'] : $answer;
		}

		// The cache is read like Gelato's answer: every entry is checked again.
		foreach ( $list as $catalog ) {
			$uid = is_array( $catalog ) ? ( $catalog['catalogUid'] ?? $catalog['uid'] ?? null ) : null;

			if ( is_array( $catalog ) && is_string( $uid ) && 1 === preg_match( '/^[a-z0-9_-]{1,100}$/i', $uid ) ) {
				$catalogs[] = array(
					'uid'   => $uid,
					'title' => mb_substr( sanitize_text_field( is_string( $catalog['title'] ?? null ) ? $catalog['title'] : $uid ), 0, 100 ),
				);
			}
		}

		if ( ! is_array( $cached ) ) {
			set_transient( self::CACHE, $catalogs, HOUR_IN_SECONDS );
		}

		$split = preg_split( '/\s+/u', mb_strtolower( trim( $query ) ) );
		$words = array_filter( false === $split ? array() : $split, static fn( string $word ): bool => '' !== $word );

		return array_values(
			array_filter(
				$catalogs,
				static function ( array $catalog ) use ( $words ): bool {
					foreach ( $words as $word ) {
						if ( ! str_contains( mb_strtolower( $catalog['title'] . ' ' . $catalog['uid'] ), $word ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	/**
	 * What the administrator can pick from a template: the colours and
	 * sizes of its variants and the image placeholders of the first one.
	 *
	 * @param string $ref Template id.
	 * @return array{name: string, description: string, image: string, colors: list<array{name: string, hex: string}>, sizes: list<string>, sides: list<array{placement: string, name: string, width_mm: int, height_mm: int, dpi: int, width: int, height: int}>, variants: array<string, array<string, string>>}
	 * @throws GelatoException When Gelato refuses or fails, or the template cannot be a base product.
	 */
	public function choice( string $ref ): array {
		$template = $this->api->template( trim( $ref ) );
		$variants = self::variants( $template );
		$colors   = array();
		$sizes    = array();
		$map      = array();

		foreach ( $variants as $variant ) {
			$color = self::color_of( $variant );
			$size  = self::size_of( $variant );

			if ( ! is_scalar( $variant['id'] ?? null ) || '' === (string) $variant['id'] ) {
				continue;
			}

			if ( ! isset( $colors[ $color ] ) && count( $colors ) < BaseProduct::MAX_COLORS ) {
				// Gelato names no colour codes: CatalogImport measures them on the blank photos.
				$colors[ $color ] = array(
					'name' => $color,
					'hex'  => '#ffffff',
				);
			}

			if ( ! in_array( $size, $sizes, true ) && count( $sizes ) < BaseProduct::MAX_SIZES ) {
				$sizes[] = $size;
			}

			$map[ $color ][ $size ] ??= (string) $variant['id'];
		}

		$sides = array() === $variants ? array() : self::sides( $variants[0] );

		if ( array() === $colors || array() === $sides ) {
			self::fail( GelatoException::INVALID );
		}

		return array(
			'name'        => mb_substr( trim( sanitize_text_field( (string) ( $template['title'] ?? $template['templateName'] ?? 'Gelato ' . $ref ) ) ), 0, 191 ),
			'description' => mb_substr( wp_strip_all_tags( (string) ( $template['description'] ?? '' ) ), 0, 2000 ),
			'image'       => is_string( $template['previewUrl'] ?? null ) ? $template['previewUrl'] : '',
			'colors'      => array_values( $colors ),
			'sizes'       => $sizes,
			'sides'       => $sides,
			'variants'    => $map,
		);
	}

	/**
	 * Creates a hidden product from the template in the API store.
	 *
	 * @param string               $kind   `blank` or `marker`.
	 * @param array<string, mixed> $import Import data.
	 * @return string The product id.
	 * @throws GelatoException When Gelato refuses or fails.
	 */
	public function render( string $kind, array $import ): string {
		$colors   = is_array( $import['colors'] ?? null ) ? array_values( $import['colors'] ) : array();
		$variants = 'marker' === $kind ? array_slice( $colors, 0, 1 ) : $colors;
		$images   = array();

		foreach ( is_array( $import['sides'] ?? null ) ? array_values( $import['sides'] ) : array() as $index => $side ) {
			$url = 'marker' === $kind ? ( $import['markers'][ $index ] ?? null ) : ( $import['blank'] ?? null );

			if ( ! is_array( $side ) || ! is_string( $side['placement'] ?? null ) || ! is_string( $url ) ) {
				self::fail( GelatoException::INVALID );
			}

			$images[] = array(
				'name'      => $side['placement'],
				'fileUrl'   => $url,
				'fitMethod' => 'slice',
			);
		}

		if ( array() === $variants || array() === $images ) {
			self::fail( GelatoException::INVALID );
		}

		$answer  = $this->api->create_from_template(
			array(
				'templateId'                => (string) ( $import['ref'] ?? '' ),
				'title'                     => 'Merch Dashboard import (' . $kind . ')',
				'description'               => '',
				'isVisibleInTheOnlineStore' => false,
				'variants'                  => array_map(
					static fn( mixed $variant ): array => array(
						'templateVariantId' => (string) $variant,
						'imagePlaceholders' => $images,
					),
					$variants
				),
			)
		);
		$product = is_scalar( $answer['id'] ?? null ) ? (string) $answer['id'] : '';

		if ( 1 !== preg_match( '/^[A-Za-z0-9-]{1,100}$/', $product ) ) {
			self::fail( GelatoException::INVALID );
		}

		return $product;
	}

	/**
	 * A product's state, with its images by position and colour once it is
	 * active.
	 *
	 * @param string               $key    Product id.
	 * @param array<string, mixed> $import Import data.
	 * @return array{status: string, error?: string, images?: array<int|string, array<string, string>>}
	 * @throws GelatoException When Gelato refuses or fails.
	 */
	public function check( string $key, array $import ): array {
		$answer = $this->api->product( $key );
		$status = is_string( $answer['status'] ?? null ) ? $answer['status'] : '';

		if ( 'publishing_error' === $status ) {
			return array(
				'status' => 'failed',
				'error'  => 'publishing_error',
			);
		}

		if ( 'active' !== $status ) {
			return array( 'status' => 'pending' );
		}

		$colors = array();

		foreach ( is_array( $import['colors'] ?? null ) ? $import['colors'] : array() as $color => $variant ) {
			$colors[ (string) $color ] = (string) $variant;
		}

		$images = array();

		foreach ( self::variant_images( $answer, $colors ) as $color => $urls ) {
			foreach ( $urls as $slot => $url ) {
				$images[ (string) $slot ][ $color ] = $url;
			}
		}

		return array(
			'status' => 'done',
			'images' => $images,
		);
	}

	/**
	 * A file Gelato returned.
	 *
	 * @param string $url URL.
	 * @throws GelatoException When it cannot be downloaded.
	 */
	public function download( string $url ): string {
		return $this->api->download( $url );
	}

	/**
	 * Seconds between two looks at a product.
	 */
	public function poll_delay(): int {
		return self::POLL_DELAY;
	}

	/**
	 * Gelato's answer about a template.
	 *
	 * @param string $ref Template id.
	 * @return array<string, mixed>
	 * @throws GelatoException When Gelato refuses or fails.
	 */
	public function diagnose( string $ref ): array {
		return array( 'GET /v1/templates/' . $ref => $this->api->template( $ref ) );
	}

	/**
	 * A product's images per colour key, in Gelato's order: the images tied
	 * to a variant (`productVariantIds`) for its colour (the variant's
	 * `templateVariantId`, else its `productUid` colour), and images tied to
	 * no variant for the only colour of a one-colour product.
	 *
	 * @param array<string, mixed>  $answer Product answer.
	 * @param array<string, string> $colors Template variant by colour key.
	 * @return array<string, list<string>>
	 */
	public static function variant_images( array $answer, array $colors ): array {
		$by_template = array_flip( array_filter( $colors, static fn( string $variant ): bool => '' !== $variant ) );
		$by_variant  = array();
		$images      = array();

		foreach ( is_array( $answer['variants'] ?? null ) ? $answer['variants'] : array() as $variant ) {
			if ( ! is_array( $variant ) || ! is_scalar( $variant['id'] ?? null ) ) {
				continue;
			}

			$template = is_scalar( $variant['templateVariantId'] ?? null ) ? (string) $variant['templateVariantId'] : '';
			$key      = (string) ( $by_template[ $template ] ?? substr( sanitize_title( self::color_of( $variant ) ), 0, 32 ) );

			if ( isset( $colors[ $key ] ) ) {
				$by_variant[ (string) $variant['id'] ] = $key;
			}
		}

		$only = 1 === count( $colors ) ? (string) array_key_first( $colors ) : null;

		foreach ( is_array( $answer['productImages'] ?? null ) ? $answer['productImages'] : array() as $image ) {
			$url  = is_array( $image ) && is_string( $image['fileUrl'] ?? null ) ? $image['fileUrl'] : '';
			$tied = is_array( $image ) && is_array( $image['productVariantIds'] ?? null ) ? $image['productVariantIds'] : array();

			if ( '' === $url ) {
				continue;
			}

			if ( array() === $tied && null !== $only ) {
				$images[ $only ][] = $url;
			}

			foreach ( $tied as $variant ) {
				$key = is_scalar( $variant ) ? ( $by_variant[ (string) $variant ] ?? null ) : null;

				if ( null !== $key && ! in_array( $url, $images[ $key ] ?? array(), true ) ) {
					$images[ $key ][] = $url;
				}
			}
		}

		if ( null !== $only && ! isset( $images[ $only ] ) && is_string( $answer['previewUrl'] ?? null ) && '' !== $answer['previewUrl'] ) {
			$images[ $only ][] = $answer['previewUrl'];
		}

		return $images;
	}

	/**
	 * The template's variants.
	 *
	 * @param array<string, mixed> $template Template answer.
	 * @return list<array<mixed>>
	 */
	public static function variants( array $template ): array {
		return array_values( array_filter( is_array( $template['variants'] ?? null ) ? $template['variants'] : array(), 'is_array' ) );
	}

	/**
	 * A variant's colour: the catalog's `gco_` in its `productUid`, else its
	 * option "Color" or "Colour", else "Standard".
	 *
	 * @param array<mixed> $variant Variant.
	 */
	public static function color_of( array $variant ): string {
		$value = self::attribute( $variant, 'gco' );
		$name  = null === $value ? null : ucfirst( str_replace( '-', ' ', $value ) );

		return mb_substr( $name ?? self::option( $variant, '/^colou?r$/i' ) ?? self::STANDARD_COLOR, 0, 60 );
	}

	/**
	 * A variant's size: the catalog's `gsi_` in its `productUid`, else its
	 * option "Size" (a comma separates sizes), else "One size".
	 *
	 * @param array<mixed> $variant Variant.
	 */
	public static function size_of( array $variant ): string {
		$value = self::attribute( $variant, 'gsi' );
		$name  = null;

		if ( null !== $value ) {
			$name = 1 === preg_match( '/^[a-z0-9]{1,4}$/', $value ) ? strtoupper( $value ) : ucfirst( str_replace( '-', ' ', $value ) );
		}

		return mb_substr( str_replace( ',', ' ', $name ?? self::option( $variant, '/^size$/i' ) ?? self::ONE_SIZE ), 0, 20 );
	}

	/**
	 * The sides of a variant's image placeholders with their print size.
	 *
	 * @param array<mixed> $variant First variant.
	 * @return list<array{placement: string, name: string, width_mm: int, height_mm: int, dpi: int, width: int, height: int}>
	 */
	private static function sides( array $variant ): array {
		$sides = array();
		$keys  = array();

		foreach ( is_array( $variant['imagePlaceholders'] ?? null ) ? $variant['imagePlaceholders'] : array() as $placeholder ) {
			$name      = is_array( $placeholder ) && is_string( $placeholder['name'] ?? null ) ? $placeholder['name'] : '';
			$width_mm  = is_array( $placeholder ) ? (int) round( (float) ( $placeholder['width'] ?? 0 ) ) : 0;
			$height_mm = is_array( $placeholder ) ? (int) round( (float) ( $placeholder['height'] ?? 0 ) ) : 0;

			if ( '' === $name || $width_mm < 1 || $height_mm < 1 || max( $width_mm, $height_mm ) > BaseView::MAX_MM || count( $sides ) >= self::MAX_SIDES ) {
				continue;
			}

			$label = is_string( $placeholder['printArea'] ?? null ) && '' !== trim( $placeholder['printArea'] ) ? $placeholder['printArea'] : $name;
			$label = mb_substr( trim( sanitize_text_field( ucfirst( str_replace( array( '_', '-' ), ' ', $label ) ) ) ), 0, 60 );
			$key   = substr( sanitize_title( $label ), 0, 32 );

			if ( '' === $key || isset( $keys[ $key ] ) ) {
				continue;
			}

			$dpi          = self::dpi( max( $width_mm, $height_mm ) );
			$keys[ $key ] = true;
			$sides[]      = array(
				'placement' => $name,
				'name'      => $label,
				'width_mm'  => $width_mm,
				'height_mm' => $height_mm,
				'dpi'       => $dpi,
				'width'     => (int) round( $width_mm * $dpi / 25.4 ),
				'height'    => (int) round( $height_mm * $dpi / 25.4 ),
			);
		}

		return $sides;
	}

	/**
	 * 300 dpi, or less when the print file would exceed the designer's largest image.
	 *
	 * @param int $mm The print size's longest side.
	 */
	private static function dpi( int $mm ): int {
		return max( BaseView::MIN_DPI, min( self::DPI, (int) floor( DesignerFiles::MAX_PIXELS * 25.4 / $mm ) ) );
	}

	/**
	 * A catalog attribute of a variant's `productUid` (the part after
	 * `_<code>_`), or null.
	 *
	 * @param array<mixed> $variant Variant.
	 * @param string       $code    Attribute code, e.g. `gco`.
	 */
	private static function attribute( array $variant, string $code ): ?string {
		$uid   = is_string( $variant['productUid'] ?? null ) ? strtolower( $variant['productUid'] ) : '';
		$parts = explode( '_', $uid );
		$at    = array_search( $code, $parts, true );
		$value = false === $at ? '' : (string) ( $parts[ $at + 1 ] ?? '' );

		return 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,40}$/', $value ) ? $value : null;
	}

	/**
	 * A variant option's value, or null.
	 *
	 * @param array<mixed> $variant Variant.
	 * @param string       $name    Pattern of the option's name.
	 */
	private static function option( array $variant, string $name ): ?string {
		foreach ( is_array( $variant['variantOptions'] ?? null ) ? $variant['variantOptions'] : array() as $option ) {
			if ( is_array( $option ) && is_string( $option['name'] ?? null ) && 1 === preg_match( $name, $option['name'] ) && is_scalar( $option['value'] ?? null ) && '' !== trim( (string) $option['value'] ) ) {
				return trim( (string) $option['value'] );
			}
		}

		return null;
	}

	/**
	 * Fails with a reason.
	 *
	 * @param string $reason GelatoException reason.
	 * @throws GelatoException Always.
	 */
	private static function fail( string $reason ): never {
		throw new GelatoException( $reason );
	}
}
