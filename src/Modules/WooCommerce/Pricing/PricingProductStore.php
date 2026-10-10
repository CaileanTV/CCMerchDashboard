<?php
/**
 * WooCommerce access needed by creator pricing.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce reads creator pricing needs, behind an interface so the
 * pricing rules can be tested without WooCommerce. Read only (ADR-049):
 * Merch Dashboard never writes a WooCommerce price, so there is no write
 * method. Implementations read through WooCommerce CRUD only: no SQL on
 * WooCommerce product data and no price filters.
 */
interface PricingProductStore {

	/**
	 * A simple product or variation, or null when no WooCommerce product has
	 * this id. Other product types are returned too (and are unsupported).
	 *
	 * @param int $item_id Product or variation id.
	 */
	public function find( int $item_id ): ?PricingUnitRecord;

	/**
	 * The variation ids of a variable product as WooCommerce lists them
	 * (its children, without trashed variations); empty for other products.
	 *
	 * @param int $product_id Parent product id.
	 * @return list<int>
	 */
	public function variation_ids( int $product_id ): array;

	/**
	 * The store currency (ISO 4217), as WooCommerce reports it.
	 */
	public function currency(): string;

	/**
	 * The store's price decimals, as WooCommerce reports them.
	 */
	public function decimals(): int;
}
