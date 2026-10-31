<?php
/**
 * Prodigi's catalogue for base products.
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
 * ADR-084: the administrator searches Prodigi's catalogue and picks a
 * product. Only products made in the EU are offered: the list has no such
 * filter, so each hit's details are read (cached for an hour). The catalogue
 * has no photos and no colour codes, so a base product from Prodigi is flat:
 * a white sheet per print area, colours the administrator can correct
 * afterwards, and the mockups come from Prodigi after a submission. A print
 * size can depend on the size, so the sizes are offered in groups, one group
 * per base product. Colours are attributes of an SKU, so one SKU can stand
 * for several colours.
 *
 * Written from Prodigi's OpenAPI file (2026-10-31) and not yet run against
 * Prodigi. Nothing is rendered on import: render() is never called.
 */
class ProdigiCatalog implements CatalogRenderer {

	public const SOURCE = 'prodigi';

	/**
	 * The manufacturing region offered.
	 */
	public const REGION = 'EU';

	/**
	 * Most search results shown.
	 */
	public const MAX_RESULTS = 48;

	/**
	 * Most sides offered.
	 */
	private const MAX_SIDES = 6;

	/**
	 * The print resolution when Prodigi names none.
	 */
	private const DPI = 300;

	private const POLL_DELAY = 30;

	/**
	 * The cached search answers (transient prefix) and categories.
	 */
	private const CACHE = 'merchboard_prodigi_search_';

	public const CATEGORIES = 'merchboard_prodigi_categories';

	/**
	 * Constructor.
	 *
	 * @param ProdigiApi $api Prodigi.
	 */
	public function __construct( private ProdigiApi $api ) {
	}

	/**
	 * Whether Prodigi may be used.
	 */
	public function available(): bool {
		return $this->api->available();
	}

	/**
	 * Catalogue products made in the EU, by words or in a category.
	 *
	 * @param string $query    Words.
	 * @param string $category Category; '' for all.
	 * @return list<array{id: string, title: string, category: string}>
	 * @throws ProdigiException When Prodigi refuses or fails.
	 */
	public function search( string $query, string $category = '' ): array {
		$key    = self::CACHE . md5( $query . '|' . $category );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return self::cached_products( $cached );
		}

		$rows = '' === trim( $query ) ? ( '' === $category ? array() : $this->api->products( $category ) ) : $this->api->search( $query );

		$found = array();

		foreach ( $rows as $row ) {
			$id = is_array( $row ) && is_string( $row['id'] ?? null ) ? $row['id'] : '';

			if ( '' === $id || count( $found ) >= self::MAX_RESULTS || ! in_array( self::REGION, $this->regions( $this->api->product( $id ) ), true ) ) {
				continue;
			}

			$found[] = array(
				'id'       => $id,
				'title'    => mb_substr( sanitize_text_field( (string) ( $row['name'] ?? $id ) ), 0, 191 ),
				'category' => sanitize_text_field( (string) ( $row['category'] ?? '' ) ),
			);
		}

		set_transient( $key, $found, HOUR_IN_SECONDS );

		return $found;
	}

	/**
	 * The catalogue's categories, by name.
	 *
	 * @return list<array{id: string, name: string}>
	 * @throws ProdigiException When Prodigi refuses or fails.
	 */
	public function categories(): array {
		$cached = get_transient( self::CATEGORIES );

		if ( is_array( $cached ) ) {
			return self::cached_categories( $cached );
		}

		$categories = array();

		foreach ( $this->api->categories() as $row ) {
			if ( is_array( $row ) && is_string( $row['id'] ?? null ) && is_string( $row['name'] ?? null ) ) {
				$categories[] = array(
					'id'   => $row['id'],
					'name' => mb_substr( sanitize_text_field( $row['name'] ), 0, 100 ),
				);
			}
		}

		usort( $categories, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );
		set_transient( self::CATEGORIES, $categories, HOUR_IN_SECONDS );

		return $categories;
	}

	/**
	 * What the administrator can pick from a product: its SKUs made in the
	 * EU in the group of sizes.
	 *
	 * @param string $ref   Catalogue product.
	 * @param int    $group The group of sizes with the same print sizes.
	 * @return array{name: string, description: string, image: string, colors: list<array{name: string, hex: string}>, sizes: list<string>, sides: list<array{placement: string, name: string, width_mm: int, height_mm: int, dpi: int, width: int, height: int}>, variants: array<string, array<string, string>>, flat: bool, groups: list<string>, group: int, options: list<array{name: string, value: string}>}
	 * @throws ProdigiException When Prodigi refuses or fails, or the product cannot be a base product.
	 */
	public function choice( string $ref, int $group = 0 ): array {
		$product = $this->api->product( $ref );
		$rows    = array();

		foreach ( $this->api->variants( $ref ) as $row ) {
			if ( is_array( $row ) && in_array( self::REGION, $this->regions( $row ), true ) ) {
				$rows[ (string) wp_json_encode( self::areas( $row ) ) ][] = $row;
			}
		}

		if ( array() === $rows ) {
			self::fail( ProdigiException::NOT_IN_EU );
		}

		$groups = array_values( $rows );
		$group  = isset( $groups[ $group ] ) ? $group : 0;
		$sides  = self::sides( $groups[ $group ][0] );

		[ $colors, $sizes, $variants ] = self::variant_map( $groups[ $group ] );

		if ( array() === $colors || array() === $sizes || array() === $sides ) {
			self::fail( ProdigiException::INVALID );
		}

		return array(
			'name'        => mb_substr( trim( sanitize_text_field( (string) ( $product['name'] ?? $ref ) ) ), 0, 191 ),
			'description' => PrintfulCatalog::description( (string) ( $product['description'] ?? '' ) ),
			'image'       => '',
			'colors'      => $colors,
			'sizes'       => $sizes,
			'sides'       => $sides,
			'variants'    => $variants,
			'flat'        => true,
			'groups'      => 1 < count( $groups ) ? array_map( static fn( array $skus ): string => implode( ', ', self::variant_map( $skus )[1] ), $groups ) : array(),
			'group'       => $group,
			'options'     => array(),
		);
	}

	/**
	 * Nothing is rendered: a Prodigi base product is flat.
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
	 * @throws ProdigiException Always.
	 */
	public function render( string $kind, array $import ): string {
		unset( $kind, $import );

		self::fail( ProdigiException::INVALID );
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
	 * A file Prodigi returned.
	 *
	 * @param string $url URL.
	 * @throws ProdigiException When it cannot be downloaded.
	 */
	public function download( string $url ): string {
		return $this->api->download( $url );
	}

	/**
	 * Seconds between two looks at a pending render.
	 */
	public function poll_delay(): int {
		return self::POLL_DELAY;
	}

	/**
	 * Prodigi's answers about a product.
	 *
	 * @param string $ref Catalogue product.
	 * @return array<string, mixed>
	 * @throws ProdigiException When Prodigi refuses or fails.
	 */
	public function diagnose( string $ref ): array {
		return array(
			'GET /catalogue/products/' . $ref => $this->api->product( $ref ),
			'GET /catalogue/products/' . $ref . '/variants' => $this->api->variants( $ref ),
		);
	}

	/**
	 * The cached search, read like Prodigi's answer.
	 *
	 * @param array<mixed> $cached Cached value.
	 * @return list<array{id: string, title: string, category: string}>
	 */
	private static function cached_products( array $cached ): array {
		$products = array();

		foreach ( $cached as $row ) {
			if ( is_array( $row ) && is_string( $row['id'] ?? null ) && is_string( $row['title'] ?? null ) ) {
				$products[] = array(
					'id'       => $row['id'],
					'title'    => $row['title'],
					'category' => is_string( $row['category'] ?? null ) ? $row['category'] : '',
				);
			}
		}

		return $products;
	}

	/**
	 * The cached categories, read like Prodigi's answer.
	 *
	 * @param array<mixed> $cached Cached value.
	 * @return list<array{id: string, name: string}>
	 */
	private static function cached_categories( array $cached ): array {
		$categories = array();

		foreach ( $cached as $row ) {
			if ( is_array( $row ) && is_string( $row['id'] ?? null ) && is_string( $row['name'] ?? null ) ) {
				$categories[] = array(
					'id'   => $row['id'],
					'name' => $row['name'],
				);
			}
		}

		return $categories;
	}

	/**
	 * The manufacturing regions of a product or SKU.
	 *
	 * @param array<mixed> $row Product or SKU.
	 * @return list<mixed>
	 */
	private function regions( array $row ): array {
		return is_array( $row['manufacturingRegions'] ?? null ) ? array_values( $row['manufacturingRegions'] ) : array();
	}

	/**
	 * An SKU's print areas with a known size.
	 *
	 * @param array<mixed> $variant SKU.
	 * @return list<array{0: string, 1: float, 2: float, 3: int}> Name, width and height in mm, dpi.
	 */
	private static function areas( array $variant ): array {
		$areas = array();

		foreach ( is_array( $variant['printAreas'] ?? null ) ? $variant['printAreas'] : array() as $area ) {
			if ( is_array( $area ) && is_string( $area['name'] ?? null ) && is_numeric( $area['widthMm'] ?? null ) && is_numeric( $area['heightMm'] ?? null ) ) {
				$areas[] = array( $area['name'], (float) $area['widthMm'], (float) $area['heightMm'], (int) ( $area['recommendedDpi'] ?? self::DPI ) );
			}
		}

		return $areas;
	}

	/**
	 * The sides of an SKU: each print area, at the highest resolution up to
	 * the recommended one that fits the designer's largest image.
	 *
	 * @param array<mixed> $variant SKU.
	 * @return list<array{placement: string, name: string, width_mm: int, height_mm: int, dpi: int, width: int, height: int}>
	 */
	private static function sides( array $variant ): array {
		$sides = array();

		foreach ( array_slice( self::areas( $variant ), 0, self::MAX_SIDES ) as [ $placement, $width_mm, $height_mm, $wanted ] ) {
			$longest = max( $width_mm, $height_mm );
			$dpi     = $longest > 0 ? max( BaseView::MIN_DPI, min( BaseView::MAX_DPI, $wanted, (int) floor( DesignerFiles::MAX_PIXELS * 25.4 / $longest ) ) ) : BaseView::MIN_DPI;
			$mm_w    = (int) round( $width_mm );
			$mm_h    = (int) round( $height_mm );
			$name    = mb_substr( trim( sanitize_text_field( ucfirst( str_replace( array( '_', '-' ), ' ', $placement ) ) ) ), 0, 60 );

			if ( '' === $name || $mm_w < 1 || $mm_h < 1 || max( $mm_w, $mm_h ) > BaseView::MAX_MM ) {
				continue;
			}

			$sides[] = array(
				'placement' => $placement,
				'name'      => $name,
				'width_mm'  => $mm_w,
				'height_mm' => $mm_h,
				'dpi'       => $dpi,
				'width'     => (int) round( $mm_w * $dpi / 25.4 ),
				'height'    => (int) round( $mm_h * $dpi / 25.4 ),
			);
		}

		return $sides;
	}

	/**
	 * Colours, sizes and the SKU of each colour and size. Prodigi's catalogue
	 * has no colour codes: every colour starts white.
	 *
	 * @param array<array<mixed>> $rows SKUs.
	 * @return array{0: list<array{name: string, hex: string}>, 1: list<string>, 2: array<string, array<string, string>>}
	 */
	private static function variant_map( array $rows ): array {
		$colors   = array();
		$sizes    = array();
		$variants = array();

		foreach ( $rows as $row ) {
			$sku  = is_string( $row['id'] ?? null ) ? $row['id'] : ( is_string( $row['sku'] ?? null ) ? $row['sku'] : '' );
			$size = self::size_of( $row );

			if ( '' === $sku || '' === $size ) {
				continue;
			}

			if ( ! in_array( $size, $sizes, true ) && count( $sizes ) < BaseProduct::MAX_SIZES ) {
				$sizes[] = $size;
			}

			foreach ( self::colors_of( $row ) as $color ) {
				if ( ! isset( $colors[ $color ] ) && count( $colors ) < BaseProduct::MAX_COLORS ) {
					$colors[ $color ] = array(
						'name' => $color,
						'hex'  => '#ffffff',
					);
				}

				$variants[ $color ][ $size ] ??= $sku;
			}
		}

		return array( array_values( $colors ), $sizes, $variants );
	}

	/**
	 * An SKU's size: its label, its attribute, or its own id.
	 *
	 * @param array<mixed> $row SKU.
	 */
	private static function size_of( array $row ): string {
		$label = is_array( $row['sizeLabel'] ?? null ) ? ( $row['sizeLabel']['metric'] ?? $row['sizeLabel']['imperial'] ?? null ) : null;
		$value = is_string( $label ) ? $label : ( self::attribute( $row, '/^size$/i' ) ?? ( is_string( $row['id'] ?? null ) ? $row['id'] : '' ) );

		return mb_substr( trim( sanitize_text_field( str_replace( ',', ' ', $value ) ) ), 0, 20 );
	}

	/**
	 * The colours an SKU comes in: its options, its one value, or "Standard".
	 *
	 * @param array<mixed> $row SKU.
	 * @return list<string>
	 */
	private static function colors_of( array $row ): array {
		foreach ( is_array( $row['attributeOptions'] ?? null ) ? $row['attributeOptions'] : array() as $name => $values ) {
			if ( 1 === preg_match( '/^colou?r$/i', (string) $name ) && is_array( $values ) ) {
				$list = array_filter( array_map( static fn( mixed $value ): string => mb_substr( trim( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) ), 0, 60 ), $values ), static fn( string $value ): bool => '' !== $value );

				if ( array() !== $list ) {
					return array_values( $list );
				}
			}
		}

		$one = self::attribute( $row, '/^colou?r$/i' );

		return array( null === $one ? GelatoCatalog::STANDARD_COLOR : mb_substr( trim( sanitize_text_field( $one ) ), 0, 60 ) );
	}

	/**
	 * An attribute's value, or null.
	 *
	 * @param array<mixed> $row  SKU.
	 * @param string       $name Pattern of the attribute's name.
	 */
	private static function attribute( array $row, string $name ): ?string {
		foreach ( is_array( $row['attributes'] ?? null ) ? $row['attributes'] : array() as $key => $value ) {
			if ( 1 === preg_match( $name, (string) $key ) && is_scalar( $value ) && '' !== (string) $value ) {
				return (string) $value;
			}
		}

		return null;
	}

	/**
	 * Fails with a reason.
	 *
	 * @param string $reason ProdigiException reason.
	 * @throws ProdigiException Always.
	 */
	private static function fail( string $reason ): never {
		throw new ProdigiException( $reason );
	}
}
