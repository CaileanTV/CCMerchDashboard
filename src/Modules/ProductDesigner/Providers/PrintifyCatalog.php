<?php
/**
 * Printify's catalog for base products.
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
 * ADR-087: the administrator searches Printify's whole catalog, picks a
 * product and one of its producers, with no limit on where the producer is.
 * Only the catalog is used: the import brings in the name, colours, sizes and
 * print areas, and each side starts as a white sheet of the size of the
 * company's own photos. The administrator adds those and draws the print area
 * on them. Designs get the mockups drawn in the browser, as on an own product,
 * and print files with transparency: a side is not `flat`.
 *
 * Print sizes follow the size of the garment. A base product holds one print
 * size per side, so a side gets the smallest one, which fits every size.
 * Printify does not name a resolution: 300 dpi is assumed. The catalog has no
 * colour codes: colours start white, and the photos give them theirs. Nothing
 * is rendered on import: render() is never called.
 */
class PrintifyCatalog implements CatalogRenderer {

	public const SOURCE = 'printify';

	/**
	 * Most search results shown.
	 */
	public const MAX_RESULTS = 48;

	/**
	 * The template of a side: the size of the company's photos.
	 */
	public const TEMPLATE = 1000;

	/**
	 * The share of the template the first print area takes, until it is drawn.
	 */
	private const AREA = 800;

	/**
	 * Most sides offered.
	 */
	private const MAX_SIDES = 6;

	/**
	 * The assumed print resolution.
	 */
	private const DPI = 300;

	private const POLL_DELAY = 30;

	/**
	 * The cached product list and producers, which change rarely.
	 */
	public const BLUEPRINTS = 'merchboard_printify_blueprints';

	public const PRODUCERS = 'merchboard_printify_producers';

	/**
	 * The member states: a producer here is listed first.
	 */
	private const EU = array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE' );

	/**
	 * Constructor.
	 *
	 * @param PrintifyApi $api Printify.
	 */
	public function __construct( private PrintifyApi $api ) {
	}

	/**
	 * Whether Printify may be used.
	 */
	public function available(): bool {
		return $this->api->available();
	}

	/**
	 * Products of the catalog with all the words in their name or brand.
	 *
	 * @param string $query Words.
	 * @return list<array{id: int, title: string, brand: string}>
	 * @throws PrintifyException When Printify refuses or fails.
	 */
	public function search( string $query ): array {
		$words = array_filter( explode( ' ', mb_strtolower( trim( $query ) ) ), static fn( string $word ): bool => '' !== $word );
		$found = array();

		if ( array() === $words ) {
			return array();
		}

		foreach ( $this->blueprints() as $row ) {
			$text = mb_strtolower( $row['title'] . ' ' . $row['brand'] );

			if ( array() === array_filter( $words, static fn( string $word ): bool => ! str_contains( $text, $word ) ) ) {
				$found[] = $row;
			}

			if ( count( $found ) >= self::MAX_RESULTS ) {
				break;
			}
		}

		return $found;
	}

	/**
	 * The producers of a product, the ones in the EU first.
	 *
	 * @param int $blueprint Product.
	 * @return list<array{id: int, title: string, country: string, city: string, eu: bool}>
	 * @throws PrintifyException When Printify refuses or fails.
	 */
	public function producers( int $blueprint ): array {
		$places    = $this->places();
		$producers = array();

		foreach ( $this->api->blueprint_providers( $blueprint ) as $row ) {
			$id = is_array( $row ) && is_numeric( $row['id'] ?? null ) ? (int) $row['id'] : 0;

			if ( $id > 0 ) {
				$place       = $places[ $id ] ?? array(
					'country' => '',
					'city'    => '',
				);
				$producers[] = array(
					'id'      => $id,
					'title'   => mb_substr( sanitize_text_field( (string) ( $row['title'] ?? $id ) ), 0, 100 ),
					'country' => $place['country'],
					'city'    => $place['city'],
					'eu'      => in_array( $place['country'], self::EU, true ),
				);
			}
		}

		usort( $producers, static fn( array $a, array $b ): int => array( $b['eu'], $a['title'] ) <=> array( $a['eu'], $b['title'] ) );

		return $producers;
	}

	/**
	 * What the administrator can pick from a producer's product. The reference
	 * is `<product>-<producer>`. Nothing depends on the group: see the class.
	 *
	 * @param string $ref   Product and producer.
	 * @param int    $group Not used.
	 * @return array{name: string, description: string, image: string, colors: list<array{name: string, hex: string}>, sizes: list<string>, sides: list<array{placement: string, name: string, width_mm: int, height_mm: int, dpi: int, width: int, height: int, technique: string, template_width: int, template_height: int, area: array{x: int, y: int, width: int, height: int}}>, variants: array<string, array<string, string>>, flat: bool, printed: bool}
	 * @throws PrintifyException When Printify refuses or fails, or the product cannot be a base product.
	 */
	public function choice( string $ref, int $group = 0 ): array {
		unset( $group );

		if ( 1 !== preg_match( '/^(\d{1,9})-(\d{1,9})$/', $ref, $match ) ) {
			self::fail( PrintifyException::INVALID );
		}

		$blueprint = $this->api->blueprint( (int) $match[1] );
		$rows      = array_filter( $this->api->variants( (int) $match[1], (int) $match[2] ), 'is_array' );
		$sides     = self::sides( $rows );

		[ $colors, $sizes, $variants ] = self::variant_map( $rows );

		if ( array() === $colors || array() === $sizes || array() === $sides ) {
			self::fail( PrintifyException::INVALID );
		}

		$image = is_array( $blueprint['images'] ?? null ) ? reset( $blueprint['images'] ) : '';

		return array(
			'name'        => mb_substr( trim( sanitize_text_field( (string) ( $blueprint['title'] ?? $ref ) ) ), 0, 191 ),
			'description' => PrintfulCatalog::description( (string) ( $blueprint['description'] ?? '' ) ),
			'image'       => is_string( $image ) ? $image : '',
			'colors'      => $colors,
			'sizes'       => $sizes,
			'sides'       => $sides,
			'variants'    => $variants,
			'flat'        => true,
			'printed'     => true,
		);
	}

	/**
	 * Nothing is rendered: the sides are white sheets.
	 *
	 * @param array<string, mixed> $import Import data.
	 * @return list<string>
	 */
	public function render_kinds( array $import ): array {
		unset( $import );

		return array();
	}

	/**
	 * Never called: see render_kinds().
	 *
	 * @param string               $kind   Render.
	 * @param array<string, mixed> $import Import data.
	 * @throws PrintifyException Always.
	 */
	public function render( string $kind, array $import ): string {
		unset( $kind, $import );

		self::fail( PrintifyException::INVALID );
	}

	/**
	 * Never called: see render_kinds().
	 *
	 * @param string               $key    Render.
	 * @param array<string, mixed> $import Import data.
	 * @return array{status: string, error?: string, note?: string, images?: array<int|string, array<string, string>>}
	 */
	public function check( string $key, array $import ): array {
		unset( $key, $import );

		return array(
			'status' => 'failed',
			'error'  => 'no render',
		);
	}

	/**
	 * Never called: see render_kinds().
	 *
	 * @param string $key Render.
	 * @return array<string, mixed>
	 */
	public function render_answer( string $key ): array {
		unset( $key );

		return array();
	}

	/**
	 * Nothing is downloaded: Printify's images are not used.
	 *
	 * @param string $url URL.
	 * @throws PrintifyException Always.
	 */
	public function download( string $url ): string {
		unset( $url );

		self::fail( PrintifyException::INVALID );
	}

	/**
	 * Seconds between two looks at a pending render.
	 */
	public function poll_delay(): int {
		return self::POLL_DELAY;
	}

	/**
	 * Printify's answers about a product.
	 *
	 * @param string $ref Product and producer.
	 * @return array<string, mixed>
	 * @throws PrintifyException When Printify refuses or fails.
	 */
	public function diagnose( string $ref ): array {
		if ( 1 !== preg_match( '/^(\d{1,9})-(\d{1,9})$/', $ref, $match ) ) {
			self::fail( PrintifyException::INVALID );
		}

		return array(
			'GET /catalog/blueprints/' . $match[1] . '.json' => $this->api->blueprint( (int) $match[1] ),
			'GET /catalog/blueprints/' . $match[1] . '/print_providers/' . $match[2] . '/variants.json' => $this->api->variants( (int) $match[1], (int) $match[2] ),
		);
	}

	/**
	 * The product list with what a search needs: the whole answer is too large to keep.
	 *
	 * @return list<array{id: int, title: string, brand: string}>
	 * @throws PrintifyException When Printify refuses or fails.
	 */
	private function blueprints(): array {
		$rows   = get_transient( self::BLUEPRINTS );
		$cached = is_array( $rows );

		if ( ! $cached ) {
			$rows = $this->api->blueprints();
		}

		$products = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && is_numeric( $row['id'] ?? null ) && is_string( $row['title'] ?? null ) ) {
				$products[] = array(
					'id'    => (int) $row['id'],
					'title' => mb_substr( sanitize_text_field( $row['title'] ), 0, 191 ),
					'brand' => mb_substr( sanitize_text_field( is_string( $row['brand'] ?? null ) ? $row['brand'] : '' ), 0, 100 ),
				);
			}
		}

		if ( ! $cached ) {
			set_transient( self::BLUEPRINTS, $products, 12 * HOUR_IN_SECONDS );
		}

		return $products;
	}

	/**
	 * Where the producers are, by producer.
	 *
	 * @return array<int, array{country: string, city: string}>
	 * @throws PrintifyException When Printify refuses or fails.
	 */
	private function places(): array {
		$rows   = get_transient( self::PRODUCERS );
		$cached = is_array( $rows );

		if ( ! $cached ) {
			$rows = $this->api->print_providers();
		}

		$places = array();

		foreach ( $rows as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$place = is_array( $row['location'] ?? null ) ? $row['location'] : $row;
			$id    = $cached ? (int) $key : ( is_numeric( $row['id'] ?? null ) ? (int) $row['id'] : 0 );

			if ( $id > 0 ) {
				$places[ $id ] = array(
					'country' => sanitize_text_field( is_string( $place['country'] ?? null ) ? $place['country'] : '' ),
					'city'    => sanitize_text_field( is_string( $place['city'] ?? null ) ? $place['city'] : '' ),
				);
			}
		}

		if ( ! $cached ) {
			set_transient( self::PRODUCERS, $places, 12 * HOUR_IN_SECONDS );
		}

		return $places;
	}

	/**
	 * The sides: each position with the smallest print size of the variants.
	 *
	 * @param array<array<mixed>> $rows Variants.
	 * @return list<array{placement: string, name: string, width_mm: int, height_mm: int, dpi: int, width: int, height: int, technique: string, template_width: int, template_height: int, area: array{x: int, y: int, width: int, height: int}}>
	 */
	private static function sides( array $rows ): array {
		$smallest = array();

		foreach ( $rows as $row ) {
			foreach ( is_array( $row['placeholders'] ?? null ) ? $row['placeholders'] : array() as $place ) {
				$position = is_array( $place ) && is_string( $place['position'] ?? null ) ? $place['position'] : '';
				$width    = is_array( $place ) && is_numeric( $place['width'] ?? null ) ? (int) $place['width'] : 0;
				$height   = is_array( $place ) && is_numeric( $place['height'] ?? null ) ? (int) $place['height'] : 0;

				if ( '' !== $position && $width > 0 && $height > 0 && ( ! isset( $smallest[ $position ] ) || $width * $height < $smallest[ $position ][0] * $smallest[ $position ][1] ) ) {
					$smallest[ $position ] = array( $width, $height, is_string( $place['decoration_method'] ?? null ) ? $place['decoration_method'] : '' );
				}
			}
		}

		$sides = array();

		foreach ( array_slice( $smallest, 0, self::MAX_SIDES, true ) as $position => [ $width, $height, $technique ] ) {
			$mm_w    = (int) round( $width * 25.4 / self::DPI );
			$mm_h    = (int) round( $height * 25.4 / self::DPI );
			$longest = max( $mm_w, $mm_h );
			$dpi     = $longest > 0 ? max( BaseView::MIN_DPI, min( self::DPI, (int) floor( DesignerFiles::MAX_PIXELS * 25.4 / $longest ) ) ) : BaseView::MIN_DPI;
			$name    = mb_substr( trim( sanitize_text_field( ucfirst( str_replace( array( '_', '-' ), ' ', (string) $position ) ) ) ), 0, 60 );

			if ( '' === $name || $mm_w < 1 || $mm_h < 1 || $longest > BaseView::MAX_MM ) {
				continue;
			}

			$sides[] = array(
				'placement'       => (string) $position,
				'name'            => $name,
				'width_mm'        => $mm_w,
				'height_mm'       => $mm_h,
				'dpi'             => $dpi,
				'width'           => (int) round( $mm_w * $dpi / 25.4 ),
				'height'          => (int) round( $mm_h * $dpi / 25.4 ),
				'technique'       => sanitize_key( $technique ),
				'template_width'  => self::TEMPLATE,
				'template_height' => self::TEMPLATE,
				'area'            => ImageProbe::fit(
					array(
						'x'      => intdiv( self::TEMPLATE - self::AREA, 2 ),
						'y'      => intdiv( self::TEMPLATE - self::AREA, 2 ),
						'width'  => self::AREA,
						'height' => self::AREA,
					),
					$mm_w,
					$mm_h
				),
			);
		}

		return $sides;
	}

	/**
	 * Colours, sizes and the variant of each. A product without colours can
	 * have other options, e.g. a paper, which take the colour's place.
	 *
	 * @param array<array<mixed>> $rows Variants.
	 * @return array{0: list<array{name: string, hex: string}>, 1: list<string>, 2: array<string, array<string, string>>}
	 */
	private static function variant_map( array $rows ): array {
		$colors   = array();
		$sizes    = array();
		$variants = array();

		foreach ( $rows as $row ) {
			$id      = is_scalar( $row['id'] ?? null ) ? (string) $row['id'] : '';
			$options = is_array( $row['options'] ?? null ) ? $row['options'] : array();
			$size    = mb_substr( trim( sanitize_text_field( str_replace( ',', ' ', is_string( $options['size'] ?? null ) ? $options['size'] : GelatoCatalog::ONE_SIZE ) ) ), 0, 20 );
			$others  = array_filter( array_diff_key( $options, array( 'size' => true ) ), 'is_string' );
			$color   = mb_substr( trim( sanitize_text_field( is_string( $options['color'] ?? null ) ? $options['color'] : implode( ' ', $others ) ) ), 0, 60 );
			$color   = '' === $color ? GelatoCatalog::STANDARD_COLOR : $color;

			if ( '' === $id || '' === $size ) {
				continue;
			}

			if ( ! in_array( $size, $sizes, true ) && count( $sizes ) < BaseProduct::MAX_SIZES ) {
				$sizes[] = $size;
			}

			if ( ! isset( $colors[ $color ] ) && count( $colors ) < BaseProduct::MAX_COLORS ) {
				$colors[ $color ] = array(
					'name' => $color,
					'hex'  => '#ffffff',
				);
			}

			$variants[ $color ][ $size ] ??= $id;
		}

		return array( array_values( $colors ), $sizes, $variants );
	}

	/**
	 * Fails with a reason.
	 *
	 * @param string $reason PrintifyException reason.
	 * @throws PrintifyException Always.
	 */
	private static function fail( string $reason ): never {
		throw new PrintifyException( $reason );
	}
}
