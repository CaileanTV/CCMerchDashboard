<?php
/**
 * Base products from Gelato templates.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-082 pkt. 2 (step 30.13): a template the administrator made in the
 * Gelato dashboard becomes a base product (a draft, source `gelato`): its
 * title and description, the colours and sizes of its variants'
 * `variantOptions` (a variant without a colour is "Standard", without a
 * size "One size") and one side per image placeholder of its first
 * variant, with the print size in millimetres. The template's preview is
 * the background of every side and the print area starts in its middle;
 * the administrator adjusts both (or adds a colour mask) and sets the
 * colour codes, which Gelato does not give, before activating it. The
 * side keys are mapped to the placeholder names for the mockups.
 */
class GelatoCatalog {

	/**
	 * The colour of a variant without one.
	 */
	public const STANDARD_COLOR = 'Standard';

	/**
	 * The size of a variant without one.
	 */
	public const ONE_SIZE = 'One size';

	/**
	 * Most sides imported.
	 */
	private const MAX_SIDES = 6;

	/**
	 * The print resolution used when the print size allows it.
	 */
	private const DPI = 300;

	/**
	 * The stored placeholder map's largest size (`provider_template`).
	 */
	private const MAX_MAP = 191;

	/**
	 * Constructor.
	 *
	 * @param GelatoApi             $api     Gelato.
	 * @param BaseProductService    $service Base products (stores files by moving them).
	 * @param BaseProductRepository $bases   Base products.
	 */
	public function __construct( private GelatoApi $api, private BaseProductService $service, private BaseProductRepository $bases ) {
	}

	/**
	 * Imports a template as a base product draft.
	 *
	 * @param string $template_id Gelato template id.
	 * @param string $base_price  Base price incl. VAT (as typed).
	 * @return int The base product's id.
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws DesignerException When the template cannot be a base product.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function import( string $template_id, string $base_price ): int {
		$template_id = trim( $template_id );
		$template    = $this->api->template( $template_id );
		$variants    = self::variants( $template );
		$preview     = is_string( $template['previewUrl'] ?? null ) ? $template['previewUrl'] : '';

		if ( array() === $variants || '' === $preview ) {
			self::fail( GelatoException::INVALID );
		}

		$temporary = array();

		try {
			[ $views, $placeholders ] = $this->views( $variants[0], $preview, $temporary );
			$map                      = (string) wp_json_encode( $placeholders );

			if ( strlen( $map ) > self::MAX_MAP ) {
				self::fail( GelatoException::INVALID );
			}

			$id = $this->service->save(
				null,
				array(
					'name'        => mb_substr( trim( (string) ( $template['title'] ?? $template['templateName'] ?? 'Gelato ' . $template_id ) ), 0, 191 ),
					'description' => mb_substr( wp_strip_all_tags( (string) ( $template['description'] ?? '' ) ), 0, 2000 ),
					'base_price'  => $base_price,
					'category_id' => '0',
					'colors'      => self::colors( $variants ),
					'sizes'       => self::sizes( $variants ),
				),
				$views
			);
		} finally {
			foreach ( $temporary as $path ) {
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}

		$this->bases->set_provider( $id, 'gelato', $template_id, $map );

		return $id;
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
	 * A variant's colour (`variantOptions` "Color" or "Colour").
	 *
	 * @param array<mixed> $variant Variant.
	 */
	public static function color_of( array $variant ): string {
		return mb_substr( self::option( $variant, '/^colou?r$/i' ) ?? self::STANDARD_COLOR, 0, 60 );
	}

	/**
	 * A variant's size (`variantOptions` "Size"; a comma separates sizes).
	 *
	 * @param array<mixed> $variant Variant.
	 */
	public static function size_of( array $variant ): string {
		return mb_substr( str_replace( ',', ' ', self::option( $variant, '/^size$/i' ) ?? self::ONE_SIZE ), 0, 20 );
	}

	/**
	 * The sides of a variant's image placeholders, as the base product form
	 * sends them, and the placeholder name by side key.
	 *
	 * @phpstan-param list<string> $temporary
	 *
	 * @param array<mixed> $variant   First variant.
	 * @param string       $preview   The template's preview URL.
	 * @param string[]     $temporary Temporary files (cleaned by the caller).
	 * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
	 * @throws GelatoException When there is no usable placeholder.
	 */
	private function views( array $variant, string $preview, array &$temporary ): array {
		$body         = $this->api->download( $preview );
		$views        = array();
		$placeholders = array();
		$image        = null;

		foreach ( is_array( $variant['imagePlaceholders'] ?? null ) ? $variant['imagePlaceholders'] : array() as $placeholder ) {
			$name      = is_array( $placeholder ) && is_string( $placeholder['name'] ?? null ) ? $placeholder['name'] : '';
			$width_mm  = is_array( $placeholder ) ? (int) round( (float) ( $placeholder['width'] ?? 0 ) ) : 0;
			$height_mm = is_array( $placeholder ) ? (int) round( (float) ( $placeholder['height'] ?? 0 ) ) : 0;

			if ( '' === $name || $width_mm < 1 || $height_mm < 1 || max( $width_mm, $height_mm ) > BaseView::MAX_MM || count( $views ) >= self::MAX_SIDES ) {
				continue;
			}

			$label = is_string( $placeholder['printArea'] ?? null ) && '' !== trim( $placeholder['printArea'] ) ? $placeholder['printArea'] : $name;
			$label = mb_substr( trim( ucfirst( str_replace( array( '_', '-' ), ' ', $label ) ) ), 0, 60 );
			$key   = substr( sanitize_title( $label ), 0, 32 );

			if ( '' === $key || isset( $placeholders[ $key ] ) ) {
				continue;
			}

			$path                 = $this->template_image( $body, $temporary );
			$image              ??= wp_getimagesize( $path );
			$width                = is_array( $image ) ? (int) $image[0] : 0;
			$height               = is_array( $image ) ? (int) $image[1] : 0;
			$area                 = self::area( $width, $height, $width_mm, $height_mm );
			$placeholders[ $key ] = $name;
			$views[]              = array(
				'key'       => '',
				'name'      => $label,
				'x'         => (string) $area[0],
				'y'         => (string) $area[1],
				'width'     => (string) $area[2],
				'height'    => (string) $area[3],
				'width_mm'  => (string) $width_mm,
				'height_mm' => (string) $height_mm,
				'dpi'       => (string) self::dpi( max( $width_mm, $height_mm ) ),
				'remove'    => false,
				'clear'     => array(),
				'uploads'   => array(
					'background' => array(
						'name'     => 'template.png',
						'type'     => 'image/png',
						'tmp_name' => $path,
						'error'    => UPLOAD_ERR_OK,
						'size'     => (int) filesize( $path ),
					),
				),
			);
		}

		if ( array() === $views ) {
			self::fail( GelatoException::INVALID );
		}

		return array( $views, $placeholders );
	}

	/**
	 * The template's preview as a temporary PNG (one copy per side: saving
	 * moves it).
	 *
	 * @phpstan-param list<string> $temporary
	 *
	 * @param string   $body      Image bytes.
	 * @param string[] $temporary Temporary files.
	 * @throws GelatoException When it is not an image.
	 */
	private function template_image( string $body, array &$temporary ): string {
		$path = wp_tempnam( 'merchboard-gelato' );

		$temporary[] = $path;

		if ( false === file_put_contents( $path, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary file of the import.
			self::fail( GelatoException::INVALID );
		}

		$size = wp_getimagesize( $path );

		if ( ! is_array( $size ) || $size[0] > DesignerFiles::MAX_PIXELS || $size[1] > DesignerFiles::MAX_PIXELS ) {
			self::fail( GelatoException::INVALID );
		}

		if ( IMAGETYPE_PNG !== $size[2] ) {
			$editor = wp_get_image_editor( $path );
			$png    = $path . '.png';

			if ( is_wp_error( $editor ) || is_wp_error( $editor->save( $png, 'image/png' ) ) ) {
				self::fail( GelatoException::INVALID );
			}

			$temporary[] = $png;
			$path        = $png;
		}

		return $path;
	}

	/**
	 * A starting print area: half the template's width (or 80 % of its
	 * height) in the middle, in the print size's proportions.
	 *
	 * @param int $width     Template width.
	 * @param int $height    Template height.
	 * @param int $width_mm  Print width.
	 * @param int $height_mm Print height.
	 * @return array{0: int, 1: int, 2: int, 3: int} x, y, width, height.
	 */
	private static function area( int $width, int $height, int $width_mm, int $height_mm ): array {
		$area_width  = max( 1, (int) round( $width / 2 ) );
		$area_height = max( 1, (int) round( $area_width * $height_mm / $width_mm ) );

		if ( $area_height > $height * 0.8 ) {
			$area_height = max( 1, (int) round( $height * 0.8 ) );
			$area_width  = max( 1, min( $width, (int) round( $area_height * $width_mm / $height_mm ) ) );
		}

		return array( (int) floor( ( $width - $area_width ) / 2 ), (int) floor( ( $height - $area_height ) / 2 ), $area_width, $area_height );
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
	 * The variants' colours, one per line ("Name #ffffff"; Gelato gives no
	 * colour codes), in order.
	 *
	 * @param list<array<mixed>> $variants Variants.
	 */
	private static function colors( array $variants ): string {
		$colors = array();

		foreach ( $variants as $variant ) {
			$name = self::color_of( $variant );

			if ( ! isset( $colors[ $name ] ) && count( $colors ) < BaseProduct::MAX_COLORS ) {
				$colors[ $name ] = $name . ' #ffffff';
			}
		}

		return implode( "\n", $colors );
	}

	/**
	 * The variants' sizes, in order.
	 *
	 * @param list<array<mixed>> $variants Variants.
	 */
	private static function sizes( array $variants ): string {
		$sizes = array();

		foreach ( $variants as $variant ) {
			$size = self::size_of( $variant );

			if ( count( $sizes ) < BaseProduct::MAX_SIZES ) {
				$sizes[ $size ] = $size;
			}
		}

		return implode( ', ', $sizes );
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
