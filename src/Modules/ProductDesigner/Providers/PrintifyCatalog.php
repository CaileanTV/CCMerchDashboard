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
use MerchBoard\Modules\WooCommerce\JobQueue;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-087: the administrator browses Printify's whole catalog with small
 * pictures, by our own groups of the product names, words and, if wanted,
 * only the products of producers in the EU, then picks a product and one of
 * its producers.
 * The import brings in the name, colours, sizes and print areas.
 *
 * ADR-122: with a shop chosen, the import renders the product as Gelato's
 * does: a temporary product in that shop with a marker colour per side in the
 * first colour, and one with the transparent file in every colour. Their
 * mockups are keyed by Printify's camera name, which is the same on both
 * products and in every colour (its id is not on every product); ImageProbe measures each side's print area, and the colour
 * codes are measured on the photos. forget() deletes the products when the
 * import is done, has failed or starts over; they are never published.
 *
 * Without a shop, or for a product whose sizes have print areas of other
 * shapes (one marker render shows one size), each side starts as a white
 * sheet of the size of the company's own photos, as before (ADR-087): the
 * administrator adds those and draws the print area on them. Either way,
 * designs get the mockups drawn in the browser and print files with
 * transparency: a side is not `flat`.
 *
 * Print sizes follow the size of the garment. A base product holds one print
 * size per side, so a side gets the smallest one, which fits every size.
 * Printify does not name a resolution: 300 dpi is assumed.
 */
class PrintifyCatalog implements CatalogRenderer, CatalogCleanup {

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
	 * The background job that reads the producers' products a page did not
	 * have time for.
	 */
	public const HOOK = 'merchboard_designer_printify_producers';

	/**
	 * Seconds the job may spend before it queues itself again.
	 */
	private const JOB_BUDGET = 40;

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
	 * The side of the marker and transparent files uploaded (ADR-122).
	 */
	private const MARKER_SIDE = 1000;

	/**
	 * The width of the striped sheet uploaded (ADR-122).
	 */
	private const STRIPES_WIDTH = 1200;

	/**
	 * Most photos of a render compared (each needs two downloads): the ones
	 * of a placement first, then the others in Printify's order.
	 */
	private const MAX_SLOTS = 16;

	/**
	 * The price of a temporary product's variants in cents: never sold.
	 */
	private const PRICE = 10000;

	/**
	 * Printify's camera of a size chart: no photo of the product.
	 */
	private const SIZE_CHART = 'size-chart';

	/**
	 * Temporary products that could not be deleted yet (`<shop>:<product>`),
	 * tried again by the next import's clean-up (ADR-122).
	 */
	public const LEFTOVERS = 'merchboard_designer_printify_leftovers';

	/**
	 * Most leftovers kept.
	 */
	private const MAX_LEFTOVERS = 50;

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
	 * @param PrintifyApi          $api     Printify.
	 * @param DesignerFiles|null   $files   Where small pictures are kept.
	 * @param JobQueue|null        $queue   The background job of the producers.
	 * @param float                $budget  Seconds a page may spend reading producers it does not know yet.
	 * @param PrintifyAccount|null $account The shop for the import's photos (ADR-122).
	 */
	public function __construct( private PrintifyApi $api, private ?DesignerFiles $files = null, private ?JobQueue $queue = null, private float $budget = 3.0, private ?PrintifyAccount $account = null ) {
	}

	/**
	 * The shop for the import's temporary products, or ''.
	 */
	private function shop(): string {
		return null !== $this->account ? $this->account->shop() : '';
	}

	/**
	 * The background job: reads the producers no page had time for, and
	 * queues itself again until all are read. A refusal ends it; the next
	 * page queues it again.
	 */
	public function read_producers(): void {
		try {
			[ , $complete ] = $this->makers( self::JOB_BUDGET );
		} catch ( PrintifyException $e ) {
			if ( PrintifyException::RATE_LIMITED === $e->reason() ) {
				$this->queue?->enqueue( self::HOOK, array(), max( 60, $e->retry_after ) );
			}

			return;
		}

		if ( ! $complete ) {
			$this->queue?->enqueue( self::HOOK, array(), 0 );
		}
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
	 * @return array{products: list<array{id: int, title: string, brand: string, producers: list<array{id: int, title: string, country: string, eu: bool}>}>, total: int, page: int, pages: int, complete: bool}
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
		$shown = array_slice( $found, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

		[ $makers, $complete ] = $this->makers( $this->budget );

		// The rest are read in the background, so the page need not wait.
		if ( ! $complete && null !== $this->queue && ! $this->queue->has_any( self::HOOK ) ) {
			$this->queue->enqueue( self::HOOK, array(), 0 );
		}

		foreach ( $shown as $index => $row ) {
			$shown[ $index ]['producers'] = $makers[ $row['id'] ] ?? array();
		}

		return array(
			'products' => $shown,
			'total'    => count( $found ),
			'page'     => $page,
			'pages'    => $pages,
			'complete' => $complete,
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
		$rows      = array_values( array_filter( $this->api->variants( (int) $match[1], (int) $match[2] ), 'is_array' ) );
		$groups    = self::shape_groups( $rows );
		$sides     = array();

		// Sizes whose print areas have another shape (a poster upright and lying) get their own sides (the owner's choice 2026-11-02).
		foreach ( $groups as $members ) {
			$found = self::sides( $members );

			if ( 1 < count( $groups ) ) {
				$own   = self::variant_map( $members )[1];
				$found = array_map( static fn( array $side ): array => array_merge( $side, array( 'name' => mb_substr( $side['name'] . ' ' . implode( ', ', $own ), 0, 60 ), 'sizes' => $own ) ), $found ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}

			$sides = array_merge( $sides, $found );
		}

		$sides = array_slice( $sides, 0, self::MAX_SIDES );

		[ $colors, $sizes, $variants ] = self::variant_map( $rows );

		if ( array() === $colors || array() === $sizes || array() === $sides ) {
			self::fail( PrintifyException::INVALID );
		}

		$image = is_array( $blueprint['images'] ?? null ) ? reset( $blueprint['images'] ) : '';

		// ADR-122: photos from a temporary product; one render shows one size, so one shape only.
		if ( '' !== $this->shop() && 1 === count( $groups ) ) {
			return array(
				'name'        => mb_substr( trim( sanitize_text_field( (string) ( $blueprint['title'] ?? $ref ) ) ), 0, 191 ),
				'description' => PrintfulCatalog::description( (string) ( $blueprint['description'] ?? '' ) ),
				'image'       => is_string( $image ) ? $image : '',
				'colors'      => $colors,
				'sizes'       => $sizes,
				'sides'       => $sides,
				'variants'    => $variants,
				'flat'        => false,
				'printed'     => false,
			);
		}

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
	 * The marker render, and once it is done the transparent one; nothing for
	 * white sheets.
	 *
	 * @param array<string, mixed> $import Import data.
	 * @return list<string>
	 */
	public function render_kinds( array $import ): array {
		if ( true === ( $import['printed'] ?? false ) || true === ( $import['flat'] ?? false ) ) {
			return array();
		}

		// ADR-122: the striped sheet shows where each view of a print around the product faces.
		return 'done' === ( $import['renders']['marker']['status'] ?? null ) ? array( 'marker', 'blank', 'stripes' ) : array( 'marker' );
	}

	/**
	 * The colour the striped sheet is rendered in (ADR-122): a white or other
	 * light one when the product has it, else the first.
	 *
	 * @param array<int|string, mixed> $colors Variant by colour key.
	 */
	public static function stripes_color( array $colors ): string {
		foreach ( array_keys( $colors ) as $key ) {
			if ( 1 === preg_match( '/white|natural|clear|frost|cream|ivory|silver/', (string) $key ) ) {
				return (string) $key;
			}
		}

		return (string) array_key_first( $colors );
	}

	/**
	 * Creates a temporary product (ADR-122): `marker` in the first colour with
	 * a marker colour per side, `blank` in every colour with the transparent
	 * file, `stripes` in the first colour with the striped sheet of each side
	 * (ImageProbe::stripes(), in the print area's proportions). A marker or
	 * the transparent file is scaled to cover its print area.
	 *
	 * @param string               $kind   `marker`, `blank` or `stripes`.
	 * @param array<string, mixed> $import Import data.
	 * @return string `<shop>:<product>`.
	 * @throws PrintifyException When Printify refuses or fails, or no shop is chosen.
	 */
	public function render( string $kind, array $import ): string {
		$shop = $this->shop();

		if ( '' === $shop ) {
			self::fail( PrintifyException::NOT_CONFIGURED );
		}

		[ $blueprint, $producer ] = self::ref_of( is_string( $import['ref'] ?? null ) ? $import['ref'] : '' );

		$variants = array_values( array_map( 'intval', array_filter( is_array( $import['colors'] ?? null ) ? $import['colors'] : array(), 'is_numeric' ) ) );
		// The stripes in a light colour: on a dark one they hardly show, and a stripe of the product's own colour not at all.
		$variants = match ( $kind ) {
			'blank'   => $variants,
			'stripes' => array( (int) ( ( is_array( $import['colors'] ?? null ) ? $import['colors'] : array() )[ self::stripes_color( is_array( $import['colors'] ?? null ) ? $import['colors'] : array() ) ] ?? 0 ) ),
			default   => array_slice( $variants, 0, 1 ),
		};
		$variants = array_values( array_filter( $variants ) );
		$sides    = array_values( array_filter( is_array( $import['sides'] ?? null ) ? $import['sides'] : array(), 'is_array' ) );
		$places   = array();

		if ( array() === $variants || array() === $sides ) {
			self::fail( PrintifyException::INVALID );
		}

		foreach ( $sides as $index => $side ) {
			$marker = 'marker' === $kind ? ( ImageProbe::MARKERS[ $index ] ?? null ) : null;

			if ( 'marker' === $kind && null === $marker ) {
				break;
			}

			$width   = max( 1, (int) ( $side['width'] ?? 1 ) );
			$height  = max( 1, (int) ( $side['height'] ?? 1 ) );
			$striped = 'stripes' === $kind;
			$upload  = $striped
				? $this->api->upload( 'merch-stripes-' . ( $index + 1 ) . '.png', ImageProbe::stripes( self::STRIPES_WIDTH, max( 1, (int) round( self::STRIPES_WIDTH * $height / $width ) ) ) )
				: $this->api->upload( null === $marker ? 'merch-clear.png' : 'merch-marker-' . ( $index + 1 ) . '.png', self::solid( $marker ) );

			$places[] = array(
				'position' => (string) ( $side['placement'] ?? '' ),
				'images'   => array(
					array(
						'id'    => $upload,
						'x'     => 0.5,
						'y'     => 0.5,
						// A square file covers the area: scale is relative to its width. The stripes have the area's proportions.
						'scale' => $striped ? 1 : round( max( 1, $height / $width ), 4 ),
						'angle' => 0,
					),
				),
			);
		}

		$product = $this->api->create_product(
			$shop,
			array(
				'title'             => 'Merch Dashboard import (temporary)',
				'description'       => 'Made by an import and deleted when it is done. Never published.',
				'blueprint_id'      => $blueprint,
				'print_provider_id' => $producer,
				'variants'          => array_map(
					static fn( int $variant ): array => array(
						'id'         => $variant,
						'price'      => self::PRICE,
						'is_enabled' => true,
					),
					$variants
				),
				'print_areas'       => array(
					array(
						'variant_ids'  => $variants,
						'placeholders' => $places,
					),
				),
			)
		);

		return $shop . ':' . (string) $product['id'];
	}

	/**
	 * A render's photos by Printify's camera name (slot_of()) and colour key.
	 * The ones of a placement come first, then the others, at most MAX_SLOTS
	 * names; a size chart is left out. Pending until Printify lists photos.
	 *
	 * @param string               $key    `<shop>:<product>`.
	 * @param array<string, mixed> $import Import data.
	 * @return array{status: string, error?: string, note?: string, images?: array<int|string, array<string, string>>}
	 * @throws PrintifyException When Printify refuses or fails.
	 */
	public function check( string $key, array $import ): array {
		[ $shop, $id ] = self::key_of( $key );

		$colors = array();

		foreach ( is_array( $import['colors'] ?? null ) ? $import['colors'] : array() as $color => $variant ) {
			$colors[ (string) $variant ] = (string) $color;
		}

		$images = array_slice( self::photos( $this->api->product( $shop, $id ), $colors ), 0, self::MAX_SLOTS, true );

		if ( array() === $images ) {
			return array(
				'status' => 'pending',
				'note'   => 'no photos yet',
			);
		}

		return array(
			'status' => 'done',
			'images' => $images,
			'note'   => count( $images ) . ' photos',
		);
	}

	/**
	 * A product's photos by camera name (slot_of()) and colour key: the ones
	 * of a placement first, then the others; a size chart is left out
	 * (ADR-122). Only photos on Printify's image hosts.
	 *
	 * @param array<mixed>          $product Printify's product.
	 * @param array<string, string> $colors  Colour key by variant id.
	 * @return array<string, array<string, string>>
	 */
	public static function photos( array $product, array $colors ): array {
		$placed = array();
		$others = array();

		foreach ( is_array( $product['images'] ?? null ) ? $product['images'] : array() as $image ) {
			$src = is_array( $image ) && is_string( $image['src'] ?? null ) ? $image['src'] : '';

			if ( 1 !== preg_match( PrintifyApi::IMAGES, $src ) || 1 !== preg_match( '#/mockup/[0-9a-f]{24}/(\d{1,12})/(\d{1,12})/#', $src, $match ) ) {
				continue;
			}

			$slot = self::slot_of( $src, $match[2] );

			if ( self::SIZE_CHART === $slot ) {
				continue;
			}

			// The variant in the URL; else the image's own list (Printify could name another variant of the colour).
			$color = $colors[ $match[1] ] ?? null;

			foreach ( null === $color && is_array( $image['variant_ids'] ?? null ) ? $image['variant_ids'] : array() as $variant ) {
				$color ??= is_scalar( $variant ) ? ( $colors[ (string) $variant ] ?? null ) : null;
			}

			if ( null === $color ) {
				continue;
			}

			if ( 'other' === ( $image['position'] ?? 'other' ) ) {
				$others[ $slot ][ $color ] ??= $src;
			} else {
				$placed[ $slot ][ $color ] ??= $src;
			}
		}

		return $placed + $others;
	}

	/**
	 * A photo's slot: Printify's camera name (`camera_label`, e.g. `front`
	 * or `context-1`), the same in every colour and on both products. The
	 * camera's id is not: a water bottle has another id per colour. Without a
	 * name, the id.
	 *
	 * @param string $src    The photo's URL.
	 * @param string $camera The camera's id from its path.
	 */
	private static function slot_of( string $src, string $camera ): string {
		$args = array();

		parse_str( (string) wp_parse_url( $src, PHP_URL_QUERY ), $args );

		$label = is_string( $args['camera_label'] ?? null ) ? substr( sanitize_key( $args['camera_label'] ), 0, 40 ) : '';

		return '' !== $label ? $label : 'camera-' . $camera;
	}

	/**
	 * Printify's answer about a render's product, without the photos' URLs.
	 *
	 * @param string $key `<shop>:<product>`.
	 * @return array<string, mixed>
	 * @throws PrintifyException When Printify refuses or fails.
	 */
	public function render_answer( string $key ): array {
		[ $shop, $id ] = self::key_of( $key );

		$product = $this->api->product( $shop, $id );

		return array(
			'id'      => $product['id'] ?? '',
			'title'   => $product['title'] ?? '',
			'visible' => $product['visible'] ?? null,
			'images'  => is_array( $product['images'] ?? null ) ? count( $product['images'] ) : 0,
		);
	}

	/**
	 * A photo of a render, from Printify's image hosts only.
	 *
	 * @param string $url URL.
	 * @return string Bytes.
	 * @throws PrintifyException When it cannot be fetched.
	 */
	public function download( string $url ): string {
		return $this->api->image( $url );
	}

	/**
	 * Deletes the import's temporary products (ADR-122). The uploads are kept:
	 * Printify gives the same few files the same id each time.
	 *
	 * @param array<string, mixed> $import Import data.
	 * @return list<string> The renders' keys that could not be deleted.
	 */
	public function forget( array $import ): array {
		$keys = array();

		foreach ( is_array( $import['renders'] ?? null ) ? $import['renders'] : array() as $render ) {
			$key = is_array( $render ) && is_string( $render['key'] ?? null ) ? $render['key'] : '';

			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}

		// What an earlier import could not delete (a 429, Printify down) is tried again with it.
		$stored = get_option( self::LEFTOVERS, array() );
		$keys   = array_values( array_unique( array_merge( is_array( $stored ) ? array_filter( $stored, 'is_string' ) : array(), $keys ) ) );
		$left   = array();

		foreach ( $keys as $key ) {
			try {
				[ $shop, $id ] = self::key_of( $key );

				$this->api->delete_product( $shop, $id );
			} catch ( PrintifyException ) {
				$left[] = $key;
			}
		}

		$left = array_slice( $left, -self::MAX_LEFTOVERS );

		if ( array() === $left ) {
			delete_option( self::LEFTOVERS );
		} else {
			update_option( self::LEFTOVERS, $left, false );
		}

		return $left;
	}

	/**
	 * A square PNG of one marker colour, or transparent; the same bytes each
	 * time, so Printify keeps one upload of each.
	 *
	 * @param array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}|null $rgb Marker colour, or null.
	 * @throws PrintifyException When GD cannot make it.
	 */
	private static function solid( ?array $rgb ): string {
		$image = imagecreatetruecolor( self::MARKER_SIDE, self::MARKER_SIDE );

		if ( false === $image ) {
			self::fail( PrintifyException::FAILED );
		}

		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		$color = null === $rgb ? imagecolorallocatealpha( $image, 0, 0, 0, 127 ) : imagecolorallocatealpha( $image, $rgb[0], $rgb[1], $rgb[2], 0 );

		imagefilledrectangle( $image, 0, 0, self::MARKER_SIDE - 1, self::MARKER_SIDE - 1, false === $color ? 0 : $color );
		ob_start();
		imagepng( $image, null, 9 );

		return (string) ob_get_clean();
	}

	/**
	 * The catalog product and producer of a reference.
	 *
	 * @param string $ref `<product>-<producer>`.
	 * @return array{0: int, 1: int}
	 * @throws PrintifyException When it is not one.
	 */
	private static function ref_of( string $ref ): array {
		if ( 1 !== preg_match( '/^(\d{1,9})-(\d{1,9})$/', $ref, $match ) ) {
			self::fail( PrintifyException::INVALID );
		}

		return array( (int) $match[1], (int) $match[2] );
	}

	/**
	 * The shop and product of a render's key.
	 *
	 * @param string $key `<shop>:<product>`.
	 * @return array{0: string, 1: string}
	 * @throws PrintifyException When it is not one.
	 */
	private static function key_of( string $key ): array {
		if ( 1 !== preg_match( '/^(\d{1,12}):([0-9a-f]{24})$/', $key, $match ) ) {
			self::fail( PrintifyException::INVALID );
		}

		return array( $match[1], $match[2] );
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
		$rows = get_transient( self::BLUEPRINTS );
		// A list kept by an earlier version has no pictures: it is read again.
		$cached = is_array( $rows ) && ( array() === $rows || ( is_array( reset( $rows ) ) && array_key_exists( 'image', reset( $rows ) ) ) );

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
	 * The producers of every product, the ones in the EU first. A producer
	 * not read yet is read while the budget lasts; the others come later,
	 * which `complete` tells.
	 *
	 * @param float $budget Seconds for producers not read yet.
	 * @return array{0: array<int, list<array{id: int, title: string, country: string, eu: bool}>>, 1: bool}
	 * @throws PrintifyException When Printify refuses or fails.
	 */
	private function makers( float $budget ): array {
		$started  = microtime( true );
		$complete = true;
		$makers   = array();

		foreach ( $this->all_producers() as $producer ) {
			if ( ! is_array( get_transient( self::PRODUCER_PRODUCTS . $producer['id'] ) ) && microtime( true ) - $started >= $budget ) {
				$complete = false;
				continue;
			}

			foreach ( $this->producer_products( $producer['id'] ) as $product ) {
				$makers[ $product ][] = array(
					'id'      => $producer['id'],
					'title'   => $producer['title'],
					'country' => $producer['country'],
					'eu'      => $producer['eu'],
				);
			}
		}

		return array( $makers, $complete );
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
	 * The variants by the shape of their print areas, rounded to 2 %: a
	 * garment's sizes share one, a poster upright and lying have two.
	 *
	 * @param list<array<mixed>> $rows Variants.
	 * @return list<list<array<mixed>>>
	 */
	private static function shape_groups( array $rows ): array {
		$groups = array();

		foreach ( $rows as $row ) {
			$shape = array();

			foreach ( is_array( $row['placeholders'] ?? null ) ? $row['placeholders'] : array() as $place ) {
				if ( is_array( $place ) && is_string( $place['position'] ?? null ) && is_numeric( $place['width'] ?? null ) && is_numeric( $place['height'] ?? null ) && (float) $place['height'] > 0 ) {
					$shape[ $place['position'] ] = round( (float) $place['width'] / (float) $place['height'] * 50 ) / 50;
				}
			}

			ksort( $shape );
			$groups[ (string) wp_json_encode( $shape ) ][] = $row;
		}

		return array_values( $groups );
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
