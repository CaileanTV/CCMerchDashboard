<?php
/**
 * Printify's catalog for base products.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use InvalidArgumentException;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-087: the administrator browses Printify's whole catalog with small
 * pictures, by our own groups of the product names, words and, if wanted,
 * only the products of producers in the EU, then picks a product and one of
 * its producers.
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
	 * Products on a page of the catalog.
	 */
	public const PER_PAGE = 48;

	/**
	 * The side of a small picture.
	 */
	public const THUMB = 240;

	/**
	 * Groups of the products by words in their names: the catalog has no
	 * categories. The first that fits wins; a product without one is "other".
	 */
	public const GROUPS = array(
		'hoodies'     => '/hood/i',
		'sweatshirts' => '/sweat|crewneck|fleece/i',
		'tshirts'     => '/\bt-?shirt|\btee\b|tank|jersey|v-neck/i',
		'clothing'    => '/shirt|polo|jacket|pants|jogger|shorts|legging|dress|skirt|sock|bodysuit|onesie|romper|bikini|swim|brief|boxer|pajama|sneaker|shoe|slipper|sandal|flip flop|\btop\b/i',
		'headwear'    => '/\bcap\b|hat\b|beanie|bucket|visor/i',
		'drinkware'   => '/mug|tumbler|\bcup\b|glass|bottle|flask|stein|can cooler|can holder|mason jar/i',
		'bags'        => '/\bbag|tote|backpack|pouch|duffel|fanny|handbag|sack/i',
		'wall'        => '/poster|canvas|\bprint\b|frame|tapestry|wall|metal sign|acrylic|photo tile|gallery board/i',
		'tech'        => '/phone|\bcase\b|mouse|desk mat|laptop|airpods|tablet|charger|\bpad\b|speaker|earbuds/i',
		'home'        => '/pillow|blanket|towel|rug|\bmat\b|mats\b|curtain|coaster|candle|ornament|clock|puzzle|throw|duvet|sheet|shower|apron|comforter|stocking|cutting board|oven|lunch|tablecloth|storage|food/i',
		'paper'       => '/sticker|decal|card|notebook|journal|calendar|magnet|postcard|paper|stationery|bookmark|note/i',
		'accessories' => '/necklace|bracelet|earring|key|pin\b|button|lanyard|wallet|watch|scarf|mask|patch|necktie|tie\b|mirror|jewelry|luggage/i',
		'pets'        => '/\bpet\b|\bdog\b|\bcat\b|collar|leash|bandana/i',
	);

	/**
	 * The products of a producer (transient prefix).
	 */
	public const PRODUCER_PRODUCTS = 'merchboard_printify_producer_';

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
	 * @param PrintifyApi        $api   Printify.
	 * @param DesignerFiles|null $files Where small pictures are kept.
	 */
	public function __construct( private PrintifyApi $api, private ?DesignerFiles $files = null ) {
	}

	/**
	 * Whether Printify may be used.
	 */
	public function available(): bool {
		return $this->api->available();
	}

	/**
	 * A page of the catalog: the products with all the words in their name or
	 * brand, in a group, and only those of one producer or of producers in the
	 * EU when asked.
	 *
	 * @param string $query    Words; '' for all.
	 * @param string $group    A key of GROUPS, 'other' or '' for all.
	 * @param bool   $eu_only  Only products a producer in the EU makes.
	 * @param int    $page     Page, from 1.
	 * @param int    $producer Only this producer's products; 0 for all.
	 * @return array{products: list<array{id: int, title: string, brand: string}>, total: int, page: int, pages: int}
	 * @throws PrintifyException When Printify refuses or fails.
	 */
	public function browse( string $query, string $group = '', bool $eu_only = false, int $page = 1, int $producer = 0 ): array {
		$words = array_filter( explode( ' ', mb_strtolower( trim( $query ) ) ), static fn( string $word ): bool => '' !== $word );
		$eu    = $eu_only ? array_flip( $this->eu_products() ) : array();
		$made  = $producer > 0 ? array_flip( $this->producer_products( $producer ) ) : array();
		$found = array();

		foreach ( $this->blueprints() as $row ) {
			$text = mb_strtolower( $row['title'] . ' ' . $row['brand'] );

			if ( ( '' === $group || self::group_of( $row['title'] ) === $group ) && ( ! $eu_only || isset( $eu[ $row['id'] ] ) ) && ( 0 === $producer || isset( $made[ $row['id'] ] ) ) && array() === array_filter( $words, static fn( string $word ): bool => ! str_contains( $text, $word ) ) ) {
				$found[] = array(
					'id'    => $row['id'],
					'title' => $row['title'],
					'brand' => $row['brand'],
				);
			}
		}

		$pages = max( 1, (int) ceil( count( $found ) / self::PER_PAGE ) );
		$page  = min( max( 1, $page ), $pages );

		return array(
			'products' => array_slice( $found, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ),
			'total'    => count( $found ),
			'page'     => $page,
			'pages'    => $pages,
		);
	}

	/**
	 * Every producer by name, the ones in the EU first.
	 *
	 * @param bool $eu_only Only producers in the EU.
	 * @return list<array{id: int, title: string, country: string, city: string, eu: bool}>
	 * @throws PrintifyException When Printify refuses or fails.
	 */
	public function all_producers( bool $eu_only = false ): array {
		$producers = array();

		foreach ( $this->places() as $id => $place ) {
			$eu = in_array( $place['country'], self::EU, true );

			if ( '' !== $place['title'] && ( $eu || ! $eu_only ) ) {
				$producers[] = array_merge( array( 'id' => $id ), $place, array( 'eu' => $eu ) );
			}
		}

		usort( $producers, static fn( array $a, array $b ): int => array( $b['eu'], $a['title'] ) <=> array( $a['eu'], $b['title'] ) );

		return $producers;
	}

	/**
	 * The group of a product by its name: a key of GROUPS or 'other'.
	 *
	 * @param string $title Name.
	 */
	public static function group_of( string $title ): string {
		foreach ( self::GROUPS as $key => $pattern ) {
			if ( 1 === preg_match( $pattern, $title ) ) {
				return $key;
			}
		}

		return 'other';
	}

	/**
	 * A product's small picture as a JPEG on white, made once and kept.
	 *
	 * @param int $product Product.
	 * @return string JPEG bytes.
	 * @throws PrintifyException When Printify refuses or fails, or the product has no picture.
	 * @throws DesignerException When it cannot be kept.
	 * @throws InvalidArgumentException When the picture cannot be read.
	 */
	public function thumbnail( int $product ): string {
		$url = '';

		foreach ( $this->blueprints() as $row ) {
			if ( $product === $row['id'] ) {
				$url = $row['image'];
				break;
			}
		}

		if ( '' === $url ) {
			self::fail( PrintifyException::NOT_FOUND );
		}

		$files = $this->files ?? new DesignerFiles();
		$name  = md5( 'printify-thumb|' . self::THUMB . '|' . $url ) . '.jpg';
		$path  = $files->path( $name );
		$kept  = null === $path ? false : file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A small local file.

		if ( is_string( $kept ) && '' !== $kept ) {
			return $kept;
		}

		$jpeg = self::small( $this->api->image( $url ) );

		$files->write( $name, $jpeg );

		return $jpeg;
	}

	/**
	 * The producers of a product, the ones in the EU first.
	 *
	 * @param int  $blueprint Product.
	 * @param bool $eu_only   Only producers in the EU.
	 * @return list<array{id: int, title: string, country: string, city: string, eu: bool}>
	 * @throws PrintifyException When Printify refuses or fails.
	 */
	public function producers( int $blueprint, bool $eu_only = false ): array {
		$places    = $this->places();
		$producers = array();

		foreach ( $this->api->blueprint_providers( $blueprint ) as $row ) {
			$id = is_array( $row ) && is_numeric( $row['id'] ?? null ) ? (int) $row['id'] : 0;

			if ( $id > 0 ) {
				$place       = $places[ $id ] ?? array(
					'title'   => '',
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

		return $eu_only ? array_values( array_filter( $producers, static fn( array $producer ): bool => $producer['eu'] ) ) : $producers;
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
	 * @return list<array{id: int, title: string, brand: string, image: string}>
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
					'image' => self::image_of( $row ),
				);
			}
		}

		if ( ! $cached ) {
			set_transient( self::BLUEPRINTS, $products, 12 * HOUR_IN_SECONDS );
		}

		return $products;
	}

	/**
	 * A product's first picture from the list or the cache, or ''.
	 *
	 * @param array<mixed> $row Product.
	 */
	private static function image_of( array $row ): string {
		$image = is_string( $row['image'] ?? null ) ? $row['image'] : ( is_array( $row['images'] ?? null ) ? reset( $row['images'] ) : '' );

		return is_string( $image ) && 1 === preg_match( PrintifyApi::IMAGES, $image ) ? $image : '';
	}

	/**
	 * The products the producers in the EU make.
	 *
	 * @return list<int>
	 * @throws PrintifyException When Printify refuses or fails.
	 */
	private function eu_products(): array {
		$products = array();

		foreach ( $this->all_producers( true ) as $producer ) {
			$products = array_merge( $products, $this->producer_products( $producer['id'] ) );
		}

		return array_values( array_unique( $products ) );
	}

	/**
	 * The products a producer makes, cached like the list.
	 *
	 * @param int $producer Producer.
	 * @return list<int>
	 * @throws PrintifyException When Printify refuses or fails.
	 */
	private function producer_products( int $producer ): array {
		$cached = get_transient( self::PRODUCER_PRODUCTS . $producer );

		if ( is_array( $cached ) ) {
			return array_values( array_filter( array_map( 'intval', $cached ) ) );
		}

		$products = array();
		$provider = $this->api->print_provider( $producer );

		foreach ( is_array( $provider['blueprints'] ?? null ) ? $provider['blueprints'] : array() as $row ) {
			if ( is_array( $row ) && is_numeric( $row['id'] ?? null ) ) {
				$products[ (int) $row['id'] ] = true;
			}
		}

		$products = array_keys( $products );

		set_transient( self::PRODUCER_PRODUCTS . $producer, $products, 12 * HOUR_IN_SECONDS );

		return $products;
	}

	/**
	 * A picture as a small JPEG on white.
	 *
	 * @param string $bytes Picture.
	 * @throws InvalidArgumentException When it cannot be read or written.
	 */
	private static function small( string $bytes ): string {
		$image = imagecreatefromstring( $bytes );
		$thumb = imagecreatetruecolor( self::THUMB, self::THUMB );

		if ( false === $image || false === $thumb ) {
			throw new InvalidArgumentException( 'The picture cannot be read.' );
		}

		$width  = imagesx( $image );
		$height = imagesy( $image );
		$scale  = self::THUMB / max( 1, $width, $height );
		$white  = imagecolorallocate( $thumb, 255, 255, 255 );

		imagefilledrectangle( $thumb, 0, 0, self::THUMB, self::THUMB, false === $white ? 0 : $white );
		imagecopyresampled( $thumb, $image, (int) ( ( self::THUMB - $width * $scale ) / 2 ), (int) ( ( self::THUMB - $height * $scale ) / 2 ), 0, 0, max( 1, (int) round( $width * $scale ) ), max( 1, (int) round( $height * $scale ) ), $width, $height );

		ob_start();
		$written = imagejpeg( $thumb, null, 80 );
		$jpeg    = (string) ob_get_clean();

		if ( ! $written || '' === $jpeg ) {
			throw new InvalidArgumentException( 'The picture cannot be written.' );
		}

		return $jpeg;
	}

	/**
	 * Where the producers are, by producer.
	 *
	 * @return array<int, array{title: string, country: string, city: string}>
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
					'title'   => mb_substr( sanitize_text_field( is_string( $row['title'] ?? null ) ? $row['title'] : '' ), 0, 100 ),
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
