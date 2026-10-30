<?php
/**
 * Printful's catalog for base products.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-081 (step 30.12, catalog import from review 30.15): the administrator
 * searches Printful's catalog (title, type, brand or model, optionally in a
 * category with its subcategories) and picks a product. Its colours (name
 * and colour code), sizes and placements with the print file's size in
 * millimetres at its dpi are offered to tick. The blank photos and print
 * areas come from two tasks of the mockup generator (CatalogImport): every
 * picked colour with a transparent file in each placement, and the first
 * colour with a marker per placement. The catalog list and the categories
 * are cached for an hour.
 */
class PrintfulCatalog implements CatalogRenderer {

	public const SOURCE = 'printful';

	/**
	 * The cached catalog (transient).
	 */
	public const CACHE = 'merchboard_printful_catalog';

	/**
	 * The cached categories (transient).
	 */
	public const CATEGORIES = 'merchboard_printful_categories';

	/**
	 * Most search results shown.
	 */
	public const MAX_RESULTS = 48;

	private const POLL_DELAY = 15;

	/**
	 * Constructor.
	 *
	 * @param PrintfulApi $api Printful.
	 */
	public function __construct( private PrintfulApi $api ) {
	}

	/**
	 * Whether Printful may be used.
	 */
	public function available(): bool {
		return $this->api->available();
	}

	/**
	 * Catalog products whose title, type, brand or model holds every word,
	 * in a category (and its subcategories) when one is given.
	 *
	 * @param string $query    Words.
	 * @param int    $category Category; 0 for all.
	 * @return list<array{id: int, title: string, type: string, brand: string, model: string, image: string, category: int}>
	 * @throws PrintfulException When Printful refuses or fails.
	 */
	public function search( string $query, int $category = 0 ): array {
		$split    = preg_split( '/\s+/u', mb_strtolower( trim( $query ) ) );
		$words    = array_values( array_filter( false === $split ? array() : $split, static fn( string $word ): bool => '' !== $word ) );
		$included = $category > 0 ? $this->descendants( $category ) : array();
		$found    = array();

		foreach ( $this->products() as $product ) {
			if ( array() !== $included && ! isset( $included[ $product['category'] ] ) ) {
				continue;
			}

			$text = mb_strtolower( $product['title'] . ' ' . $product['type'] . ' ' . $product['brand'] . ' ' . $product['model'] );

			foreach ( $words as $word ) {
				if ( ! str_contains( $text, $word ) ) {
					continue 2;
				}
			}

			$found[] = $product;

			if ( count( $found ) >= self::MAX_RESULTS ) {
				break;
			}
		}

		return $found;
	}

	/**
	 * The catalog's categories, by title.
	 *
	 * @return list<array{id: int, parent: int, title: string}>
	 * @throws PrintfulException When Printful refuses or fails.
	 */
	public function categories(): array {
		$cached     = get_transient( self::CATEGORIES );
		$categories = array();

		if ( is_array( $cached ) ) {
			$list = $cached;
		} else {
			$answer = $this->api->categories();
			$list   = is_array( $answer['categories'] ?? null ) ? $answer['categories'] : array();
		}

		// The cache is read like Printful's answer: every entry is checked again.
		foreach ( $list as $category ) {
			$parent = is_array( $category ) ? ( $category['parent_id'] ?? $category['parent'] ?? 0 ) : 0;

			if ( is_array( $category ) && is_numeric( $category['id'] ?? null ) && is_string( $category['title'] ?? null ) ) {
				$categories[] = array(
					'id'     => (int) $category['id'],
					'parent' => is_numeric( $parent ) ? (int) $parent : 0,
					'title'  => mb_substr( sanitize_text_field( $category['title'] ), 0, 100 ),
				);
			}
		}

		usort( $categories, static fn( array $a, array $b ): int => strcasecmp( $a['title'], $b['title'] ) );

		if ( ! is_array( $cached ) ) {
			set_transient( self::CATEGORIES, $categories, HOUR_IN_SECONDS );
		}

		return $categories;
	}

	/**
	 * What the administrator can pick from a catalog product: the colours
	 * and sizes of its variants and the placements of its first variant
	 * with a print file.
	 *
	 * @param string $ref Printful product id.
	 * @return array{name: string, description: string, image: string, colors: list<array{name: string, hex: string}>, sizes: list<string>, sides: list<array{placement: string, name: string, width_mm: int, height_mm: int, dpi: int, width: int, height: int}>, variants: array<string, array<string, string>>}
	 * @throws PrintfulException When Printful refuses or fails, or the product cannot be a base product.
	 */
	public function choice( string $ref ): array {
		$id = 1 === preg_match( '/^\d{1,10}$/', $ref ) ? (int) $ref : 0;

		if ( $id < 1 ) {
			self::fail( PrintfulException::NOT_FOUND );
		}

		$catalog    = $this->api->product( $id );
		$printfiles = $this->api->printfiles( $id );
		$product    = is_array( $catalog['product'] ?? null ) ? $catalog['product'] : array();
		$colors     = array();
		$sizes      = array();
		$variants   = array();
		$first      = 0;

		foreach ( is_array( $catalog['variants'] ?? null ) ? $catalog['variants'] : array() as $variant ) {
			$color = is_array( $variant ) ? mb_substr( trim( sanitize_text_field( (string) ( $variant['color'] ?? '' ) ) ), 0, 60 ) : '';
			$size  = is_array( $variant ) ? mb_substr( trim( sanitize_text_field( (string) ( $variant['size'] ?? '' ) ) ), 0, 20 ) : '';

			if ( '' === $color || '' === $size || ! is_numeric( $variant['id'] ?? null ) ) {
				continue;
			}

			if ( ! isset( $colors[ $color ] ) && count( $colors ) < BaseProduct::MAX_COLORS ) {
				$hex              = is_string( $variant['color_code'] ?? null ) && 1 === preg_match( '/^#?([0-9a-fA-F]{6})$/', $variant['color_code'], $match ) ? '#' . strtolower( $match[1] ) : '#ffffff';
				$colors[ $color ] = array(
					'name' => $color,
					'hex'  => $hex,
				);
			}

			if ( ! in_array( $size, $sizes, true ) && count( $sizes ) < BaseProduct::MAX_SIZES ) {
				$sizes[] = $size;
			}

			$variants[ $color ][ $size ] ??= (string) (int) $variant['id'];
			$first                         = 0 === $first ? (int) $variant['id'] : $first;
		}

		$sides = self::sides( $printfiles, $first );

		if ( array() === $colors || array() === $sizes || array() === $sides ) {
			self::fail( PrintfulException::INVALID );
		}

		return array(
			'name'        => mb_substr( trim( sanitize_text_field( (string) ( $product['title'] ?? $product['model'] ?? 'Printful ' . $id ) ) ), 0, 191 ),
			'description' => mb_substr( wp_strip_all_tags( (string) ( $product['description'] ?? '' ) ), 0, 2000 ),
			'image'       => is_string( $product['image'] ?? null ) ? $product['image'] : '',
			'colors'      => array_values( $colors ),
			'sizes'       => $sizes,
			'sides'       => $sides,
			'variants'    => $variants,
		);
	}

	/**
	 * Creates a task at the mockup generator.
	 *
	 * @param string               $kind   `blank` or `marker`.
	 * @param array<string, mixed> $import Import data.
	 * @return string The task key.
	 * @throws PrintfulException When Printful refuses or fails.
	 */
	public function render( string $kind, array $import ): string {
		$colors   = is_array( $import['colors'] ?? null ) ? array_values( $import['colors'] ) : array();
		$variants = array_map( 'intval', 'marker' === $kind ? array_slice( $colors, 0, 1 ) : $colors );
		$files    = array();

		foreach ( is_array( $import['sides'] ?? null ) ? array_values( $import['sides'] ) : array() as $index => $side ) {
			$width  = is_array( $side ) ? (int) ( $side['width'] ?? 0 ) : 0;
			$height = is_array( $side ) ? (int) ( $side['height'] ?? 0 ) : 0;
			$url    = 'marker' === $kind ? ( $import['markers'][ $index ] ?? null ) : ( $import['blank'] ?? null );

			if ( ! is_array( $side ) || ! is_string( $side['placement'] ?? null ) || ! is_string( $url ) || $width < 1 || $height < 1 ) {
				self::fail( PrintfulException::INVALID );
			}

			$files[] = array(
				'placement' => $side['placement'],
				'image_url' => $url,
				'position'  => array(
					'area_width'  => $width,
					'area_height' => $height,
					'width'       => $width,
					'height'      => $height,
					'top'         => 0,
					'left'        => 0,
				),
			);
		}

		if ( array() === $variants || array() === $files ) {
			self::fail( PrintfulException::INVALID );
		}

		$answer = $this->api->create_task(
			(int) ( $import['ref'] ?? 0 ),
			array(
				'variant_ids' => $variants,
				'format'      => 'jpg',
				'files'       => $files,
			)
		);
		$key    = is_string( $answer['task_key'] ?? null ) ? $answer['task_key'] : '';

		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,190}$/', $key ) ) {
			self::fail( PrintfulException::INVALID );
		}

		return $key;
	}

	/**
	 * A task's state, with its mockups by placement and colour when done.
	 *
	 * @param string               $key    Task key.
	 * @param array<string, mixed> $import Import data.
	 * @return array{status: string, error?: string, images?: array<int|string, array<string, string>>}
	 * @throws PrintfulException When Printful refuses or fails.
	 */
	public function check( string $key, array $import ): array {
		$answer = $this->api->task( $key );
		$status = is_string( $answer['status'] ?? null ) ? $answer['status'] : '';

		if ( 'failed' === $status || ( is_string( $answer['error'] ?? null ) && '' !== $answer['error'] ) ) {
			return array(
				'status' => 'failed',
				'error'  => mb_substr( sanitize_text_field( is_string( $answer['error'] ?? null ) ? $answer['error'] : 'failed' ), 0, 300 ),
			);
		}

		if ( 'completed' !== $status ) {
			return array( 'status' => 'pending' );
		}

		$colors = array();
		$images = array();

		foreach ( is_array( $import['colors'] ?? null ) ? $import['colors'] : array() as $color => $variant ) {
			$colors[ (string) $variant ] = (string) $color;
		}

		foreach ( is_array( $answer['mockups'] ?? null ) ? $answer['mockups'] : array() as $mockup ) {
			$placement = is_array( $mockup ) && is_string( $mockup['placement'] ?? null ) ? $mockup['placement'] : '';
			$url       = is_array( $mockup ) && is_string( $mockup['mockup_url'] ?? null ) ? $mockup['mockup_url'] : '';

			foreach ( '' !== $placement && '' !== $url && is_array( $mockup['variant_ids'] ?? null ) ? $mockup['variant_ids'] : array() as $variant ) {
				$color = is_scalar( $variant ) ? ( $colors[ (string) $variant ] ?? null ) : null;

				if ( null !== $color ) {
					$images[ $placement ][ $color ] ??= $url;
				}
			}
		}

		return array(
			'status' => 'done',
			'images' => $images,
		);
	}

	/**
	 * A file Printful returned.
	 *
	 * @param string $url URL.
	 * @throws PrintfulException When it cannot be downloaded.
	 */
	public function download( string $url ): string {
		return $this->api->download( $url );
	}

	/**
	 * Seconds between two looks at a task.
	 */
	public function poll_delay(): int {
		return self::POLL_DELAY;
	}

	/**
	 * Printful's answers about a catalog product.
	 *
	 * @param string $ref Printful product id.
	 * @return array<string, mixed>
	 * @throws PrintfulException When Printful refuses or fails.
	 */
	public function diagnose( string $ref ): array {
		$id = (int) $ref;

		return array(
			'GET /products/' . $id                    => $this->api->product( $id ),
			'GET /mockup-generator/printfiles/' . $id => $this->api->printfiles( $id ),
		);
	}

	/**
	 * Printful's answer about a render's task.
	 *
	 * @param string $key Task key.
	 * @return array<string, mixed>
	 * @throws PrintfulException When Printful refuses or fails.
	 */
	public function render_answer( string $key ): array {
		return $this->api->task( $key );
	}

	/**
	 * The catalog's products that are not discontinued (cached).
	 *
	 * @return list<array{id: int, title: string, type: string, brand: string, model: string, image: string, category: int}>
	 * @throws PrintfulException When Printful refuses or fails.
	 */
	private function products(): array {
		$cached   = get_transient( self::CACHE );
		$products = array();
		$text     = static fn( array $product, string $field ): string => is_string( $product[ $field ] ?? null ) ? mb_substr( sanitize_text_field( $product[ $field ] ), 0, 191 ) : '';

		// The cache is read like Printful's answer: every entry is checked again.
		foreach ( is_array( $cached ) ? $cached : $this->api->products() as $product ) {
			if ( ! is_array( $product ) || ! is_numeric( $product['id'] ?? null ) || true === ( $product['is_discontinued'] ?? false ) ) {
				continue;
			}

			$category   = $product['main_category_id'] ?? $product['category'] ?? 0;
			$products[] = array(
				'id'       => (int) $product['id'],
				'title'    => $text( $product, 'title' ),
				'type'     => $text( $product, isset( $product['type_name'] ) ? 'type_name' : 'type' ),
				'brand'    => $text( $product, 'brand' ),
				'model'    => $text( $product, 'model' ),
				'image'    => is_string( $product['image'] ?? null ) && 'https' === wp_parse_url( $product['image'], PHP_URL_SCHEME ) ? $product['image'] : '',
				'category' => is_numeric( $category ) ? (int) $category : 0,
			);
		}

		if ( ! is_array( $cached ) ) {
			set_transient( self::CACHE, $products, HOUR_IN_SECONDS );
		}

		return $products;
	}

	/**
	 * A category and its subcategories.
	 *
	 * @param int $category Category.
	 * @return array<int, true>
	 * @throws PrintfulException When Printful refuses or fails.
	 */
	private function descendants( int $category ): array {
		$children = array();

		foreach ( $this->categories() as $entry ) {
			$children[ $entry['parent'] ][] = $entry['id'];
		}

		$found = array( $category => true );
		$queue = array( $category );

		while ( array() !== $queue ) {
			foreach ( $children[ array_shift( $queue ) ] ?? array() as $child ) {
				if ( ! isset( $found[ $child ] ) ) {
					$found[ $child ] = true;
					$queue[]         = $child;
				}
			}
		}

		return $found;
	}

	/**
	 * The placements with a print file of a variant (the first one), with
	 * the print file's size.
	 *
	 * @param array<string, mixed> $printfiles Print files answer.
	 * @param int                  $variant    Variant.
	 * @return list<array{placement: string, name: string, width_mm: int, height_mm: int, dpi: int, width: int, height: int}>
	 */
	private static function sides( array $printfiles, int $variant ): array {
		$files   = array();
		$mapping = array();
		$labels  = is_array( $printfiles['available_placements'] ?? null ) ? $printfiles['available_placements'] : array();

		foreach ( is_array( $printfiles['printfiles'] ?? null ) ? $printfiles['printfiles'] : array() as $file ) {
			if ( is_array( $file ) && is_numeric( $file['printfile_id'] ?? null ) ) {
				$files[ (int) $file['printfile_id'] ] = $file;
			}
		}

		foreach ( is_array( $printfiles['variant_printfiles'] ?? null ) ? $printfiles['variant_printfiles'] : array() as $entry ) {
			if ( is_array( $entry ) && ( (int) ( $entry['variant_id'] ?? 0 ) === $variant || array() === $mapping ) && is_array( $entry['placements'] ?? null ) ) {
				$mapping = $entry['placements'];
			}
		}

		$sides = array();

		foreach ( $labels as $placement => $label ) {
			$file   = $files[ (int) ( $mapping[ $placement ] ?? 0 ) ] ?? null;
			$width  = is_array( $file ) ? (int) ( $file['width'] ?? 0 ) : 0;
			$height = is_array( $file ) ? (int) ( $file['height'] ?? 0 ) : 0;
			$dpi    = min( BaseView::MAX_DPI, max( BaseView::MIN_DPI, is_array( $file ) ? (int) ( $file['dpi'] ?? 150 ) : 150 ) );
			$name   = mb_substr( trim( sanitize_text_field( is_string( $label ) ? $label : ucfirst( str_replace( '_', ' ', (string) $placement ) ) ) ), 0, 60 );
			$mm_w   = (int) round( $width * 25.4 / $dpi );
			$mm_h   = (int) round( $height * 25.4 / $dpi );

			if ( ! is_string( $placement ) || '' === $name || $mm_w < 1 || $mm_h < 1 || max( $mm_w, $mm_h ) > BaseView::MAX_MM ) {
				continue;
			}

			$sides[] = array(
				'placement' => $placement,
				'name'      => $name,
				'width_mm'  => $mm_w,
				'height_mm' => $mm_h,
				'dpi'       => $dpi,
				'width'     => $width,
				'height'    => $height,
			);
		}

		return $sides;
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
