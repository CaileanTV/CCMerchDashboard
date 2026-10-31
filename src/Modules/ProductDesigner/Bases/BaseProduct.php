<?php
/**
 * A base product of the product designer.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Bases;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * A product the administrator offers for designs (ADR-080 pkt. 2): source,
 * name, description, base price incl. VAT (1/10 000 units, in the store
 * currency), the default product category, colours, sizes and sides.
 * `version` grows with every change of a template or print area (from step
 * 30.11 not with a new price; the designer products follow a new price).
 * Creators design only on an `active` base product; a `retired` one is
 * out of the shop for good. One design area is included in the base price;
 * every further used area adds `extra_side_units` to what the company keeps
 * (the owner's choice 2026-10-30, DesignerPrices::secured_base()).
 */
final readonly class BaseProduct {

	public const DRAFT = 'draft';

	public const ACTIVE = 'active';

	public const RETIRED = 'retired';

	public const SOURCES = array( 'own', 'printful', 'gelato', 'prodigi' );

	/**
	 * Most colours, sizes and sides.
	 */
	public const MAX_COLORS = 60;

	public const MAX_SIZES = 30;

	public const MAX_VIEWS = 8;

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<array{key: string, name: string, hex: string}> $colors
	 * @phpstan-param list<string> $sizes
	 * @phpstan-param list<BaseView> $views
	 *
	 * @param int|null   $id          Id; null before insert.
	 * @param string     $status      DRAFT, ACTIVE or RETIRED.
	 * @param string     $source      One of SOURCES.
	 * @param string     $name        Name.
	 * @param string     $description Description (plain text).
	 * @param int        $base_units  Base price incl. VAT in 1/10 000 units.
	 * @param string     $currency    ISO 4217 code.
	 * @param int|null   $category_id Default `product_cat` term.
	 * @param array[]    $colors      Colours (key, name, #rrggbb).
	 * @param string[]   $sizes       Sizes.
	 * @param BaseView[] $views       Sides.
	 * @param int        $version     Version.
	 * @param int        $extra_side_units Price incl. VAT of each used side after the first.
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
		public int $extra_side_units = 0
	) {
		if ( ( null !== $id && $id < 1 ) || ! in_array( $status, array( self::DRAFT, self::ACTIVE, self::RETIRED ), true ) || ! in_array( $source, self::SOURCES, true ) || $version < 1 || $extra_side_units < 0 ) {
			throw new InvalidArgumentException( 'Invalid base product.' );
		}

		if ( '' === $name || mb_strlen( $name ) > 191 || $base_units < 1 || 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) || ( null !== $category_id && $category_id < 1 ) ) {
			throw new InvalidArgumentException( 'Invalid base product.' );
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
			if ( '' === $size || mb_strlen( $size ) > 20 ) {
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
}
