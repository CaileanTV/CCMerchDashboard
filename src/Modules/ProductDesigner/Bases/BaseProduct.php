<?php
/**
 * A base product of the product designer.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Bases;

use InvalidArgumentException;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulCatalog;

defined( 'ABSPATH' ) || exit;

/**
 * A product the administrator offers for designs (ADR-080 pkt. 2): source,
 * name, description, base price incl. VAT (1/10 000 units, in the store
 * currency), the default product category, colours, sizes and sides.
 * `version` grows with every change of a template or print area (from step
 * 30.11 not with a new price; the designer products follow a new price).
 * Creators design only on an `active` base product; a `retired` one is
 * out of the shop for good. The most expensive used design area is included
 * in the base price; every further used area adds `extra_side_units`, or
 * `small_side_units` on a small side, to what the company keeps (the owner's
 * choices 2026-10-30 and 2026-11-03, DesignerPrices::secured_base()).
 */
final readonly class BaseProduct {

	public const DRAFT = 'draft';

	public const ACTIVE = 'active';

	public const RETIRED = 'retired';

	public const SOURCES = array( 'own', 'printful', 'gelato', 'prodigi', 'printify' );

	/**
	 * Most colours, sizes and sides: a poster has 29 sizes in two directions,
	 * in 16 groups of print sizes (ADR-115).
	 */
	public const MAX_COLORS = 60;

	public const MAX_SIZES = 60;

	public const MAX_VIEWS = 24;

	/**
	 * The longest size name, e.g. `50x70 cm / 20x28"` (ADR-115).
	 */
	public const MAX_SIZE_LENGTH = 60;

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<array{key: string, name: string, hex: string}> $colors
	 * @phpstan-param list<string> $sizes
	 * @phpstan-param list<BaseView> $views
	 *
	 * @param int|null           $id          Id; null before insert.
	 * @param string             $status      DRAFT, ACTIVE or RETIRED.
	 * @param string             $source      One of SOURCES.
	 * @param string             $name        Name.
	 * @param string             $description Description (plain text).
	 * @param int                $base_units  Base price incl. VAT in 1/10 000 units.
	 * @param string             $currency    ISO 4217 code.
	 * @param int|null           $category_id Default `product_cat` term.
	 * @param array[]            $colors      Colours (key, name, #rrggbb).
	 * @param string[]           $sizes       Sizes.
	 * @param BaseView[]         $views       Sides.
	 * @param int                $version     Version.
	 * @param int                $extra_side_units Price incl. VAT of each further used large side.
	 * @param string             $note             What creators read in the designer; may be empty.
	 * @param array<string, int> $size_prices The price of a size that has its own; empty with one price for all.
	 * @param int                $small_side_units Price incl. VAT of each further used small side.
	 * @param bool               $background_color Whether the designer offers a background colour (ADR-095).
	 * @param int[]              $tag_ids          The `product_tag` terms its designer products get (ADR-100).
	 * @throws InvalidArgumentException When a value is invalid.
	 */
	public function __construct(
		public ?int $id,
		public string $status,
		public string $source,
		public string $name,
		public string $description,
		public int $base_units,
		public string $currency,
		public ?int $category_id,
		public array $colors,
		public array $sizes,
		public array $views,
		public int $version = 1,
		public int $extra_side_units = 0,
		public string $note = '',
		public array $size_prices = array(),
		public int $small_side_units = 0,
		public bool $background_color = false,
		public array $tag_ids = array()
	) {
		if ( ( null !== $id && $id < 1 ) || ! in_array( $status, array( self::DRAFT, self::ACTIVE, self::RETIRED ), true ) || ! in_array( $source, self::SOURCES, true ) || $version < 1 || $extra_side_units < 0 || $small_side_units < 0 ) {
			throw new InvalidArgumentException( 'Invalid base product.' );
		}

		if ( '' === $name || mb_strlen( $name ) > 191 || $base_units < 1 || 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) || ( null !== $category_id && $category_id < 1 ) ) {
			throw new InvalidArgumentException( 'Invalid base product.' );
		}

		foreach ( $tag_ids as $tag_id ) {
			if ( $tag_id < 1 ) {
				throw new InvalidArgumentException( 'Invalid product tag.' );
			}
		}

		foreach ( $size_prices as $size => $units ) {
			if ( ! in_array( (string) $size, $sizes, true ) || $units < 1 ) {
				throw new InvalidArgumentException( 'Invalid price of a size.' );
			}
		}

		if ( count( $colors ) > self::MAX_COLORS || count( $sizes ) > self::MAX_SIZES || count( $views ) > self::MAX_VIEWS ) {
			throw new InvalidArgumentException( 'Too many colours, sizes or sides.' );
		}

		$keys = array_column( $colors, 'key' );

		foreach ( $colors as $color ) {
			if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,31}$/', $color['key'] ) || '' === $color['name'] || mb_strlen( $color['name'] ) > 60 || 1 !== preg_match( '/^#[0-9a-f]{6}$/', $color['hex'] ) ) {
				throw new InvalidArgumentException( 'Invalid colour.' );
			}
		}

		foreach ( $sizes as $size ) {
			if ( '' === $size || mb_strlen( $size ) > self::MAX_SIZE_LENGTH ) {
				throw new InvalidArgumentException( 'Invalid size.' );
			}
		}

		$view_keys = array_map( static fn( BaseView $view ): string => $view->key, $views );

		if ( count( $keys ) !== count( array_unique( $keys ) ) || count( $sizes ) !== count( array_unique( $sizes ) ) || count( $view_keys ) !== count( array_unique( $view_keys ) ) ) {
			throw new InvalidArgumentException( 'Colours, sizes and sides must be unique.' );
		}

		if ( self::ACTIVE === $status && ( array() === $colors || array() === $sizes || array() === $views ) ) {
			throw new InvalidArgumentException( 'An active base product needs colours, sizes and sides.' );
		}
	}

	/**
	 * A size name as stored: one line without commas (they separate sizes),
	 * runs of spaces as one, at most MAX_SIZE_LENGTH characters. Names are
	 * compared in this form, so `8x12"` and `8x12 "` stay apart but
	 * `A3  (30x40)` and `A3 (30x40)` do not (ADR-115).
	 *
	 * @param string $name The name as given.
	 */
	public static function size_name( string $name ): string {
		return trim( mb_substr( trim( sanitize_text_field( str_replace( ',', ' ', $name ) ) ), 0, self::MAX_SIZE_LENGTH ) );
	}

	/**
	 * The asset ids of every template layer.
	 *
	 * @return list<int>
	 */
	public function layer_assets(): array {
		$ids = array();

		foreach ( $this->views as $view ) {
			foreach ( $view->layers as $asset ) {
				$ids[] = $asset;
			}
		}

		return $ids;
	}

	/**
	 * The photos under the guides of its sides (ADR-122): creators may see
	 * them like the sides' layers, but they belong to the provider's
	 * thumbnails, so they are no layers to forget with a side.
	 *
	 * @return list<int>
	 */
	public function guide_figures(): array {
		$ids = array();

		foreach ( $this->views as $view ) {
			foreach ( $view->guides as $guide ) {
				if ( $guide['figure'] > 0 ) {
					$ids[] = $guide['figure'];
				}
			}
		}

		return $ids;
	}

	/**
	 * The base price of a size; without one, the base product's.
	 *
	 * @param string|null $size The size.
	 */
	public function price_of( ?string $size ): int {
		return null === $size ? $this->base_units : ( $this->size_prices[ $size ] ?? $this->base_units );
	}

	/**
	 * Whether a side belongs to only some of the sizes or colours (values of
	 * the first axis, ADR-115).
	 */
	public function sized(): bool {
		return array() !== array_filter( $this->views, static fn( BaseView $view ): bool => array() !== $view->sizes || array() !== $view->colors );
	}

	/**
	 * Whether a side belongs to only some of the colours (ADR-115).
	 */
	public function colored(): bool {
		return array() !== array_filter( $this->views, static fn( BaseView $view ): bool => array() !== $view->colors );
	}

	/**
	 * The sides of a size, and of a colour when one is named, in the base
	 * product's order.
	 *
	 * @param string      $size  The size.
	 * @param string|null $color The colour key; null for any.
	 * @return list<string>
	 */
	public function sides_of( string $size, ?string $color = null ): array {
		return array_values( array_map( static fn( BaseView $view ): string => $view->key, array_filter( $this->views, static fn( BaseView $view ): bool => $view->on_size( $size ) && ( null === $color || $view->on_color( $color ) ) ) ) );
	}

	/**
	 * Every chosen size with every chosen colour; the colour is null when no
	 * side belongs to only some colours.
	 *
	 * @param string[]|null $sizes  The chosen sizes; null for all of them.
	 * @param string[]|null $colors The chosen colour keys; null for all of them.
	 * @return list<array{0: string, 1: string|null}>
	 */
	private function combinations( ?array $sizes, ?array $colors ): array {
		$keys  = $this->colored() ? ( $colors ?? array_column( $this->colors, 'key' ) ) : array( null );
		$pairs = array();

		foreach ( $sizes ?? $this->sizes as $size ) {
			foreach ( $keys as $color ) {
				$pairs[] = array( (string) $size, null === $color ? null : (string) $color );
			}
		}

		return $pairs;
	}

	/**
	 * How many used design areas one product has: with sides per size or
	 * colour, those of the combination with the most, so the company never
	 * gets less than it set.
	 *
	 * @param string[]      $used   The used sides.
	 * @param string[]|null $sizes  The chosen sizes; null for all of them.
	 * @param string[]|null $colors The chosen colour keys; null for all of them.
	 */
	public function areas( array $used, ?array $sizes = null, ?array $colors = null ): int {
		if ( ! $this->sized() ) {
			return count( $used );
		}

		$most = 0;

		foreach ( $this->combinations( $sizes, $colors ) as [ $size, $color ] ) {
			$most = max( $most, count( array_intersect( $used, $this->sides_of( $size, $color ) ) ) );
		}

		return $most;
	}

	/**
	 * Everything a design's price depends on: when it changes, the shop gets a new price.
	 *
	 * @return array<string, mixed>
	 */
	public function price_terms(): array {
		return array(
			'base'  => $this->base_units,
			'sizes' => $this->size_prices,
			'large' => $this->extra_side_units,
			'small' => $this->small_side_units,
			'sides' => array_map( static fn( BaseView $view ): bool => $view->small, $this->views ),
		);
	}

	/**
	 * The price of a side as an extra design area: a small side's or a large one's.
	 *
	 * @param BaseView $view Side.
	 */
	public function extra_of( BaseView $view ): int {
		return $view->small ? $this->small_side_units : $this->extra_side_units;
	}

	/**
	 * What the extra design areas of one product cost: the most expensive used
	 * area is included in the base price and every other one adds its price.
	 * With sides per size, the size that costs most, so the company never gets
	 * less than it set (the owner's choice 2026-11-03).
	 *
	 * @param string[]      $used   The used sides.
	 * @param string[]|null $sizes  The chosen sizes; null for all of them.
	 * @param string[]|null $colors The chosen colour keys; null for all of them.
	 */
	public function extra_amount( array $used, ?array $sizes = null, ?array $colors = null ): int {
		$views = array();
		$most  = 0;

		foreach ( $this->views as $view ) {
			$views[ $view->key ] = $view;
		}

		foreach ( $this->sized() ? $this->combinations( $sizes, $colors ) : array( array( null, null ) ) as [ $size, $color ] ) {
			$prices = array();

			foreach ( array_unique( $used ) as $key ) {
				$view = $views[ $key ] ?? null;

				// Without sides per size, a side the base product no longer has counts as a large area, as before.
				if ( null === $size || ( null !== $view && $view->on_size( $size ) && ( null === $color || $view->on_color( $color ) ) ) ) {
					$prices[] = null === $view ? $this->extra_side_units : $this->extra_of( $view );
				}
			}

			$most = max( $most, array() === $prices ? 0 : array_sum( $prices ) - max( $prices ) );
		}

		return $most;
	}

	/**
	 * Whether a design fits the chosen sizes and colours: with sides per size
	 * or colour each chosen combination has a design, and no design lies on
	 * one that is not chosen (ADR-115: each orientation has its own design).
	 *
	 * @param string[]      $used   The used sides.
	 * @param string[]      $sizes  The chosen sizes.
	 * @param string[]|null $colors The chosen colour keys; null for all of them.
	 */
	public function covers( array $used, array $sizes, ?array $colors = null ): bool {
		if ( ! $this->sized() ) {
			return true;
		}

		$sold = array();

		foreach ( $this->combinations( $sizes, $colors ) as [ $size, $color ] ) {
			$own = array_intersect( $used, $this->sides_of( $size, $color ) );

			if ( array() === $own ) {
				return false;
			}

			$sold = array_merge( $sold, $own );
		}

		return array() === array_diff( $used, $sold );
	}

	/**
	 * Whether the browser's images of a side show stitches and stay the
	 * product's images (ADR-085). Printful stitches its own mockups.
	 *
	 * @param BaseView $view The side.
	 */
	public function stitched( BaseView $view ): bool {
		return '' !== $view->thread_pack && PrintfulCatalog::SOURCE !== $this->source;
	}
}
