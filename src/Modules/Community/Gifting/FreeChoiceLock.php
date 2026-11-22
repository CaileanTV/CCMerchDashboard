<?php
/**
 * A collection as it was when it was given with free choice of design.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

defined( 'ABSPATH' ) || exit;

/**
 * The value of `free_choice` on a gift and a giveaway (schema 54, ADR-120):
 * the collection, its name and its designs with their prices, locked at the
 * purchase. An unreadable value reads as none, so the gift is of its one
 * product and never of more than was paid for.
 */
final readonly class FreeChoiceLock {

	/**
	 * Constructor.
	 *
	 * @phpstan-param array<int, array{creator: int, variants: list<array{attributes: array<string, string>, price: int, base: int}>}> $designs
	 *
	 * @param int     $collection_id The collection.
	 * @param string  $name          Its name then, which the gift is called by.
	 * @param array[] $designs       The designs by product.
	 */
	public function __construct( public int $collection_id, public string $name, public array $designs ) {
	}

	/**
	 * The designs' products.
	 *
	 * @return list<int>
	 */
	public function product_ids(): array {
		return array_keys( $this->designs );
	}

	/**
	 * As it is stored.
	 */
	public function to_json(): string {
		$designs = array();

		foreach ( $this->designs as $id => $design ) {
			$designs[] = array(
				'id'       => $id,
				'creator'  => $design['creator'],
				'variants' => $design['variants'],
			);
		}

		return (string) wp_json_encode(
			array(
				'collection' => $this->collection_id,
				'name'       => $this->name,
				'designs'    => $designs,
			)
		);
	}

	/**
	 * A stored value, or null for none and for anything unreadable.
	 *
	 * @param mixed $stored The column's value.
	 */
	public static function from_stored( mixed $stored ): ?self {
		$data = is_string( $stored ) && '' !== $stored ? json_decode( $stored, true ) : null;

		if ( ! is_array( $data ) || ! is_int( $data['collection'] ?? null ) || ! is_string( $data['name'] ?? null ) || ! is_array( $data['designs'] ?? null ) ) {
			return null;
		}

		$designs = array();

		foreach ( $data['designs'] as $design ) {
			if ( ! is_array( $design ) || ! is_int( $design['id'] ?? null ) || ! is_int( $design['creator'] ?? null ) || ! is_array( $design['variants'] ?? null ) ) {
				return null;
			}

			$variants = array();

			foreach ( $design['variants'] as $variant ) {
				$read = self::variant( $variant );

				if ( null === $read ) {
					return null;
				}

				$variants[] = $read;
			}

			if ( array() === $variants ) {
				return null;
			}

			$designs[ $design['id'] ] = array(
				'creator'  => $design['creator'],
				'variants' => $variants,
			);
		}

		return count( $designs ) < FreeChoice::MIN_DESIGNS ? null : new self( $data['collection'], $data['name'], $designs );
	}

	/**
	 * A stored variant, or null.
	 *
	 * @param mixed $variant Stored value.
	 * @return array{attributes: array<string, string>, price: int, base: int}|null
	 */
	private static function variant( mixed $variant ): ?array {
		if ( ! is_array( $variant ) || ! is_int( $variant['price'] ?? null ) || ! is_int( $variant['base'] ?? null ) || ! is_array( $variant['attributes'] ?? null ) ) {
			return null;
		}

		$attributes = array();

		foreach ( $variant['attributes'] as $name => $value ) {
			if ( ! is_string( $value ) ) {
				return null;
			}

			$attributes[ (string) $name ] = $value;
		}

		return array(
			'attributes' => $attributes,
			'price'      => $variant['price'],
			'base'       => $variant['base'],
		);
	}
}
