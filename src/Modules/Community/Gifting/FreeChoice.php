<?php
/**
 * When a collection can be given with free choice of design.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-120, status note 2026-11-22: the winner may choose among a collection's
 * designs only when it costs and settles the same whichever is chosen, as a
 * gift's profit is booked at the purchase and a giveaway costs the base
 * price. Pure: the caller reads the prices, in the ledger's integer units.
 *
 * A design is its creator and its variants; a variant is its attributes, the
 * customer's price and the base price. Designs cost the same when:
 *
 * - they have the same creator;
 * - the same prices occur in both, as pairs of customer and base price;
 * - a variant both have has the same pair;
 * - an option both have (size M, say) has a pair in common, also where their
 *   colours differ so that no variant is in both.
 *
 * What may be chosen is locked at the purchase: allowed() only ever takes
 * designs away from that.
 */
final class FreeChoice {

	/**
	 * Fewest designs to choose between.
	 */
	public const MIN_DESIGNS = 2;

	/**
	 * Whether all the designs cost the same, so one can be chosen freely.
	 *
	 * @phpstan-param array<int, array{creator: int, variants: list<array{attributes: array<string, string>, price: int, base: int}>}> $designs
	 *
	 * @param array[] $designs The designs by product.
	 */
	public static function offered( array $designs ): bool {
		if ( count( $designs ) < self::MIN_DESIGNS ) {
			return false;
		}

		$all = array_values( $designs );

		// Every pair: two designs can agree with the first and differ on a variant it lacks.
		foreach ( $all as $index => $one ) {
			if ( array() === $one['variants'] ) {
				return false;
			}

			foreach ( array_slice( $all, $index + 1 ) as $other ) {
				if ( ! self::same( $one, $other ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * The designs a winner may choose: those locked at the purchase that are
	 * still sold at the same prices. With none left, all of those from the
	 * purchase, so a winner is never without the gift; the code covers up to
	 * what was paid.
	 *
	 * @phpstan-param array<int, array{creator: int, variants: list<array{attributes: array<string, string>, price: int, base: int}>}> $locked
	 * @phpstan-param array<int, array{creator: int, variants: list<array{attributes: array<string, string>, price: int, base: int}>}> $current
	 *
	 * @param array[] $locked  The designs as they were at the purchase, by product.
	 * @param array[] $current The ones still sold, as they are now, by product.
	 * @return list<int>
	 */
	public static function allowed( array $locked, array $current ): array {
		$allowed = array();

		foreach ( $locked as $product_id => $then ) {
			$now = $current[ $product_id ] ?? null;

			if ( null !== $now && self::unchanged( $then, $now ) ) {
				$allowed[] = $product_id;
			}
		}

		return array() === $allowed ? array_keys( $locked ) : $allowed;
	}

	/**
	 * Whether two designs cost the same.
	 *
	 * @phpstan-param array{creator: int, variants: list<array{attributes: array<string, string>, price: int, base: int}>} $one
	 * @phpstan-param array{creator: int, variants: list<array{attributes: array<string, string>, price: int, base: int}>} $other
	 *
	 * @param array<string, mixed> $one   A design.
	 * @param array<string, mixed> $other Another.
	 */
	private static function same( array $one, array $other ): bool {
		if ( $one['creator'] !== $other['creator'] || self::pairs( $one['variants'] ) !== self::pairs( $other['variants'] ) || ! self::agree( $one['variants'], $other['variants'] ) ) {
			return false;
		}

		$options = self::by_option( $other['variants'] );

		foreach ( self::by_option( $one['variants'] ) as $option => $pairs ) {
			if ( isset( $options[ $option ] ) && array() === array_intersect( $pairs, $options[ $option ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a design still costs what it did: a variant sold out or taken
	 * away changes nothing, a price that was not there does.
	 *
	 * @phpstan-param array{creator: int, variants: list<array{attributes: array<string, string>, price: int, base: int}>} $then
	 * @phpstan-param array{creator: int, variants: list<array{attributes: array<string, string>, price: int, base: int}>} $now
	 *
	 * @param array<string, mixed> $then At the purchase.
	 * @param array<string, mixed> $now  Now.
	 */
	private static function unchanged( array $then, array $now ): bool {
		return $then['creator'] === $now['creator']
			&& array() !== $now['variants']
			&& array() === array_diff( self::pairs( $now['variants'] ), self::pairs( $then['variants'] ) )
			&& self::agree( $then['variants'], $now['variants'] );
	}

	/**
	 * Whether every variant in both lists has the same pair in both.
	 *
	 * @phpstan-param list<array{attributes: array<string, string>, price: int, base: int}> $one
	 * @phpstan-param list<array{attributes: array<string, string>, price: int, base: int}> $other
	 *
	 * @param array[] $one   Variants.
	 * @param array[] $other Variants.
	 */
	private static function agree( array $one, array $other ): bool {
		$known = self::by_variant( $other );

		foreach ( self::by_variant( $one ) as $variant => $pairs ) {
			if ( isset( $known[ $variant ] ) && $known[ $variant ] !== $pairs ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The pairs of customer and base price, each once, sorted.
	 *
	 * @phpstan-param list<array{attributes: array<string, string>, price: int, base: int}> $variants
	 *
	 * @param array[] $variants Variants.
	 * @return list<string>
	 */
	private static function pairs( array $variants ): array {
		$pairs = array_values( array_unique( array_map( self::pair( ... ), $variants ) ) );
		sort( $pairs, SORT_STRING );

		return $pairs;
	}

	/**
	 * The pairs of each variant, known by its attributes in any order.
	 *
	 * @phpstan-param list<array{attributes: array<string, string>, price: int, base: int}> $variants
	 *
	 * @param array[] $variants Variants.
	 * @return array<string, list<string>>
	 */
	private static function by_variant( array $variants ): array {
		$grouped = array();

		foreach ( $variants as $variant ) {
			$options = self::options( $variant['attributes'] );
			sort( $options, SORT_STRING );
			$grouped[ implode( "\n", $options ) ][] = $variant;
		}

		return array_map( self::pairs( ... ), $grouped );
	}

	/**
	 * The pairs of each option.
	 *
	 * @phpstan-param list<array{attributes: array<string, string>, price: int, base: int}> $variants
	 *
	 * @param array[] $variants Variants.
	 * @return array<string, list<string>>
	 */
	private static function by_option( array $variants ): array {
		$grouped = array();

		foreach ( $variants as $variant ) {
			foreach ( self::options( $variant['attributes'] ) as $option ) {
				$grouped[ $option ][] = $variant;
			}
		}

		return array_map( self::pairs( ... ), $grouped );
	}

	/**
	 * A variant's options as comparable text.
	 *
	 * @param array<string, string> $attributes Attributes.
	 * @return list<string>
	 */
	private static function options( array $attributes ): array {
		$options = array();

		foreach ( $attributes as $name => $value ) {
			$options[] = mb_strtolower( trim( $name ) . '=' . trim( $value ) );
		}

		return $options;
	}

	/**
	 * A variant's customer and base price as one value.
	 *
	 * @phpstan-param array{attributes: array<string, string>, price: int, base: int} $variant
	 *
	 * @param array<string, mixed> $variant A variant.
	 */
	private static function pair( array $variant ): string {
		return $variant['price'] . '/' . $variant['base'];
	}
}
