<?php
/**
 * The shop side of going live without WooCommerce.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\GoLive;

defined( 'ABSPATH' ) || exit;

/**
 * Without WooCommerce there is no shop to go live in.
 */
final class NullGoLiveShop implements GoLiveShop {

	/**
	 * Not available.
	 */
	public function available(): bool {
		return false;
	}

	/**
	 * Refuses every markup.
	 *
	 * @param string $percent Percentage.
	 * @throws GoLiveException Always.
	 */
	public function check_markup( string $percent ): void {
		throw new GoLiveException( GoLiveException::SHOP_UNAVAILABLE );
	}

	/**
	 * No categories.
	 *
	 * @param int $creator_id Creator.
	 */
	public function has_category( int $creator_id ): bool {
		return false;
	}

	/**
	 * Refuses.
	 *
	 * @param int    $creator_id Creator.
	 * @param string $name       Category name.
	 * @param int    $parent_id  Parent.
	 * @param string $percent    Markup.
	 * @throws GoLiveException Always.
	 */
	public function provision( int $creator_id, string $name, int $parent_id, string $percent ): bool {
		throw new GoLiveException( GoLiveException::SHOP_UNAVAILABLE );
	}
}
