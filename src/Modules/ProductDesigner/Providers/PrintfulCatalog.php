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
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;

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
 *
 * Europe first (the owner's choice 2026-10-31): v2 lists only products sold
 * in Europe that can be sent to Denmark, and only variants in stock in Europe
 * are offered. A v2 call that fails, other than 429, falls back on v1, where
 * the list is unfiltered (limited_to_eu() tells) and the variants' own
 * availability_status decides. "Europe" is Printful's selling region: its
 * docs do not promise production inside the EU.
 *
 * A marker must fill the whole print area, and v2 refuses a file whose shape
 * differs from its position by more than 2 %, so a base product's renders stay
 * on v1 (real answers 2026-10-31). The designs' mockups use v2.
 *
 * An all-over product (cut-and-sew, sublimation) has no photo to measure:
 * the creator designs on the flat sewing pattern (flat sides, ADR-081) and
 * Printful sews the design into mockups afterwards. Such a product is
 * imported only through v2, which alone gives its templates. Its print size
 * can depend on the size (a pillow), so its sizes are offered in groups with
 * the same print sizes, one group per base product.
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

	/**
	 * The selling region and destination of the catalog.
	 */
	public const REGION = 'europe';

	public const COUNTRY = 'DK';

	/**
	 * The techniques whose product is designed on its flat pattern.
	 */
	public const FLAT_TECHNIQUES = array( 'cut-sew', 'sublimation' );

	/**
	 * Yarn colours need their own design; not offered yet.
	 */
	private const UNSUPPORTED_TECHNIQUE = 'knitwear';

	/**
	 * Seconds v1's unfiltered list is kept: v2 is tried again soon.
	 */
	private const FALLBACK_CACHE = 300;

	/**
	 * Mockup categories made for a season: never a product's photo.
	 */
	private const SEASONAL = '/halloween|holiday|christmas|xmas|valentine|easter|black friday/i';

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
	 * Whether the list is limited to Europe; false while v2 fails and v1's
	 * unfiltered list is shown.
	 *
	 * @throws PrintfulException When Printful refuses or fails.
	 */
	public function limited_to_eu(): bool {
		return $this->catalog()['eu'];
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
			$list = $this->fetch_categories();
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
	 * and sizes of its variants in stock in Europe and its placements. v2
	 * first, v1 when v2 fails.
	 *
	 * @param string $ref   Printful product id.
	 * @param int    $group The group of sizes with the same print sizes.
	 * @return array{name: string, description: string, image: string, colors: list<array{name: string, hex: string}>, sizes: list<string>, sides: list<array{placement: string, name: string, width_mm: int, height_mm: int, dpi: int, width: int, height: int, technique?: string, style?: int, template?: string, template_width?: int, template_height?: int, area?: array{x: int, y: int, width: int, height: int}}>, variants: array<string, array<string, string>>, flat?: bool, groups?: list<string>, group?: int, options?: list<array{name: string, value: string}>}
	 * @throws PrintfulException When Printful refuses or fails, or the product cannot be a base product.
	 */
	public function choice( string $ref, int $group = 0 ): array {
		$id = 1 === preg_match( '/^\d{1,10}$/', $ref ) ? (int) $ref : 0;

		if ( $id < 1 ) {
			self::fail( PrintfulException::NOT_FOUND );
		}

		try {
			return $this->choice_v2( $id, $group );
		} catch ( PrintfulException $e ) {
			if ( self::stands( $e ) ) {
				throw $e;
			}
		}

		return $this->choice_v1( $id );
	}

	/**
	 * What v2 offers of a product.
	 *
	 * @param int $id    Printful product.
	 * @param int $group The group of sizes.
	 * @return array{name: string, description: string, image: string, colors: list<array{name: string, hex: string}>, sizes: list<string>, sides: list<array{placement: string, name: string, width_mm: int, height_mm: int, dpi: int, width: int, height: int, technique?: string, style?: int, template?: string, template_width?: int, template_height?: int, area?: array{x: int, y: int, width: int, height: int}}>, variants: array<string, array<string, string>>, flat: bool, groups: list<string>, group: int, options: list<array{name: string, value: string}>}
	 * @throws PrintfulException When Printful refuses or fails, or the product cannot be a base product.
	 */
	private function choice_v2( int $id, int $group ): array {
		$product   = $this->api->catalog_product( $id, self::REGION );
		$technique = self::default_technique( $product );

		if ( self::UNSUPPORTED_TECHNIQUE === $technique ) {
			self::fail( PrintfulException::UNSUPPORTED );
		}

		$flat  = in_array( $technique, self::FLAT_TECHNIQUES, true );
		$stock = $this->stock( $id, $flat ? $technique : '' );
		$rows  = array_values(
			array_filter(
				$this->api->catalog_variants( $id ),
				static fn( mixed $variant ): bool => is_array( $variant ) && is_numeric( $variant['id'] ?? null ) && isset( $stock[ (int) $variant['id'] ] )
			)
		);

		if ( array() === $rows ) {
			self::fail( PrintfulException::NOT_IN_EU );
		}

		$groups = $flat ? self::size_groups( $rows ) : array( $rows );
		$group  = isset( $groups[ $group ] ) ? $group : 0;
		$first  = $groups[ $group ][0];
		$sides  = self::sides_v2( $first, $this->api->mockup_styles( $id, self::REGION ), $technique, $flat );

		if ( $flat ) {
			$sides = self::with_templates( $sides, $this->api->mockup_templates( $id, self::REGION ), (int) $first['id'] );
		}

		[ $colors, $sizes, $variants ] = self::variant_map( $groups[ $group ] );

		if ( array() === $colors || array() === $sizes || array() === $sides ) {
			self::fail( PrintfulException::INVALID );
		}

		return array(
			'name'        => mb_substr( trim( sanitize_text_field( (string) ( $product['name'] ?? 'Printful ' . $id ) ) ), 0, 191 ),
			'description' => self::description( (string) ( $product['description'] ?? '' ) ),
			'image'       => is_string( $product['image'] ?? null ) ? $product['image'] : '',
			'colors'      => $colors,
			'sizes'       => $sizes,
			'sides'       => $sides,
			'variants'    => $variants,
			'flat'        => $flat,
			'groups'      => 1 < count( $groups ) ? array_map( static fn( array $rows ): string => implode( ', ', self::variant_map( $rows )[1] ), $groups ) : array(),
			'group'       => $group,
			'options'     => $flat ? self::stitch_option( $product ) : array(),
		);
	}

	/**
	 * What v1 offers of a product: its colours and sizes in stock in Europe
	 * and the placements of its first variant with a print file.
	 *
	 * @param int $id Printful product.
	 * @return array{name: string, description: string, image: string, colors: list<array{name: string, hex: string}>, sizes: list<string>, sides: list<array{placement: string, name: string, width_mm: int, height_mm: int, dpi: int, width: int, height: int}>, variants: array<string, array<string, string>>}
	 * @throws PrintfulException When Printful refuses or fails, or the product cannot be a base product.
	 */
	private function choice_v1( int $id ): array {
		$catalog = $this->api->product( $id );
		$product = is_array( $catalog['product'] ?? null ) ? $catalog['product'] : array();

		foreach ( is_array( $product['techniques'] ?? null ) ? $product['techniques'] : array() as $technique ) {
			if ( is_array( $technique ) && in_array( strtolower( (string) ( $technique['key'] ?? '' ) ), self::FLAT_TECHNIQUES, true ) ) {
				self::fail( PrintfulException::V2_REQUIRED );
			}
		}

		$rows = array_values( array_filter( is_array( $catalog['variants'] ?? null ) ? $catalog['variants'] : array(), 'is_array' ) );
		$sold = array_values( array_filter( $rows, array( self::class, 'in_stock_v1' ) ) );

		if ( array() !== $rows && array() === $sold ) {
			self::fail( PrintfulException::NOT_IN_EU );
		}

		[ $colors, $sizes, $variants ] = self::variant_map( $sold );

		$sides = self::sides( $this->api->printfiles( $id ), array() === $sold ? 0 : (int) ( $sold[0]['id'] ?? 0 ) );

		if ( array() === $colors || array() === $sizes || array() === $sides ) {
			self::fail( PrintfulException::INVALID );
		}

		return array(
			'name'        => mb_substr( trim( sanitize_text_field( (string) ( $product['title'] ?? $product['model'] ?? 'Printful ' . $id ) ) ), 0, 191 ),
			'description' => self::description( (string) ( $product['description'] ?? '' ) ),
			'image'       => is_string( $product['image'] ?? null ) ? $product['image'] : '',
			'colors'      => $colors,
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
	 * @return array{status: string, error?: string, note?: string, images?: array<int|string, array<string, string>>}
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

		$note = 'status=' . ( '' === $status ? '?' : sanitize_key( $status ) );

		if ( 'completed' !== $status ) {
			return array(
				'status' => 'pending',
				'note'   => $note,
			);
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
			'note'   => $note,
		);
	}

	/**
	 * One blank render of every colour and one with the markers.
	 *
	 * @param array<string, mixed> $import Import data.
	 * @return list<string>
	 */
	public function render_kinds( array $import ): array {
		unset( $import );

		return array( 'blank', 'marker' );
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
		$id      = (int) $ref;
		$answers = array(
			'GET /products/' . $id                    => $this->api->product( $id ),
			'GET /mockup-generator/printfiles/' . $id => $this->api->printfiles( $id ),
		);

		// v2 is in beta: a refused call must not hide v1's answers.
		try {
			$answers[ 'GET /v2/catalog-products/' . $id ]                    = $this->api->catalog_product( $id, self::REGION );
			$answers[ 'GET /v2/catalog-products/' . $id . '/mockup-styles' ] = $this->api->mockup_styles( $id, self::REGION );
		} catch ( PrintfulException $e ) {
			$answers['v2'] = array(
				'reason' => $e->reason(),
				'call'   => $e->call,
				'detail' => $e->detail,
			);
		}

		return $answers;
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
		return $this->catalog()['products'];
	}

	/**
	 * The catalog (cached) and whether it is limited to Europe.
	 *
	 * @return array{eu: bool, products: list<array{id: int, title: string, type: string, brand: string, model: string, image: string, category: int}>}
	 * @throws PrintfulException When Printful refuses or fails.
	 */
	private function catalog(): array {
		$cached = get_transient( self::CACHE );
		$valid  = is_array( $cached ) && is_array( $cached['products'] ?? null );

		if ( $valid ) {
			$eu   = true === ( $cached['eu'] ?? false );
			$list = $cached['products'];
		} else {
			[ $eu, $list ] = $this->fetch_catalog();
		}

		$products = array();
		$text     = static fn( array $product, string $field ): string => is_string( $product[ $field ] ?? null ) ? mb_substr( sanitize_text_field( $product[ $field ] ), 0, 191 ) : '';

		// The cache is read like Printful's answer: every entry is checked again.
		foreach ( $list as $product ) {
			if ( ! is_array( $product ) || ! is_numeric( $product['id'] ?? null ) || true === ( $product['is_discontinued'] ?? false ) || self::UNSUPPORTED_TECHNIQUE === self::default_technique( $product ) ) {
				continue;
			}

			$category   = $product['main_category_id'] ?? $product['category'] ?? 0;
			$products[] = array(
				'id'       => (int) $product['id'],
				'title'    => $text( $product, isset( $product['title'] ) ? 'title' : 'name' ),
				'type'     => $text( $product, isset( $product['type_name'] ) ? 'type_name' : 'type' ),
				'brand'    => $text( $product, 'brand' ),
				'model'    => $text( $product, 'model' ),
				'image'    => is_string( $product['image'] ?? null ) && 'https' === wp_parse_url( $product['image'], PHP_URL_SCHEME ) ? $product['image'] : '',
				'category' => is_numeric( $category ) ? (int) $category : 0,
			);
		}

		$catalog = array(
			'eu'       => $eu,
			'products' => $products,
		);

		if ( ! $valid ) {
			set_transient( self::CACHE, $catalog, $eu ? HOUR_IN_SECONDS : self::FALLBACK_CACHE );
		}

		return $catalog;
	}

	/**
	 * The list from v2, else v1's unfiltered list.
	 *
	 * @return array{0: bool, 1: array<mixed>} Whether it is limited to Europe, and the list.
	 * @throws PrintfulException When Printful refuses or fails.
	 */
	private function fetch_catalog(): array {
		try {
			return array( true, $this->api->catalog_products( self::REGION, self::COUNTRY ) );
		} catch ( PrintfulException $e ) {
			if ( self::stands( $e ) ) {
				throw $e;
			}
		}

		return array( false, $this->api->products() );
	}

	/**
	 * The categories from v2, else from v1.
	 *
	 * @return array<mixed>
	 * @throws PrintfulException When Printful refuses or fails.
	 */
	private function fetch_categories(): array {
		try {
			return $this->api->catalog_categories();
		} catch ( PrintfulException $e ) {
			if ( self::stands( $e ) ) {
				throw $e;
			}
		}

		$answer = $this->api->categories();

		return is_array( $answer['categories'] ?? null ) ? $answer['categories'] : array();
	}

	/**
	 * Whether a failure of v2 stands: v1 would not change it.
	 *
	 * @param PrintfulException $e Failure.
	 */
	private static function stands( PrintfulException $e ): bool {
		return in_array( $e->reason(), array( PrintfulException::RATE_LIMITED, PrintfulException::NOT_IN_EU, PrintfulException::UNSUPPORTED ), true );
	}

	/**
	 * A product's default technique in v2, or ''.
	 *
	 * @param array<mixed> $product v2 product.
	 */
	private static function default_technique( array $product ): string {
		$first = '';

		foreach ( is_array( $product['techniques'] ?? null ) ? $product['techniques'] : array() as $technique ) {
			$key   = is_array( $technique ) && is_string( $technique['key'] ?? null ) ? $technique['key'] : '';
			$first = '' === $first ? $key : $first;

			if ( is_array( $technique ) && true === ( $technique['is_default'] ?? false ) ) {
				return $key;
			}
		}

		return $first;
	}

	/**
	 * The variants in stock in Europe, in one technique when one is named.
	 *
	 * @param int    $id        Printful product.
	 * @param string $technique Technique; '' for any.
	 * @return array<int, true>
	 * @throws PrintfulException When Printful refuses or fails.
	 */
	private function stock( int $id, string $technique ): array {
		$stock = array();

		foreach ( $this->api->availability( $id, self::REGION ) as $row ) {
			foreach ( is_array( $row ) && is_array( $row['techniques'] ?? null ) ? $row['techniques'] : array() as $entry ) {
				if ( ! is_array( $entry ) || ( '' !== $technique && ( $entry['technique'] ?? '' ) !== $technique ) ) {
					continue;
				}

				foreach ( is_array( $entry['selling_regions'] ?? null ) ? $entry['selling_regions'] : array() as $region ) {
					if ( is_array( $region ) && self::REGION === ( $region['name'] ?? '' ) && 'in stock' === ( $region['availability'] ?? '' ) && is_numeric( $row['catalog_variant_id'] ?? null ) ) {
						$stock[ (int) $row['catalog_variant_id'] ] = true;
					}
				}
			}
		}

		return $stock;
	}

	/**
	 * Whether a v1 variant is in stock in Europe. A variant without the field
	 * counts as sold: v1 is the fallback and must not block a product.
	 *
	 * @param array<mixed> $variant v1 variant.
	 */
	private static function in_stock_v1( array $variant ): bool {
		$list = is_array( $variant['availability_status'] ?? null ) ? $variant['availability_status'] : array();

		foreach ( $list as $entry ) {
			if ( is_array( $entry ) && 'EU' === ( $entry['region'] ?? '' ) && 'in_stock' === ( $entry['status'] ?? '' ) ) {
				return true;
			}
		}

		return array() === $list;
	}

	/**
	 * Colours, sizes and the variant of each colour and size. A variant
	 * without a colour (a pillow) is "Standard".
	 *
	 * @param array<array<mixed>> $rows Variants.
	 * @return array{0: list<array{name: string, hex: string}>, 1: list<string>, 2: array<string, array<string, string>>}
	 */
	private static function variant_map( array $rows ): array {
		$colors   = array();
		$sizes    = array();
		$variants = array();

		foreach ( $rows as $variant ) {
			$color = mb_substr( trim( sanitize_text_field( (string) ( $variant['color'] ?? '' ) ) ), 0, 60 );
			$color = '' === $color ? GelatoCatalog::STANDARD_COLOR : $color;
			$size  = mb_substr( trim( sanitize_text_field( (string) ( $variant['size'] ?? '' ) ) ), 0, 20 );

			if ( '' === $size || ! is_numeric( $variant['id'] ?? null ) ) {
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
		}

		return array( array_values( $colors ), self::sorted_sizes( $sizes ), $variants );
	}

	/**
	 * Sizes from small to large; v2 gives them in no order, and others stay
	 * in Printful's.
	 *
	 * @phpstan-param list<string> $sizes
	 * @phpstan-return list<string>
	 *
	 * @param string[] $sizes Sizes.
	 * @return string[]
	 */
	private static function sorted_sizes( array $sizes ): array {
		$order = array_flip( array( 'XXS', '2XS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '2XL', 'XXXL', '3XL', '4XL', '5XL', '6XL' ) );
		$rank  = static fn( string $size ): int => $order[ strtoupper( $size ) ] ?? count( $order );

		usort( $sizes, static fn( string $a, string $b ): int => $rank( $a ) <=> $rank( $b ) );

		return $sizes;
	}

	/**
	 * A provider's description as plain text: a tag ends a word, so it leaves
	 * a space behind.
	 *
	 * @param string $html Description.
	 */
	public static function description( string $html ): string {
		$text = wp_strip_all_tags( (string) preg_replace( '/<[^>]+>/', '$0 ', $html ) );

		return mb_substr( trim( (string) preg_replace( '/[ \t]{2,}/', ' ', $text ) ), 0, 2000 );
	}

	/**
	 * The variants in groups with the same print sizes, in Printful's order.
	 *
	 * @param list<array<mixed>> $rows Variants.
	 * @return list<non-empty-list<array<mixed>>>
	 */
	private static function size_groups( array $rows ): array {
		$groups = array();

		foreach ( $rows as $variant ) {
			$groups[ (string) wp_json_encode( self::dimensions( $variant ) ) ][] = $variant;
		}

		return array_values( $groups );
	}

	/**
	 * A variant's print size by placement in inches: its first of each (a
	 * tote's second one is the advanced area).
	 *
	 * @param array<mixed> $variant v2 variant.
	 * @return array<string, array{0: float, 1: float}>
	 */
	private static function dimensions( array $variant ): array {
		$sizes = array();

		foreach ( is_array( $variant['placement_dimensions'] ?? null ) ? $variant['placement_dimensions'] : array() as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['placement'] ?? null ) && is_numeric( $entry['width'] ?? null ) && is_numeric( $entry['height'] ?? null ) ) {
				$sizes[ $entry['placement'] ] ??= array( (float) $entry['width'], (float) $entry['height'] );
			}
		}

		return $sizes;
	}

	/**
	 * The sides v2 offers for a variant. A placement's size is the variant's;
	 * its dpi, name and technique come from the row of that size.
	 *
	 * @param array<mixed> $variant   First variant.
	 * @param array<mixed> $rows      Mockup styles by placement.
	 * @param string       $technique The product's default technique.
	 * @param bool         $flat      Whether the product is designed flat.
	 * @return list<array{placement: string, name: string, width_mm: int, height_mm: int, dpi: int, width: int, height: int, technique: string, style: int}>
	 */
	private static function sides_v2( array $variant, array $rows, string $technique, bool $flat ): array {
		$sizes   = self::dimensions( $variant );
		$picked  = array();
		$allowed = $flat ? array( $technique ) : array( $technique, 'embroidery' );

		foreach ( $rows as $row ) {
			$placement = is_array( $row ) && is_string( $row['placement'] ?? null ) ? $row['placement'] : '';

			if ( '' === $placement || 'mockup' === $placement || ! isset( $sizes[ $placement ] ) || ! in_array( $row['technique'] ?? '', $allowed, true ) || 'advanced' === ( $row['print_area_type'] ?? '' ) ) {
				continue;
			}

			$exact = (float) ( $row['print_area_width'] ?? 0 ) === $sizes[ $placement ][0] && (float) ( $row['print_area_height'] ?? 0 ) === $sizes[ $placement ][1];

			if ( ! isset( $picked[ $placement ] ) || $exact ) {
				$picked[ $placement ] = $row;
			}
		}

		$sides = array();

		foreach ( $picked as $placement => $row ) {
			$placement = (string) $placement;
			$dpi       = min( BaseView::MAX_DPI, max( BaseView::MIN_DPI, (int) ( $row['dpi'] ?? 150 ) ) );
			$width     = (int) round( $sizes[ $placement ][0] * $dpi );
			$height    = (int) round( $sizes[ $placement ][1] * $dpi );
			$mm_w      = (int) round( $sizes[ $placement ][0] * 25.4 );
			$mm_h      = (int) round( $sizes[ $placement ][1] * 25.4 );
			$name      = mb_substr( trim( sanitize_text_field( is_string( $row['display_name'] ?? null ) ? $row['display_name'] : ucfirst( str_replace( '_', ' ', $placement ) ) ) ), 0, 60 );

			if ( '' === $name || $mm_w < 1 || $mm_h < 1 || max( $mm_w, $mm_h ) > BaseView::MAX_MM || max( $width, $height ) > DesignerFiles::MAX_PIXELS ) {
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
				'technique' => is_string( $row['technique'] ?? null ) ? $row['technique'] : $technique,
				'style'     => $flat ? self::style_of( $placement, $row, (int) ( $variant['id'] ?? 0 ) ) : 0,
			);
		}

		return $sides;
	}

	/**
	 * The mockup style that shows a placement: the view it faces, or the
	 * first style for a placement that faces no view. Seasonal photos are
	 * never a product's. 0 when there is none.
	 *
	 * @param string       $placement Placement.
	 * @param array<mixed> $row       The placement's row of mockup styles.
	 * @param int          $variant   Variant.
	 */
	private static function style_of( string $placement, array $row, int $variant ): int {
		$views  = array(
			'default'      => 'Front',
			'front'        => 'Front',
			'back'         => 'Back',
			'sleeve_left'  => 'Left',
			'sleeve_right' => 'Right',
		);
		$view   = $views[ $placement ] ?? '';
		$styles = array();

		foreach ( is_array( $row['mockup_styles'] ?? null ) ? $row['mockup_styles'] : array() as $style ) {
			$only = is_array( $style ) ? ( $style['restricted_to_variants'] ?? null ) : null;

			if ( is_array( $style ) && is_numeric( $style['id'] ?? null ) && ( ! is_array( $only ) || in_array( $variant, array_map( 'intval', $only ), true ) ) && 1 !== preg_match( self::SEASONAL, (string) ( $style['category_name'] ?? '' ) ) ) {
				$styles[] = $style;
			}
		}

		foreach ( $styles as $style ) {
			if ( ( $style['view_name'] ?? null ) === $view ) {
				return (int) $style['id'];
			}
		}

		return isset( $styles[0] ) ? (int) $styles[0]['id'] : 0;
	}

	/**
	 * The stitch colour a cut-and-sew product needs: Printful refuses a task
	 * without it (a real answer, 2026-10-31).
	 *
	 * @param array<mixed> $product v2 product.
	 * @return list<array{name: string, value: string}>
	 */
	private static function stitch_option( array $product ): array {
		foreach ( is_array( $product['product_options'] ?? null ) ? $product['product_options'] : array() as $option ) {
			if ( is_array( $option ) && 'stitch_color' === ( $option['name'] ?? null ) ) {
				return array(
					array(
						'name'  => 'stitch_color',
						'value' => 'white',
					),
				);
			}
		}

		return array();
	}

	/**
	 * The sides with their flat pattern; a side without one is left out: it
	 * has nothing to design on.
	 *
	 * @param list<array{placement: string, name: string, width_mm: int, height_mm: int, dpi: int, width: int, height: int, technique: string, style: int}> $sides   Sides.
	 * @param array<mixed>                                                                                                                                  $rows    Mockup templates.
	 * @param int                                                                                                                                           $variant Variant.
	 * @return list<array{placement: string, name: string, width_mm: int, height_mm: int, dpi: int, width: int, height: int, technique: string, style: int, template: string, template_width: int, template_height: int, area: array{x: int, y: int, width: int, height: int}}>
	 */
	private static function with_templates( array $sides, array $rows, int $variant ): array {
		$found = array();

		foreach ( $rows as $row ) {
			$placement = is_array( $row ) && is_string( $row['placement'] ?? null ) ? $row['placement'] : '';
			$url       = is_array( $row ) && is_string( $row['image_url'] ?? null ) ? $row['image_url'] : '';
			$ids       = is_array( $row ) && is_array( $row['catalog_variant_ids'] ?? null ) ? array_map( 'intval', $row['catalog_variant_ids'] ) : array();

			if ( '' !== $placement && 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && 'template' === ( $row['role'] ?? '' ) && in_array( $variant, $ids, true ) ) {
				$found[ $placement ] ??= $row;
			}
		}

		$kept = array();

		foreach ( $sides as $side ) {
			$row    = $found[ $side['placement'] ] ?? null;
			$width  = is_array( $row ) ? (int) ( $row['template_width'] ?? 0 ) : 0;
			$height = is_array( $row ) ? (int) ( $row['template_height'] ?? 0 ) : 0;

			if ( ! is_array( $row ) || $width < 1 || $height < 1 ) {
				continue;
			}

			$kept[] = array_merge(
				$side,
				array(
					'template'        => (string) $row['image_url'],
					'template_width'  => $width,
					'template_height' => $height,
					'area'            => array(
						'x'      => (int) ( $row['print_area_left'] ?? 0 ),
						'y'      => (int) ( $row['print_area_top'] ?? 0 ),
						'width'  => (int) ( $row['print_area_width'] ?? 0 ),
						'height' => (int) ( $row['print_area_height'] ?? 0 ),
					),
				)
			);
		}

		return $kept;
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
