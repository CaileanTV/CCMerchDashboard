<?php
/**
 * The bleed of a Gelato base product, fetched again.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Providers;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductService;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\DesignerException;

defined( 'ABSPATH' ) || exit;

/**
 * "Hent bleed fra Gelato" (ADR-115 pkt. 8) for a base product imported
 * from a Gelato template, also one imported before 2.3.0: per side and size,
 * the size's print area in its own template variant and the finished size
 * Gelato's product API names (GelatoApi::dimensions(), kept 30 days) give the
 * bleed. A size whose print area differs from the side's shape by more than
 * GelatoCatalog::SHAPE_TOLERANCE gets none, as it is no size of that side's
 * print file. A failed call leaves the stored bleed as it is.
 */
final class GelatoBleed {

	/**
	 * Constructor.
	 *
	 * @param GelatoApi             $api     Gelato.
	 * @param BaseProductRepository $bases   Base products.
	 * @param BaseProductService    $service Base products (stores the formats).
	 */
	public function __construct( private GelatoApi $api, private BaseProductRepository $bases, private BaseProductService $service ) {
	}

	/**
	 * Fetches and stores the bleed of every side and size.
	 *
	 * @param int $id Base product.
	 * @return int How many sizes of the sides have a bleed.
	 * @throws DesignerException When the base product is unknown or no Gelato product, or the user may not.
	 * @throws GelatoException When Gelato refuses or fails.
	 * @throws DatabaseException When it cannot be read or stored.
	 */
	public function fetch( int $id ): int {
		$base     = $this->bases->find( $id );
		$provider = $this->bases->provider( $id );

		if ( null === $base || null === $provider || GelatoCatalog::SOURCE !== $provider['source'] ) {
			self::unknown();
		}

		$by_id = array();

		foreach ( GelatoCatalog::variants( $this->api->template( $provider['ref'] ) ) as $variant ) {
			if ( is_scalar( $variant['id'] ?? null ) ) {
				$by_id[ (string) $variant['id'] ] = $variant;
			}
		}

		$formats = array();
		$count   = 0;

		foreach ( $base->views as $view ) {
			$placement = $provider['placements'][ $view->key ] ?? null;

			if ( null === $placement ) {
				continue;
			}

			$own = $this->formats_of( $base, $view, $placement, $provider, $by_id );

			$formats[ $view->key ] = $own;
			$count                += count( array_filter( $own, static fn( array $format ): bool => isset( $format['bleed'] ) ) );
		}

		$this->service->set_formats( $id, $formats );

		return $count;
	}

	/**
	 * A side's formats: each of its sizes' print area, with the bleed.
	 *
	 * @param BaseProduct                                                                                       $base      Base product.
	 * @param BaseView                                                                                          $view      Side.
	 * @param string                                                                                            $placement The side's placeholder.
	 * @param array{variants: array<string, array<string, string>>, uids: array<string, array<string, string>>} $provider  Provider data.
	 * @param array<string, array<mixed>>                                                                       $by_id     Template variant by id.
	 * @return array<string, array{width_mm: float, height_mm: float, bleed?: array{0: float, 1: float}}>
	 * @throws GelatoException When Gelato refuses or fails.
	 */
	private function formats_of( BaseProduct $base, BaseView $view, string $placement, array $provider, array $by_id ): array {
		$formats = $view->formats;
		$colors  = array() === $view->colors ? array_column( $base->colors, 'key' ) : $view->colors;
		$shape   = $view->width_mm / $view->height_mm;

		foreach ( array() === $view->sizes ? $base->sizes : $view->sizes as $size ) {
			foreach ( $colors as $color ) {
				$variant = $by_id[ $provider['variants'][ $color ][ $size ] ?? '' ] ?? null;
				$area    = null === $variant ? null : self::placeholder( $variant, $placement );

				if ( null === $area || abs( ( $area[0] / $area[1] ) / $shape - 1 ) > GelatoCatalog::SHAPE_TOLERANCE ) {
					continue;
				}

				$uid   = $provider['uids'][ $color ][ $size ] ?? ( is_string( $variant['productUid'] ?? null ) ? $variant['productUid'] : '' );
				$bleed = GelatoCatalog::bleed( $area, '' === $uid ? null : $this->api->dimensions( $uid ) );

				$formats[ $size ] = array(
					'width_mm'  => round( $area[0], 1 ),
					'height_mm' => round( $area[1], 1 ),
				) + ( null === $bleed ? array() : array( 'bleed' => $bleed ) );
				break;
			}
		}

		return $formats;
	}

	/**
	 * Refuses a base product that is unknown or none of Gelato's.
	 *
	 * @throws DesignerException Always.
	 */
	private static function unknown(): never {
		throw new DesignerException( DesignerException::BASE_UNKNOWN );
	}

	/**
	 * A variant's placeholder size in mm, or null.
	 *
	 * @param array<mixed> $variant   Template variant.
	 * @param string       $placement Placeholder name.
	 * @return array{0: float, 1: float}|null
	 */
	private static function placeholder( array $variant, string $placement ): ?array {
		foreach ( is_array( $variant['imagePlaceholders'] ?? null ) ? $variant['imagePlaceholders'] : array() as $placeholder ) {
			if ( is_array( $placeholder ) && ( $placeholder['name'] ?? null ) === $placement && is_numeric( $placeholder['width'] ?? null ) && is_numeric( $placeholder['height'] ?? null ) && (float) $placeholder['width'] > 0 && (float) $placeholder['height'] > 0 ) {
				return array( (float) $placeholder['width'], (float) $placeholder['height'] );
			}
		}

		return null;
	}
}
