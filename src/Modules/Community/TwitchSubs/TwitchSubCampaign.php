<?php
/**
 * One of a creator's discount campaigns for Twitch subscribers.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchSubs;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-106 pkt. 3 (status note 2026-11-10): a named campaign with a whole
 * percent per tier (0–100 with up to 2 decimals, stored in basis points), all the creator's
 * products or a selection (at most MAX_PRODUCTS parent products), on or off,
 * and an optional period of whole days in the site's time zone (inclusive).
 */
final readonly class TwitchSubCampaign {

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
	 * Longest name.
	 */
	public const MAX_NAME = 60;

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param string                        $id          Stable id (16 hex).
	 * @param string                        $name        The creator's name for it.
	 * @param bool                          $enabled     Whether it is on.
	 * @param array{1: int, 2: int, 3: int} $tiers       Basis points per tier (0–10 000).
	 * @param string                        $scope       ALL or SELECTED.
	 * @param int[]                         $product_ids Selected parent products (SELECTED only).
	 * @param string|null                   $starts      First day (Y-m-d), null for none.
	 * @param string|null                   $ends        Last day (Y-m-d), null for none.
	 */
	public function __construct(
		public string $id,
		public string $name,
		public bool $enabled,
		public array $tiers,
		public string $scope,
		public array $product_ids,
		public ?string $starts = null,
		public ?string $ends = null
	) {
	}

	/**
	 * A stored campaign, or null when it is unreadable.
	 *
	 * @param mixed $stored Stored value.
	 */
	public static function from_stored( mixed $stored ): ?self {
		if ( ! is_array( $stored ) || ! is_string( $stored['id'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{16}$/', $stored['id'] ) ) {
			return null;
		}

		$tiers = array();

		foreach ( array( 1, 2, 3 ) as $tier ) {
			$bps = $stored['tiers'][ $tier ] ?? ( $stored['tiers'][ (string) $tier ] ?? null );

			if ( ! is_int( $bps ) || $bps < 0 || $bps > 10000 ) {
				return null;
			}

			$tiers[ $tier ] = $bps;
		}

		$scope = $stored['scope'] ?? null;
		$ids   = $stored['product_ids'] ?? array();

		if ( ! in_array( $scope, array( self::ALL, self::SELECTED ), true ) || ! is_array( $ids ) ) {
			return null;
		}

		return new self(
			$stored['id'],
			is_string( $stored['name'] ?? null ) ? mb_substr( $stored['name'], 0, self::MAX_NAME ) : '',
			true === ( $stored['enabled'] ?? false ),
			array(
				1 => $tiers[1],
				2 => $tiers[2],
				3 => $tiers[3],
			),
			$scope,
			self::SELECTED === $scope ? self::ids( $ids ) : array(),
			self::day( $stored['starts'] ?? null ),
			self::day( $stored['ends'] ?? null )
		);
	}

	/**
	 * The value to store.
	 *
	 * @return array{id: string, name: string, enabled: bool, tiers: array{1: int, 2: int, 3: int}, scope: string, product_ids: list<int>, starts: string|null, ends: string|null}
	 */
	public function to_stored(): array {
		return array(
			'id'          => $this->id,
			'name'        => $this->name,
			'enabled'     => $this->enabled,
			'tiers'       => $this->tiers,
			'scope'       => $this->scope,
			'product_ids' => $this->product_ids,
			'starts'      => $this->starts,
			'ends'        => $this->ends,
		);
	}

	/**
	 * Whether it gives anything on a day: on, a percent above 0, a product
	 * when only selected ones count, and the day inside its period.
	 *
	 * @param string $today The day (Y-m-d, site time zone).
	 */
	public function active( string $today ): bool {
		return $this->enabled
			&& max( $this->tiers ) > 0
			&& ( self::ALL === $this->scope || array() !== $this->product_ids )
			&& ( null === $this->starts || $this->starts <= $today )
			&& ( null === $this->ends || $today <= $this->ends );
	}

	/**
	 * Whether two campaigns can be active on the same day (both on).
	 *
	 * @param self $other Another campaign.
	 */
	public function overlaps( self $other ): bool {
		return $this->enabled
			&& $other->enabled
			&& ( null === $this->starts || null === $other->ends || $this->starts <= $other->ends )
			&& ( null === $other->starts || null === $this->ends || $other->starts <= $this->ends );
	}

	/**
	 * Whether it covers a parent product of the creator.
	 *
	 * @param int $product_id Parent product.
	 */
	public function covers( int $product_id ): bool {
		return self::ALL === $this->scope || in_array( $product_id, $this->product_ids, true );
	}

	/**
	 * The basis points of a tier (1–3), 0 for none.
	 *
	 * @param int $tier Tier.
	 */
	public function bps( int $tier ): int {
		return in_array( $tier, array( 1, 2, 3 ), true ) ? $this->tiers[ $tier ] : 0;
	}

	/**
	 * A percent in basis points as shown: without decimals when whole,
	 * else with two (16.67 %), in the site's number format.
	 *
	 * @param int $bps Basis points.
	 */
	public static function percent_label( int $bps ): string {
		return number_format_i18n( $bps / 100, 0 === $bps % 100 ? 0 : 2 );
	}

	/**
	 * A stored day, or null.
	 *
	 * @param mixed $value Value.
	 */
	public static function day( mixed $value ): ?string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return null;
		}

		return $value;
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
