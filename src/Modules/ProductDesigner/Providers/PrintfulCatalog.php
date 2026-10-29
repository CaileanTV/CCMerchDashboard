<?php
/**
 * Base products from Printful's catalog.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\DesignerException;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-081 (step 30.12): a Printful catalog product becomes a base product
 * (a draft, source `printful`): its title and description, the colours
 * (name and colour code) and sizes of its variants, and one side per
 * placement of its first variant's layout templates, with the template
 * image as the background layer, the template's print area and the print
 * file's size in millimetres at its dpi. The administrator sets the base
 * price and activates it after a look. The side keys are mapped to
 * Printful's placements for the mockup generator. Template images are
 * downloaded through PrintfulApi and stored like uploaded layers.
 */
class PrintfulCatalog {

	/**
	 * Most sides imported.
	 */
	private const MAX_SIDES = 6;

	/**
	 * Constructor.
	 *
	 * @param PrintfulApi           $api      Printful.
	 * @param BaseProductService    $service  Base products (stores files by moving them).
	 * @param BaseProductRepository $bases    Base products.
	 */
	public function __construct( private PrintfulApi $api, private BaseProductService $service, private BaseProductRepository $bases ) {
	}

	/**
	 * Imports a catalog product as a base product draft.
	 *
	 * @param int    $product_id Printful product id.
	 * @param string $base_price Base price incl. VAT (as typed).
	 * @return int The base product's id.
	 * @throws PrintfulException When Printful refuses or fails.
	 * @throws DesignerException When the product cannot be a base product.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function import( int $product_id, string $base_price ): int {
		if ( $product_id < 1 ) {
			self::fail( PrintfulException::NOT_FOUND );
		}

		$catalog    = $this->api->product( $product_id );
		$printfiles = $this->api->printfiles( $product_id );
		$templates  = $this->api->templates( $product_id );
		$product    = is_array( $catalog['product'] ?? null ) ? $catalog['product'] : array();
		$variants   = array_values( array_filter( is_array( $catalog['variants'] ?? null ) ? $catalog['variants'] : array(), 'is_array' ) );

		if ( array() === $variants ) {
			self::fail( PrintfulException::INVALID );
		}

		$temporary = array();

		try {
			[ $views, $placements ] = $this->views( (int) ( $variants[0]['id'] ?? 0 ), $printfiles, $templates, $temporary );
			$id                     = $this->service->save(
				null,
				array(
					'name'        => mb_substr( trim( (string) ( $product['title'] ?? $product['model'] ?? 'Printful ' . $product_id ) ), 0, 191 ),
					'description' => mb_substr( wp_strip_all_tags( (string) ( $product['description'] ?? '' ) ), 0, 2000 ),
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

		$this->bases->set_provider( $id, 'printful', (string) $product_id, (string) wp_json_encode( $placements ) );

		return $id;
	}

	/**
	 * The sides of the first variant's templates, as the base product form
	 * sends them, and the Printful placement by side key.
	 *
	 * @phpstan-param list<string> $temporary
	 *
	 * @param int                  $variant    First variant.
	 * @param array<string, mixed> $printfiles Print files answer.
	 * @param array<string, mixed> $templates  Templates answer.
	 * @param string[]             $temporary  Temporary files (cleaned by the caller).
	 * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
	 * @throws PrintfulException When there is no usable template.
	 */
	private function views( int $variant, array $printfiles, array $templates, array &$temporary ): array {
		$mapping = array();

		foreach ( is_array( $templates['variant_mapping'] ?? null ) ? $templates['variant_mapping'] : array() as $entry ) {
			if ( is_array( $entry ) && (int) ( $entry['variant_id'] ?? 0 ) === $variant && is_array( $entry['templates'] ?? null ) ) {
				$mapping = $entry['templates'];
				break;
			}
		}

		$by_id  = array();
		$files  = array();
		$labels = is_array( $printfiles['available_placements'] ?? null ) ? $printfiles['available_placements'] : array();

		foreach ( is_array( $templates['templates'] ?? null ) ? $templates['templates'] : array() as $template ) {
			if ( is_array( $template ) ) {
				$by_id[ (int) ( $template['template_id'] ?? 0 ) ] = $template;
			}
		}

		foreach ( is_array( $printfiles['printfiles'] ?? null ) ? $printfiles['printfiles'] : array() as $file ) {
			if ( is_array( $file ) ) {
				$files[ (int) ( $file['printfile_id'] ?? 0 ) ] = $file;
			}
		}

		$views      = array();
		$placements = array();

		foreach ( $mapping as $entry ) {
			$placement = is_array( $entry ) && is_string( $entry['placement'] ?? null ) ? $entry['placement'] : '';
			$template  = $by_id[ (int) ( is_array( $entry ) ? ( $entry['template_id'] ?? 0 ) : 0 ) ] ?? null;
			$file      = null === $template ? null : ( $files[ (int) ( $template['printfile_id'] ?? 0 ) ] ?? null );

			if ( '' === $placement || null === $template || null === $file || ! is_string( $template['image_url'] ?? null ) || count( $views ) >= self::MAX_SIDES ) {
				continue;
			}

			$name = mb_substr( trim( is_string( $labels[ $placement ] ?? null ) ? $labels[ $placement ] : ucfirst( str_replace( '_', ' ', $placement ) ) ), 0, 60 );
			$key  = substr( sanitize_title( $name ), 0, 32 );

			if ( '' === $key || isset( $placements[ $key ] ) ) {
				continue;
			}

			[ $path, $width ] = $this->template_image( $template['image_url'], $temporary );
			$scale            = $width / max( 1, (int) ( $template['template_width'] ?? $width ) );
			$dpi              = min( BaseView::MAX_DPI, max( BaseView::MIN_DPI, (int) ( $file['dpi'] ?? 150 ) ) );

			$placements[ $key ] = $placement;
			$views[]            = array(
				'key'       => '',
				'name'      => $name,
				'x'         => (string) (int) round( (float) ( $template['print_area_left'] ?? 0 ) * $scale ),
				'y'         => (string) (int) round( (float) ( $template['print_area_top'] ?? 0 ) * $scale ),
				'width'     => (string) (int) round( (float) ( $template['print_area_width'] ?? 0 ) * $scale ),
				'height'    => (string) (int) round( (float) ( $template['print_area_height'] ?? 0 ) * $scale ),
				'width_mm'  => (string) max( 1, (int) round( (int) ( $file['width'] ?? 0 ) * 25.4 / $dpi ) ),
				'height_mm' => (string) max( 1, (int) round( (int) ( $file['height'] ?? 0 ) * 25.4 / $dpi ) ),
				'dpi'       => (string) $dpi,
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
			self::fail( PrintfulException::INVALID );
		}

		return array( $views, $placements );
	}

	/**
	 * A template image as a temporary PNG and its width.
	 *
	 * @phpstan-param list<string> $temporary
	 *
	 * @param string   $url       Image URL from Printful.
	 * @param string[] $temporary Temporary files.
	 * @return array{0: string, 1: int}
	 * @throws PrintfulException When it is not an image.
	 */
	private function template_image( string $url, array &$temporary ): array {
		$body = $this->api->download( $url );
		$path = wp_tempnam( 'merchboard-printful' );

		$temporary[] = $path;

		if ( false === file_put_contents( $path, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary file of the import.
			self::fail( PrintfulException::INVALID );
		}

		$size = wp_getimagesize( $path );

		if ( ! is_array( $size ) ) {
			self::fail( PrintfulException::INVALID );
		}

		if ( IMAGETYPE_PNG !== $size[2] ) {
			$editor = wp_get_image_editor( $path );
			$png    = $path . '.png';

			if ( is_wp_error( $editor ) || is_wp_error( $editor->save( $png, 'image/png' ) ) ) {
				self::fail( PrintfulException::INVALID );
			}

			$temporary[] = $png;
			$path        = $png;
		}

		return array( $path, (int) $size[0] );
	}

	/**
	 * The variants' colours, one per line ("Name #hex"), in order.
	 *
	 * @param list<array<mixed>> $variants Variants.
	 */
	private static function colors( array $variants ): string {
		$colors = array();

		foreach ( $variants as $variant ) {
			$name = trim( (string) ( $variant['color'] ?? '' ) );
			$hex  = is_string( $variant['color_code'] ?? null ) && 1 === preg_match( '/^#?[0-9a-fA-F]{6}$/', $variant['color_code'] ) ? ltrim( $variant['color_code'], '#' ) : 'ffffff';

			if ( '' !== $name && ! isset( $colors[ $name ] ) ) {
				$colors[ $name ] = $name . ' #' . $hex;
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
			$size = trim( (string) ( $variant['size'] ?? '' ) );

			if ( '' !== $size ) {
				$sizes[ $size ] = $size;
			}
		}

		return implode( ', ', $sizes );
	}
	/**
	 * Fails with a reason.
	 *
	 * @param string $reason PrintfulException reason.
	 * @throws PrintfulException Always.
	 */
	private static function fail( string $reason ): never {
		throw new PrintfulException( $reason );
	}
}
