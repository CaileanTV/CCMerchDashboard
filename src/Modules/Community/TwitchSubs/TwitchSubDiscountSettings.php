<?php
/**
 * A creator's discount for Twitch subscribers.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchSubs;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-106 pkt. 3: on or off, a whole percent per tier (0–100, stored in
 * basis points), whether gifted subscriptions count, and whether the
 * discount covers all the creator's products or a selection (at most
 * MAX_PRODUCTS parent products).
 */
final readonly class TwitchSubDiscountSettings {

	/**
	 * All the creator's products.
	 */
	public const ALL = 'all';

	/**
	 * Only the selected products.
	 */
	public const SELECTED = 'selected';

	/**
	 * Most selected products.
	 */
	public const MAX_PRODUCTS = 500;

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param bool                          $enabled     Whether the discount is on.
	 * @param array{1: int, 2: int, 3: int} $tiers       Basis points per tier (0–10 000, whole percents).
	 * @param bool                          $gifted      Whether gifted subscriptions count.
	 * @param string                        $scope       ALL or SELECTED.
	 * @param int[]                         $product_ids Selected parent products (SELECTED only).
	 */
	public function __construct(
		public bool $enabled,
		public array $tiers,
		public bool $gifted,
		public string $scope,
		public array $product_ids
	) {
	}

	/**
	 * The settings of a creator who has set nothing: off, 0 %, gifted
	 * subscriptions count, all products.
	 */
	public static function defaults(): self {
		return new self(
			false,
			array(
				1 => 0,
				2 => 0,
				3 => 0,
			),
			true,
			self::ALL,
			array()
		);
	}

	/**
	 * The stored settings; anything unreadable falls back to the defaults
	 * (off).
	 *
	 * @param mixed $stored Stored creator meta.
	 */
	public static function from_stored( mixed $stored ): self {
		if ( ! is_array( $stored ) ) {
			return self::defaults();
		}

		$tiers = array();

		foreach ( array( 1, 2, 3 ) as $tier ) {
			$bps = $stored['tiers'][ $tier ] ?? ( $stored['tiers'][ (string) $tier ] ?? null );

			if ( ! is_int( $bps ) || $bps < 0 || $bps > 10000 || 0 !== $bps % 100 ) {
				return self::defaults();
			}

			$tiers[ $tier ] = $bps;
		}

		$scope = $stored['scope'] ?? null;
		$ids   = $stored['product_ids'] ?? array();

		if ( ! in_array( $scope, array( self::ALL, self::SELECTED ), true ) || ! is_array( $ids ) ) {
			return self::defaults();
		}

		return new self(
			true === ( $stored['enabled'] ?? false ),
			array(
				1 => $tiers[1],
				2 => $tiers[2],
				3 => $tiers[3],
			),
			true === ( $stored['gifted'] ?? true ),
			$scope,
			self::SELECTED === $scope ? self::ids( $ids ) : array()
		);
	}

	/**
	 * The value to store.
	 *
	 * @return array{enabled: bool, tiers: array{1: int, 2: int, 3: int}, gifted: bool, scope: string, product_ids: list<int>}
	 */
	public function to_stored(): array {
		return array(
			'enabled'     => $this->enabled,
			'tiers'       => $this->tiers,
			'gifted'      => $this->gifted,
			'scope'       => $this->scope,
			'product_ids' => $this->product_ids,
		);
	}

	/**
	 * The basis points for a subscription, or 0 when it gives none (off, no
	 * subscription, a gift that does not count).
	 *
	 * @param int  $tier   Tier 1–3, 0 for none.
	 * @param bool $gifted Whether the subscription was a gift.
	 */
	public function bps_for( int $tier, bool $gifted ): int {
		if ( ! $this->enabled || ( $gifted && ! $this->gifted ) || ! in_array( $tier, array( 1, 2, 3 ), true ) ) {
			return 0;
		}

		return $this->tiers[ $tier ];
	}

	/**
	 * Whether the discount covers a parent product of the creator.
	 *
	 * @param int $product_id Parent product.
	 */
	public function covers( int $product_id ): bool {
		return self::ALL === $this->scope || in_array( $product_id, $this->product_ids, true );
	}

	/**
	 * Whether the discount can give anything (on, a percent above 0, and a
	 * product when only selected ones count).
	 */
	public function active(): bool {
		return $this->enabled && max( $this->tiers ) > 0 && ( self::ALL === $this->scope || array() !== $this->product_ids );
	}

	/**
	 * The highest percent of any tier (for the shop's card).
	 */
	public function highest_percent(): int {
		return intdiv( max( $this->tiers ), 100 );
	}

	/**
	 * Positive unique product ids, at most MAX_PRODUCTS.
	 *
	 * @param array<mixed> $ids Ids.
	 * @return list<int>
	 */
	private static function ids( array $ids ): array {
		$clean = array();

		foreach ( $ids as $id ) {
			if ( is_int( $id ) && $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}

		return array_slice( array_values( $clean ), 0, self::MAX_PRODUCTS );
	}
}
