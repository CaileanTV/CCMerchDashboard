<?php
/**
 * Gelato's catalog and templates for base products.
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
 * ADR-082 pkt. 2 (step 30.13, catalog import from review 30.15): the
 * administrator names one template made in Gelato's dashboard, which is only
 * Gelato's render engine: its own design is always replaced by our files.
 * The colours and sizes come from each
 * template variant's `productUid` (the catalog attributes `gco_` and `gsi_`;
 * the variant's own options, "Standard" or "One size" when it has none),
 * the sides from the first variant's image placeholders with their print
 * size in millimetres. A store product has a `previewUrl` and
 * `productImages` (`fileUrl`, `productVariantIds`, seen in real answers
 * 2026-10-30): one image per mockup scene of the template, none for an
 * embroidered test product. The blank photos and print areas come from one
 * product per picked colour with a transparent file in each placeholder,
 * and one of the first colour with a marker per placeholder (CatalogImport).
 * Only a side an image shows gets a photo. The products
 * stay hidden in the API store (Gelato has no call to delete them).
 */
class GelatoCatalog implements CatalogRenderer {

	public const SOURCE = 'gelato';

	/**
	 * The colour of a variant without one.
	 */
	public const STANDARD_COLOR = 'Standard';

	/**
	 * The size of a variant without one.
	 */
	public const ONE_SIZE = 'One size';

	/**
	 * Most sides offered.
	 */
	private const MAX_SIDES = 6;

	/**
	 * The print resolution used when the print size allows it.
	 */
	private const DPI = 300;

	private const POLL_DELAY = 30;

	/**
	 * Looks at an active product before its preview is taken: the mockups
	 * are published in the background.
	 */
	private const PREVIEW_POLLS = 2;

	/**
	 * Looks at an active product without a preview before it is given up.
	 */
	private const ACTIVE_POLLS = 10;

	/**
	 * Seconds after which such a product is given up: the job can run far
	 * less often than every POLL_DELAY seconds.
	 */
	private const ACTIVE_WAIT = 600;

	/**
	 * Constructor.
	 *
	 * @param GelatoApi $api Gelato.
	 */
	public function __construct( private GelatoApi $api ) {
	}

	/**
	 * Whether Gelato may be used.
	 */
	public function available(): bool {
		return $this->api->available();
	}

	/**
	 * The largest difference in shape (width ÷ height) between the sizes of
	 * one format group (ADR-115 pkt. 2).
	 */
	public const SHAPE_TOLERANCE = 0.03;

	/**
	 * The smallest and largest bleed in mm (ADR-115 pkt. 8).
	 */
	public const MIN_BLEED = 0.5;

	public const MAX_BLEED = 15.0;

	/**
	 * What the administrator can pick from a template (ADR-115). The axes come
	 * from the variants' own options:
	 *
	 * - the size is the catalog's `gsi_`, else an option named Size, Sizes,
	 *   Format or Størrelse, else "One size";
	 * - the first axis is the colour (the catalog's `gco_`, else an option
	 *   named like Color, Colour or Farve) with every other option
	 *   (Orientation, Paper, Frame …), its values joined by " / " ("Standard"
	 *   without any).
	 *
	 * Two variants with the same values are counted in `duplicates`, never
	 * dropped without a word. The sizes are sorted (SizeOrder). With options
	 * besides the colour, or without a colour (`grouped`), every value of the first axis has its
	 * own sides: its sizes in format groups of one shape (SHAPE_TOLERANCE),
	 * each a side with the print size of its largest size, its own render
	 * (`group`, `variant`) and each size's own print size (`formats`).
	 * Otherwise the sides are the first variant's image placeholders, as before.
	 *
	 * @param string $ref   Template id.
	 * @param int    $group Not used: the format groups follow from the template.
	 * @return array{name: string, description: string, image: string, colors: list<array{name: string, hex: string}>, sizes: list<string>, sides: list<array<string, mixed>>, variants: array<string, array<string, string>>, uids: array<string, array<string, string>>, axes: array{color: string, size: string}, grouped: bool, duplicates: int}
	 * @throws GelatoException When Gelato refuses or fails, or the template cannot be a base product.
	 */
	public function choice( string $ref, int $group = 0 ): array {
		unset( $group );

		$template = $this->api->template( trim( $ref ) );
		$variants = self::variants( $template );

		[ $size_option, $color_option, $others ] = self::axes( $variants );

		$colors     = array();
		$sizes      = array();
		$map        = array();
		$uids       = array();
		$found      = array();
		$areas      = array();
		$duplicates = 0;
		$tinted     = null !== $color_option;

		foreach ( $variants as $variant ) {
			if ( ! is_scalar( $variant['id'] ?? null ) || '' === (string) $variant['id'] ) {
				continue;
			}

			$size    = self::size_of( $variant, $size_option );
			$color   = self::value_of( $variant, $color_option, $others );
			$tinted  = $tinted || null !== self::attribute( $variant, 'gco' );
			$full    = ! isset( $colors[ $color ] ) && count( $colors ) >= BaseProduct::MAX_COLORS;
			$too_big = ! in_array( $size, $sizes, true ) && count( $sizes ) >= BaseProduct::MAX_SIZES;

			if ( isset( $map[ $color ][ $size ] ) ) {
				++$duplicates;
				continue;
			}

			if ( $full || $too_big ) {
				continue;
			}

			// Gelato names no colour codes: CatalogImport measures them on the blank photos.
			$colors[ $color ] ??= array(
				'name' => $color,
				'hex'  => '#ffffff',
			);

			if ( ! in_array( $size, $sizes, true ) ) {
				$sizes[] = $size;
			}

			$map[ $color ][ $size ]   = (string) $variant['id'];
			$uids[ $color ][ $size ]  = is_string( $variant['productUid'] ?? null ) ? sanitize_text_field( $variant['productUid'] ) : '';
			$found[ $color ][ $size ] = $variant;
			$area                     = self::print_area( $variant );
			$areas[ $size ]           = min( $areas[ $size ] ?? $area, $area );
		}

		$sizes = SizeOrder::sort( $sizes, $areas );
		// Without a colour every size's print area can have its own shape, as with other options.
		$grouped = array() !== $others || ! $tinted;
		$first   = array() === $found ? null : reset( $found );
		$sides   = $grouped ? self::groups( $found, $sizes ) : ( is_array( $first ) ? self::sides( (array) reset( $first ) ) : array() );

		if ( array() === $colors || array() === $sides ) {
			self::fail( GelatoException::INVALID );
		}

		$labels = $tinted ? array( __( 'Colour', 'merchboard' ) ) : array();

		foreach ( $others as $other ) {
			$labels[] = self::option_label( $other );
		}

		return array(
			'name'        => mb_substr( trim( sanitize_text_field( (string) ( $template['title'] ?? $template['templateName'] ?? 'Product ' . $ref ) ) ), 0, 191 ), // The provider's name never reaches creators or customers.
			'description' => PrintfulCatalog::description( (string) ( $template['description'] ?? '' ) ),
			'image'       => is_string( $template['previewUrl'] ?? null ) ? $template['previewUrl'] : '',
			'colors'      => array_values( $colors ),
			'sizes'       => $sizes,
			'sides'       => $sides,
			'variants'    => $map,
			'uids'        => $uids,
			'axes'        => array(
				'color' => array() === $labels ? __( 'Colour', 'merchboard' ) : mb_substr( implode( ' / ', $labels ), 0, 60 ),
				'size'  => __( 'Size', 'merchboard' ),
			),
			'grouped'     => $grouped,
			'duplicates'  => $duplicates,
		);
	}

	/**
	 * The template's axes from its variants' options: the size option, the
	 * colour option and the others, each by its name as Gelato writes it.
	 *
	 * @param list<array<mixed>> $variants Variants.
	 * @return array{0: string|null, 1: string|null, 2: list<string>}
	 */
	public static function axes( array $variants ): array {
		$names = array();

		foreach ( $variants as $variant ) {
			foreach ( is_array( $variant['variantOptions'] ?? null ) ? $variant['variantOptions'] : array() as $option ) {
				$name = is_array( $option ) && is_string( $option['name'] ?? null ) ? trim( $option['name'] ) : '';

				if ( '' !== $name && ! in_array( $name, $names, true ) ) {
					$names[] = $name;
				}
			}
		}

		$size  = null;
		$color = null;

		foreach ( $names as $name ) {
			if ( null === $size && 1 === preg_match( '/^(sizes?|format|st(ø|oe?)rrelse)$/iu', $name ) ) {
				$size = $name;
			} elseif ( null === $color && 1 === preg_match( '/colou?r|farve/iu', $name ) ) {
				$color = $name;
			}
		}

		return array( $size, $color, array_values( array_diff( $names, array_filter( array( $size, $color ), 'is_string' ) ) ) );
	}

	/**
	 * An option's name as creators and customers read it (an attribute name).
	 *
	 * @param string $name Gelato's option name.
	 */
	public static function option_label( string $name ): string {
		return match ( strtolower( trim( $name ) ) ) {
			'orientation' => __( 'Orientation', 'merchboard' ),
			'paper'       => __( 'Paper', 'merchboard' ),
			'frame'       => __( 'Frame', 'merchboard' ),
			'material'    => __( 'Material', 'merchboard' ),
			'finish'      => __( 'Finish', 'merchboard' ),
			default       => mb_substr( trim( sanitize_text_field( $name ) ), 0, 60 ),
		};
	}

	/**
	 * An option's value as creators and customers read it.
	 *
	 * @param string $value Gelato's value.
	 */
	public static function option_value( string $value ): string {
		return match ( strtolower( trim( $value ) ) ) {
			'vertical', 'portrait'    => __( 'Vertical', 'merchboard' ),
			'horizontal', 'landscape' => __( 'Horizontal', 'merchboard' ),
			default                   => trim( $value ),
		};
	}

	/**
	 * A variant's value on the first axis: its colour and its other options.
	 *
	 * @param array<mixed> $variant      Variant.
	 * @param string|null  $color_option The colour option's name.
	 * @param string[]     $others       The other options' names.
	 */
	public static function value_of( array $variant, ?string $color_option, array $others ): string {
		$parts = array();

		// The catalog's colour wins over the template author's own names, as before (ADR-082).
		if ( null !== self::attribute( $variant, 'gco' ) ) {
			$parts[] = self::color_of( $variant );
		} elseif ( null !== $color_option ) {
			$parts[] = self::option( $variant, '/^' . preg_quote( $color_option, '/' ) . '$/iu' );
		}

		foreach ( $others as $other ) {
			$value   = self::option( $variant, '/^' . preg_quote( $other, '/' ) . '$/iu' );
			$parts[] = null === $value ? null : self::option_value( $value );
		}

		$parts = array_filter( $parts, static fn( ?string $part ): bool => null !== $part && '' !== $part );

		return array() === $parts ? self::STANDARD_COLOR : mb_substr( trim( sanitize_text_field( implode( ' / ', $parts ) ) ), 0, 60 );
	}

	/**
	 * The format groups of every value of the first axis: per value, its
	 * sizes from the largest print area to the smallest, each joining the
	 * group with the same print areas whose largest size (its print file) is
	 * closest in shape, at most SHAPE_TOLERANCE apart, else starting one.
	 * Each group's print area is a side with the print size of its largest
	 * size; the groups are numbered by their smallest size.
	 *
	 * @param array<string, array<string, array<mixed>>> $found Variant by value and size.
	 * @param string[]                                   $sizes The sizes in order.
	 * @return list<array<string, mixed>>
	 */
	private static function groups( array $found, array $sizes ): array {
		$sides  = array();
		$number = 0;

		foreach ( $found as $color => $by_size ) {
			$own = array();

			foreach ( $sizes as $size ) {
				$list = isset( $by_size[ $size ] ) ? self::sides( $by_size[ $size ] ) : array();

				if ( array() !== $list ) {
					$own[ $size ] = $list;
				}
			}

			// Largest first: a group's largest size is its print file, which each of its sizes is cut from.
			$order = array_map( 'strval', array_keys( $own ) );
			usort( $order, static fn( string $a, string $b ): int => self::area_of( $own[ $b ] ) <=> self::area_of( $own[ $a ] ) );

			$groups = array();
			$member = array();

			foreach ( $order as $size ) {
				$placements = implode( "\0", array_column( $own[ $size ], 'placement' ) );
				$best       = null;
				$closest    = INF;

				// The group whose print file is closest in shape, within SHAPE_TOLERANCE.
				foreach ( $groups as $index => $group ) {
					$apart = $group['placements'] === $placements ? self::apart( $own[ $group['largest'] ], $own[ $size ] ) : INF;

					if ( $apart <= self::SHAPE_TOLERANCE && $apart < $closest ) {
						$best    = $index;
						$closest = $apart;
					}
				}

				if ( null === $best ) {
					$best            = count( $groups );
					$groups[ $best ] = array(
						'placements' => $placements,
						'largest'    => $size,
					);
				}

				$member[ $size ] = $best;
			}

			// Each group's sizes in the product's order; the groups numbered by their smallest size.
			$lists = array();

			foreach ( array_keys( $own ) as $size ) {
				$lists[ $member[ (string) $size ] ][] = (string) $size;
			}

			foreach ( array_values( $lists ) as $index => $list ) {
				$largest = $groups[ $member[ $list[0] ] ]['largest'];
				$key     = 'g' . ( ++$number );

				foreach ( $own[ $largest ] as $at => $side ) {
					$formats = array();

					foreach ( $list as $size ) {
						$formats[ $size ] = array(
							'width_mm'  => round( $own[ $size ][ $at ]['mm'][0], 1 ),
							'height_mm' => round( $own[ $size ][ $at ]['mm'][1], 1 ),
						);
					}

					if ( count( $own[ $largest ] ) > 1 ) {
						/* translators: 1: a side, e.g. "Front"; 2: a value such as "Vertical"; 3: the number of a group of sizes with one shape. */
						$name = sprintf( __( '%1$s – %2$s – format %3$d', 'merchboard' ), $side['name'], $color, $index + 1 );
					} else {
						/* translators: 1: a value such as "Vertical"; 2: the number of a group of sizes with one shape. */
						$name = sprintf( __( '%1$s – format %2$d', 'merchboard' ), $color, $index + 1 );
					}

					$sides[] = array_merge(
						$side,
						array(
							'name'    => mb_substr( $name, 0, 60 ),
							'sizes'   => $list,
							'colors'  => array( (string) $color ),
							'group'   => $key,
							'variant' => (string) $by_size[ $largest ]['id'],
							'formats' => $formats,
						)
					);
				}
			}
		}

		return array_slice( $sides, 0, BaseProduct::MAX_VIEWS );
	}

	/**
	 * How far apart two variants' print areas are in shape: the largest
	 * relative difference of width ÷ height over their sides.
	 *
	 * @param list<array{mm: array{0: float, 1: float}}> $first One variant's sides.
	 * @param list<array{mm: array{0: float, 1: float}}> $other The other's, in the same order.
	 */
	private static function apart( array $first, array $other ): float {
		$apart = 0.0;

		foreach ( $first as $at => $side ) {
			$apart = max( $apart, abs( ( $side['mm'][0] / $side['mm'][1] ) / ( $other[ $at ]['mm'][0] / $other[ $at ]['mm'][1] ) - 1 ) );
		}

		return $apart;
	}

	/**
	 * A variant's print area in mm², its sides together.
	 *
	 * @param list<array{mm: array{0: float, 1: float}}> $sides The variant's sides.
	 */
	private static function area_of( array $sides ): float {
		return array_sum( array_map( static fn( array $side ): float => $side['mm'][0] * $side['mm'][1], $sides ) );
	}

	/**
	 * Creates a hidden product from the template in the API store.
	 *
	 * @param string               $kind   `blank` or `marker`.
	 * @param array<string, mixed> $import Import data.
	 * @return string The product id.
	 * @throws GelatoException When Gelato refuses or fails.
	 */
	public function render( string $kind, array $import ): string {
		$colors   = self::render_colors( $kind, $import );
		$variants = array_values( $colors );
		$images   = array();
		$sides    = self::render_sides( $kind, $import );

		foreach ( $sides as $index => $side ) {
			$url = str_starts_with( $kind, 'marker' ) ? ( $import['markers'][ $index ] ?? null ) : ( $import['blank'] ?? null );

			if ( ! is_string( $side['placement'] ?? null ) || ! is_string( $url ) ) {
				self::fail( GelatoException::INVALID );
			}

			$images[] = array(
				'name'      => $side['placement'],
				'fileUrl'   => $url,
				'fitMethod' => 'slice',
			);
		}

		if ( array() === $variants || array() === $images ) {
			self::fail( GelatoException::INVALID );
		}

		$answer  = $this->api->create_from_template(
			array(
				'templateId'                => (string) ( $import['ref'] ?? '' ),
				'title'                     => 'Merch Dashboard import (' . $kind . ')',
				'description'               => '',
				'isVisibleInTheOnlineStore' => false,
				'variants'                  => array_map(
					static fn( mixed $variant ): array => array(
						'templateVariantId' => (string) $variant,
						'imagePlaceholders' => $images,
					),
					$variants
				),
			)
		);
		$product = is_scalar( $answer['id'] ?? null ) ? (string) $answer['id'] : '';

		if ( 1 !== preg_match( '/^[A-Za-z0-9-]{1,100}$/', $product ) ) {
			self::fail( GelatoException::INVALID );
		}

		return $product;
	}

	/**
	 * A product's state, with its images by position and colour once it is
	 * active.
	 *
	 * @param string               $key    Product id.
	 * @param array<string, mixed> $import Import data.
	 * @return array{status: string, error?: string, note?: string, images?: array<int|string, array<string, string>>}
	 * @throws GelatoException When Gelato refuses or fails.
	 */
	public function check( string $key, array $import ): array {
		$answer = $this->api->product( $key );
		$status = is_string( $answer['status'] ?? null ) ? $answer['status'] : '';

		if ( 'publishing_error' === $status ) {
			return array(
				'status' => 'failed',
				'error'  => 'publishing_error',
			);
		}

		$note = sprintf( 'status=%s, previewUrl=%s, productImages=%d', '' === $status ? '?' : sanitize_key( $status ), is_string( $answer['previewUrl'] ?? null ) && '' !== $answer['previewUrl'] ? 'yes' : 'no', is_array( $answer['productImages'] ?? null ) ? count( $answer['productImages'] ) : 0 );

		if ( 'active' !== $status ) {
			return array(
				'status' => 'pending',
				'note'   => $note,
			);
		}

		$render = array( '', 0, time() );

		foreach ( is_array( $import['renders'] ?? null ) ? $import['renders'] : array() as $kind => $entry ) {
			$entry_key = is_array( $entry ) ? ( $entry['key'] ?? null ) : null;

			if ( $entry_key === $key ) {
				$render = array( (string) $kind, (int) ( $entry['polls'] ?? 0 ), (int) ( $entry['started'] ?? time() ) );
			}
		}

		$lists = $render[1] < self::PREVIEW_POLLS ? array() : self::variant_images( $answer, self::render_colors( $render[0], $import ) );

		if ( array() === $lists ) {
			// An active product without any preview for minutes will not get one.
			return $render[1] < self::ACTIVE_POLLS && time() - $render[2] < self::ACTIVE_WAIT
				? array(
					'status' => 'pending',
					'note'   => $note,
				)
				: array(
					'status' => 'failed',
					'note'   => $note,
					'error'  => 'active without a preview; ' . $note,
				);
		}

		$images = array();

		foreach ( $lists as $color => $urls ) {
			foreach ( $urls as $slot => $url ) {
				$images[ (string) $slot ][ $color ] = $url;
			}
		}

		return array(
			'status' => 'done',
			'images' => $images,
			'note'   => $note,
		);
	}

	/**
	 * One render with the markers and, once it has given a preview, one per
	 * colour: a product at Gelato has one preview, and every render stays in
	 * the store for good, so none is made in vain.
	 *
	 * @param array<string, mixed> $import Import data.
	 * @return list<string>
	 */
	public function render_kinds( array $import ): array {
		$groups = is_array( $import['groups'] ?? null ) ? $import['groups'] : array();

		// A format group (ADR-115) has its own variant: a marker and a blank render each.
		if ( array() !== $groups ) {
			$kinds = array();

			foreach ( array_keys( $groups ) as $group ) {
				$kinds[] = 'marker@' . $group;

				if ( 'done' === ( $import['renders'][ 'marker@' . $group ]['status'] ?? null ) ) {
					$kinds[] = 'blank@' . $group;
				}
			}

			return $kinds;
		}

		$kinds = array( 'marker' );

		if ( 'done' !== ( $import['renders']['marker']['status'] ?? null ) ) {
			return $kinds;
		}

		foreach ( is_array( $import['colors'] ?? null ) ? array_keys( $import['colors'] ) : array() as $color ) {
			$kinds[] = 'blank:' . $color;
		}

		return $kinds;
	}

	/**
	 * The colour of a render with its template variant: `marker` the first
	 * colour, `blank:<key>` that colour.
	 *
	 * @param string               $kind   Render.
	 * @param array<string, mixed> $import Import data.
	 * @return array<string, string>
	 */
	private static function render_colors( string $kind, array $import ): array {
		$at = strpos( $kind, '@' );

		if ( false !== $at ) {
			$group = substr( $kind, $at + 1 );

			return is_scalar( $import['groups'][ $group ] ?? null ) ? array( $group => (string) $import['groups'][ $group ] ) : array();
		}

		$colors = array();

		foreach ( is_array( $import['colors'] ?? null ) ? $import['colors'] : array() as $color => $variant ) {
			$colors[ (string) $color ] = (string) $variant;
		}

		if ( str_starts_with( $kind, 'blank:' ) ) {
			return array_intersect_key( $colors, array( substr( $kind, 6 ) => true ) );
		}

		return array_slice( $colors, 0, 1, true );
	}

	/**
	 * The sides a render shows, in their marker order: a format group's
	 * (`marker@<group>`, `blank@<group>`), else every side.
	 *
	 * @param string               $kind   Render.
	 * @param array<string, mixed> $import Import data.
	 * @return list<array<mixed>>
	 */
	public static function render_sides( string $kind, array $import ): array {
		$sides = array_values( array_filter( is_array( $import['sides'] ?? null ) ? $import['sides'] : array(), 'is_array' ) );
		$at    = strpos( $kind, '@' );

		if ( false === $at ) {
			return $sides;
		}

		$group = substr( $kind, $at + 1 );

		return array_values( array_filter( $sides, static fn( array $side ): bool => ( $side['group'] ?? null ) === $group ) );
	}

	/**
	 * A catalog product's finished size in mm (GelatoApi::dimensions()).
	 *
	 * @param string $uid The product's `productUid`.
	 * @return array{0: float, 1: float}|null
	 * @throws GelatoException When the call fails.
	 */
	public function dimensions( string $uid ): ?array {
		return $this->api->dimensions( $uid );
	}

	/**
	 * The bleed of a print area in mm per axis, [x, y]: half of what the
	 * print area exceeds the finished size by, only when both lie between
	 * MIN_BLEED and MAX_BLEED, also with the finished size turned (ADR-115
	 * pkt. 8). Null on clothing and anything else without a bleed.
	 *
	 * @param array{0: float, 1: float}      $area     The print area in mm.
	 * @param array{0: float, 1: float}|null $finished The finished size in mm.
	 * @return array{0: float, 1: float}|null
	 */
	public static function bleed( array $area, ?array $finished ): ?array {
		if ( null === $finished || $area[0] <= 0 || $area[1] <= 0 ) {
			return null;
		}

		foreach ( array( $finished, array( $finished[1], $finished[0] ) ) as $trim ) {
			$x = ( $area[0] - $trim[0] ) / 2;
			$y = ( $area[1] - $trim[1] ) / 2;

			if ( $x >= self::MIN_BLEED && $y >= self::MIN_BLEED && $x <= self::MAX_BLEED && $y <= self::MAX_BLEED ) {
				return array( round( $x, 2 ), round( $y, 2 ) );
			}
		}

		return null;
	}

	/**
	 * Gelato's answer about a render's product.
	 *
	 * @param string $key Product id.
	 * @return array<string, mixed>
	 * @throws GelatoException When Gelato refuses or fails.
	 */
	public function render_answer( string $key ): array {
		return $this->api->product( $key );
	}

	/**
	 * A file Gelato returned.
	 *
	 * @param string $url URL.
	 * @throws GelatoException When it cannot be downloaded.
	 */
	public function download( string $url ): string {
		return $this->api->download( $url );
	}

	/**
	 * Seconds between two looks at a product.
	 */
	public function poll_delay(): int {
		return self::POLL_DELAY;
	}

	/**
	 * Gelato's answer about a template.
	 *
	 * @param string $ref Template id.
	 * @return array<string, mixed>
	 * @throws GelatoException When Gelato refuses or fails.
	 */
	public function diagnose( string $ref ): array {
		return array( 'GET /v1/templates/' . $ref => $this->api->template( $ref ) );
	}

	/**
	 * A product's images per colour key, in Gelato's order: the images tied
	 * to a variant (`productVariantIds`) for its colour (the variant's
	 * `templateVariantId`, else its `productUid` colour). Are none tied,
	 * untied images are shared equally between the colours in the order sent
	 * (all to the first when they do not divide), and without any image the
	 * first colour takes the product's `previewUrl`. Real answers tie the
	 * images to variants (2026-10-30); store variants have no `templateVariantId`.
	 *
	 * @param array<string, mixed>  $answer Product answer.
	 * @param array<string, string> $colors Template variant by colour key.
	 * @return array<string, list<string>>
	 */
	public static function variant_images( array $answer, array $colors ): array {
		// A product of one variant: every image is that variant's (a format group's render, ADR-115).
		if ( 1 === count( $colors ) ) {
			$urls = array();

			foreach ( is_array( $answer['productImages'] ?? null ) ? $answer['productImages'] : array() as $image ) {
				if ( is_array( $image ) && is_string( $image['fileUrl'] ?? null ) && '' !== $image['fileUrl'] && ! in_array( $image['fileUrl'], $urls, true ) ) {
					$urls[] = $image['fileUrl'];
				}
			}

			if ( array() === $urls && is_string( $answer['previewUrl'] ?? null ) && '' !== $answer['previewUrl'] ) {
				$urls[] = $answer['previewUrl'];
			}

			return array() === $urls ? array() : array( (string) array_key_first( $colors ) => $urls );
		}

		$by_template = array_flip( array_filter( $colors, static fn( string $variant ): bool => '' !== $variant ) );
		$by_variant  = array();
		$images      = array();

		foreach ( is_array( $answer['variants'] ?? null ) ? $answer['variants'] : array() as $variant ) {
			if ( ! is_array( $variant ) || ! is_scalar( $variant['id'] ?? null ) ) {
				continue;
			}

			$template = is_scalar( $variant['templateVariantId'] ?? null ) ? (string) $variant['templateVariantId'] : '';
			$key      = (string) ( $by_template[ $template ] ?? substr( sanitize_title( self::color_of( $variant ) ), 0, 32 ) );

			if ( isset( $colors[ $key ] ) ) {
				$by_variant[ (string) $variant['id'] ] = $key;
			}
		}

		$untied = array();

		foreach ( is_array( $answer['productImages'] ?? null ) ? $answer['productImages'] : array() as $image ) {
			$url  = is_array( $image ) && is_string( $image['fileUrl'] ?? null ) ? $image['fileUrl'] : '';
			$tied = is_array( $image ) && is_array( $image['productVariantIds'] ?? null ) ? $image['productVariantIds'] : array();

			if ( '' === $url ) {
				continue;
			}

			if ( array() === $tied ) {
				$untied[] = $url;
			}

			foreach ( $tied as $variant ) {
				$key = is_scalar( $variant ) ? ( $by_variant[ (string) $variant ] ?? null ) : null;

				if ( null !== $key && ! in_array( $url, $images[ $key ] ?? array(), true ) ) {
					$images[ $key ][] = $url;
				}
			}
		}

		$keys = array_map( 'strval', array_keys( $colors ) );

		if ( array() === $images && array() !== $untied && array() !== $keys ) {
			// Images tied to no variant: an equal share per colour in the order sent, else all to the first colour.
			$share = max( 1, 0 === count( $untied ) % count( $keys ) ? intdiv( count( $untied ), count( $keys ) ) : count( $untied ) );

			foreach ( array_chunk( $untied, $share ) as $index => $urls ) {
				$images[ $keys[ $index ] ] = $urls;
			}
		}

		if ( array() === $images && isset( $keys[0] ) && is_string( $answer['previewUrl'] ?? null ) && '' !== $answer['previewUrl'] ) {
			$images[ $keys[0] ][] = $answer['previewUrl'];
		}

		return $images;
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
	 * A variant's colour: the catalog's `gco_` in its `productUid`, else its
	 * option "Color" or "Colour", else "Standard".
	 *
	 * @param array<mixed> $variant Variant.
	 */
	public static function color_of( array $variant ): string {
		$value = self::attribute( $variant, 'gco' );
		$name  = null === $value ? null : ucfirst( str_replace( '-', ' ', $value ) );

		return mb_substr( $name ?? self::option( $variant, '/^colou?r$/i' ) ?? self::STANDARD_COLOR, 0, 60 );
	}

	/**
	 * A variant's size: the catalog's `gsi_` in its `productUid`, else the
	 * template's size option (ADR-115), else an option named like a size,
	 * else "One size"; as a size name (BaseProduct::size_name()).
	 *
	 * @param array<mixed> $variant Variant.
	 * @param string|null  $option  The template's size option (axes()).
	 */
	public static function size_of( array $variant, ?string $option = null ): string {
		$value = self::attribute( $variant, 'gsi' );
		$name  = null;

		if ( null !== $value ) {
			$name = 1 === preg_match( '/^[a-z0-9]{1,4}$/', $value ) ? strtoupper( $value ) : ucfirst( str_replace( '-', ' ', $value ) );
		}

		$own = null === $option ? null : self::option( $variant, '/^' . preg_quote( $option, '/' ) . '$/iu' );

		return BaseProduct::size_name( $name ?? $own ?? self::option( $variant, '/^(sizes?|format|st(ø|oe?)rrelse)$/iu' ) ?? self::ONE_SIZE );
	}

	/**
	 * A print area's name for creators: Gelato numbers the areas of a poster
	 * or a canvas ("1"), which reads as "Forside", "Side 2" … (ADR-115 pkt. 7).
	 *
	 * @param string $label The print area or placeholder name.
	 */
	public static function side_label( string $label ): string {
		$label = trim( $label );

		if ( 1 === preg_match( '/^\d{1,2}$/', $label ) ) {
			/* translators: %d: the number of a print area, 2 or more. */
			return (int) $label <= 1 ? __( 'Front', 'merchboard' ) : sprintf( __( 'Side %d', 'merchboard' ), (int) $label );
		}

		return mb_substr( trim( sanitize_text_field( ucfirst( str_replace( array( '_', '-' ), ' ', $label ) ) ) ), 0, 60 );
	}

	/**
	 * The print area of a variant in mm², all its placeholders together (the
	 * order of sizes that are not clothing).
	 *
	 * @param array<mixed> $variant Variant.
	 */
	public static function print_area( array $variant ): float {
		$area = 0.0;

		foreach ( is_array( $variant['imagePlaceholders'] ?? null ) ? $variant['imagePlaceholders'] : array() as $placeholder ) {
			if ( is_array( $placeholder ) ) {
				$area += max( 0.0, (float) ( $placeholder['width'] ?? 0 ) ) * max( 0.0, (float) ( $placeholder['height'] ?? 0 ) );
			}
		}

		return $area;
	}

	/**
	 * The sides of a variant's image placeholders with their print size.
	 *
	 * @param array<mixed> $variant First variant.
	 * @return list<array{placement: string, name: string, width_mm: int, height_mm: int, dpi: int, width: int, height: int, mm: array{0: float, 1: float}}>
	 */
	private static function sides( array $variant ): array {
		$sides = array();
		$keys  = array();

		foreach ( is_array( $variant['imagePlaceholders'] ?? null ) ? $variant['imagePlaceholders'] : array() as $placeholder ) {
			$name      = is_array( $placeholder ) && is_string( $placeholder['name'] ?? null ) ? $placeholder['name'] : '';
			$width_mm  = is_array( $placeholder ) ? (int) round( (float) ( $placeholder['width'] ?? 0 ) ) : 0;
			$height_mm = is_array( $placeholder ) ? (int) round( (float) ( $placeholder['height'] ?? 0 ) ) : 0;

			if ( '' === $name || $width_mm < 1 || $height_mm < 1 || max( $width_mm, $height_mm ) > BaseView::MAX_MM || count( $sides ) >= self::MAX_SIDES ) {
				continue;
			}

			$label = self::side_label( is_string( $placeholder['printArea'] ?? null ) && '' !== trim( $placeholder['printArea'] ) ? $placeholder['printArea'] : $name );
			$key   = substr( sanitize_title( $label ), 0, 32 );

			if ( '' === $key || isset( $keys[ $key ] ) ) {
				continue;
			}

			$dpi          = self::dpi( max( $width_mm, $height_mm ) );
			$keys[ $key ] = true;
			$sides[]      = array(
				'placement' => $name,
				'name'      => $label,
				'width_mm'  => $width_mm,
				'height_mm' => $height_mm,
				'dpi'       => $dpi,
				'width'     => (int) round( $width_mm * $dpi / 25.4 ),
				'height'    => (int) round( $height_mm * $dpi / 25.4 ),
				'mm'        => array( (float) $placeholder['width'], (float) $placeholder['height'] ),
			);
		}

		return $sides;
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
	 * A catalog attribute of a variant's `productUid` (the part after
	 * `_<code>_`), or null.
	 *
	 * @param array<mixed> $variant Variant.
	 * @param string       $code    Attribute code, e.g. `gco`.
	 */
	private static function attribute( array $variant, string $code ): ?string {
		$uid   = is_string( $variant['productUid'] ?? null ) ? strtolower( $variant['productUid'] ) : '';
		$parts = explode( '_', $uid );
		$at    = array_search( $code, $parts, true );
		$value = false === $at ? '' : (string) ( $parts[ $at + 1 ] ?? '' );

		return 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,40}$/', $value ) ? $value : null;
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
	 * Fails with a reason.
	 *
	 * @param string $reason GelatoException reason.
	 * @throws GelatoException Always.
	 */
	private static function fail( string $reason ): never {
		throw new GelatoException( $reason );
	}
}
