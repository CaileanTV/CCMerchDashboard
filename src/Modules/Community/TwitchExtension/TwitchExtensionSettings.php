<?php
/**
 * A creator's Twitch Extension panel.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\TwitchExtension;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the creator's panel is on, and the chosen products in order
 * (ADR-112 pkt. 4). No products chosen: the panel shows the newest.
 */
final class TwitchExtensionSettings {

	/**
	 * The most products in the panel.
	 */
	public const MAX_PRODUCTS = 12;

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param bool  $enabled     Whether the panel shows the products.
	 * @param int[] $product_ids Chosen parent products, in order.
	 */
	public function __construct( public bool $enabled = false, public array $product_ids = array() ) {
	}

	/**
	 * From creator meta (anything unreadable is off and empty).
	 *
	 * @param mixed $stored Stored value.
	 */
	public static function from_stored( mixed $stored ): self {
		$stored = is_array( $stored ) ? $stored : array();
		$ids    = is_array( $stored['product_ids'] ?? null ) ? $stored['product_ids'] : array();
		$ids    = array_values( array_unique( array_filter( array_map( static fn( mixed $id ): int => is_int( $id ) ? $id : 0, $ids ), static fn( int $id ): bool => $id > 0 ) ) );

		return new self( true === ( $stored['enabled'] ?? false ), array_slice( $ids, 0, self::MAX_PRODUCTS ) );
	}

	/**
	 * For creator meta.
	 *
	 * @return array{enabled: bool, product_ids: list<int>}
	 */
	public function to_stored(): array {
		return array(
			'enabled'     => $this->enabled,
			'product_ids' => $this->product_ids,
		);
	}
}
