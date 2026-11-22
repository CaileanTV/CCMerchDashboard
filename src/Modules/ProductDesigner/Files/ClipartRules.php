<?php
/**
 * Who may use a clipart.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Files;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-121 pkt. 1, 7 and 11: a clipart can be chosen where it is offered, and
 * a design that has it keeps it when it is removed or taken off the base
 * product, also in a copy. What a design has is read from the stored
 * versions, never from the request, and keeping never widens the kind: an
 * upload or a template layer is not kept by it.
 */
final class ClipartRules {

	/**
	 * Whether an asset is a clipart of the administrator's library.
	 *
	 * @param array{creator_id: int|null, kind: string} $asset Asset record.
	 */
	public static function is_clipart( array $asset ): bool {
		return null === $asset['creator_id'] && in_array( $asset['kind'], array( DesignerAssetRepository::CLIPART, DesignerAssetRepository::ARTWORK ), true );
	}

	/**
	 * Whether a clipart may be in a design on a base product.
	 *
	 * @param Clipart $clipart The clipart.
	 * @param int     $base_id The design's base product.
	 * @param int[]   $kept    The images the stored design already has.
	 */
	public static function may_use( Clipart $clipart, int $base_id, array $kept ): bool {
		return $clipart->readable() && ( in_array( $clipart->id, $kept, true ) || $clipart->offered_on( $base_id ) );
	}

	/**
	 * The images of stored designs: their sides, shared formats and colour
	 * designs. A hidden one counts too.
	 *
	 * @param mixed ...$stored Designs as stored (JSON); anything else has none.
	 * @return list<int>
	 */
	public static function used( mixed ...$stored ): array {
		$used = array();

		foreach ( $stored as $json ) {
			$design = is_string( $json ) ? json_decode( $json, true ) : null;

			if ( ! is_array( $design ) ) {
				continue;
			}

			$used += self::images( $design );

			foreach ( is_array( $design['designs'] ?? null ) ? $design['designs'] : array() as $part ) {
				$used += is_array( $part ) ? self::images( $part ) : array();
			}
		}

		return array_values( $used );
	}

	/**
	 * The images of one design.
	 *
	 * @param array<mixed> $part The design or a colour design.
	 * @return array<int, int> The ids by id.
	 */
	private static function images( array $part ): array {
		$lists   = is_array( $part['sides'] ?? null ) ? array_values( $part['sides'] ) : array();
		$formats = is_array( $part['formats'] ?? null ) ? $part['formats'] : array();

		foreach ( is_array( $formats['shared'] ?? null ) ? $formats['shared'] : array() as $master ) {
			$lists[] = is_array( $master ) ? ( $master['objects'] ?? null ) : null;
		}

		$images = array();

		foreach ( $lists as $objects ) {
			foreach ( is_array( $objects ) ? $objects : array() as $object ) {
				if ( is_array( $object ) && 'image' === ( $object['type'] ?? null ) && is_int( $object['asset'] ?? null ) ) {
					$images[ $object['asset'] ] = $object['asset'];
				}
			}
		}

		return $images;
	}
}
