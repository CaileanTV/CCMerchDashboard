<?php
/**
 * What going live does in the shop.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\GoLive;

defined( 'ABSPATH' ) || exit;

/**
 * The shop side of going live (ADR-091, ADR-093): the creator's product
 * category under the parent category and their markup. The WooCommerce
 * module implements it; without WooCommerce nobody can go live.
 */
interface GoLiveShop {

	/**
	 * Whether the shop is there (WooCommerce).
	 */
	public function available(): bool;

	/**
	 * Refuses a markup that cannot be stored, before anything changes.
	 *
	 * @param string $percent Percentage, e.g. "20" or "17,5".
	 * @throws GoLiveException When it is invalid.
	 */
	public function check_markup( string $percent ): void;

	/**
	 * Whether the creator has a product category.
	 *
	 * @param int $creator_id Creator.
	 */
	public function has_category( int $creator_id ): bool;

	/**
	 * Creates the creator's category under the parent and maps it, then sets
	 * the markup. Idempotent: an existing mapping is kept.
	 *
	 * @param int    $creator_id Creator (live already).
	 * @param string $name       Category name.
	 * @param int    $parent_id  Parent `product_cat`.
	 * @param string $percent    Markup percentage.
	 * @return bool Whether the markup was set too (false: the category is there, the markup is not).
	 * @throws GoLiveException When the category cannot be made or mapped.
	 */
	public function provision( int $creator_id, string $name, int $parent_id, string $percent ): bool;
}
