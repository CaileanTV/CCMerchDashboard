<?php
/**
 * What pricing needs to know about a WooCommerce sellable unit.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Pricing;

use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;

defined( 'ABSPATH' ) || exit;

/**
 * A read-only snapshot of a simple product or a variation as WooCommerce
 * CRUD returns it (`edit` context, no filters): type, post statuses and the
 * three price strings exactly as stored. Merch Dashboard never writes any of
 * them (ADR-049); they are shown for information only:
 * - `regular_price`: the list price;
 * - `sale_price`: a discount WooCommerce manages;
 * - `price`: the active price WooCommerce derives from both (`_price`).
 */
final readonly class PricingUnitRecord {

	/**
	 * Product types a pricing unit can be (simple products and the variations
	 * of variable products). Every other type is unsupported.
	 */
	private const SUPPORTED_TYPES = array( 'simple', 'variation' );

	/**
	 * Constructor.
	 *
	 * @param int    $id            Product or variation id.
	 * @param int    $parent_id     Parent product id (0 for a simple product).
	 * @param string $type          WooCommerce product type.
	 * @param string $status        Post status of the unit.
	 * @param string $parent_status Post status of the parent ('' when a variation's parent is missing; the unit's own status for a simple product).
	 * @param string $regular_price Regular price as stored ('' when none).
	 * @param string $sale_price    Sale price as stored ('' when none).
	 * @param string $price         Active price as stored ('' when none).
	 * @param string $name          Product or variation name (display only; '' when unknown).
	 */
	public function __construct(
		public int $id,
		public int $parent_id,
		public string $type,
		public string $status,
		public string $parent_status,
		public string $regular_price,
		public string $sale_price,
		public string $price,
		public string $name = ''
	) {
	}

	/**
	 * Whether the unit is a variation.
	 */
	public function is_variation(): bool {
		return 'variation' === $this->type;
	}

	/**
	 * The parent product that owns the pricing (ADR-030, ADR-049): the
	 * product itself for a simple product, the parent for a variation.
	 */
	public function product_id(): int {
		return $this->is_variation() ? $this->parent_id : $this->id;
	}

	/**
	 * The variation id, 0 for a simple product.
	 */
	public function variation_id(): int {
		return $this->is_variation() ? $this->id : 0;
	}

	/**
	 * Whether the unit or its parent is in the trash.
	 */
	public function trashed(): bool {
		return 'trash' === $this->status || 'trash' === $this->parent_status;
	}

	/**
	 * Whether pricing can handle the unit: a simple product or a variation
	 * with an existing parent, not an auto-draft. Draft, pending, private and
	 * published units are supported; trash is reported separately.
	 */
	public function supported(): bool {
		return in_array( $this->type, self::SUPPORTED_TYPES, true )
			&& ( ! $this->is_variation() || ( $this->parent_id > 0 && '' !== $this->parent_status ) )
			&& 'auto-draft' !== $this->status
			&& 'auto-draft' !== $this->parent_status;
	}

	/**
	 * The regular price in units, null when empty or not a plain decimal.
	 */
	public function regular_units(): ?int {
		return self::units( $this->regular_price );
	}

	/**
	 * The sale price in units, null when empty or not a plain decimal.
	 */
	public function sale_units(): ?int {
		return self::units( $this->sale_price );
	}

	/**
	 * The active price in units, null when empty or not a plain decimal.
	 */
	public function active_units(): ?int {
		return self::units( $this->price );
	}

	/**
	 * A stored WooCommerce price in units, null when empty or not a plain
	 * decimal with at most four decimals.
	 *
	 * @param string $price Stored price.
	 */
	private static function units( string $price ): ?int {
		$price = trim( $price );

		if ( 1 !== preg_match( '/^\d{1,15}(?:\.\d{1,4})?$/', $price ) ) {
			return null;
		}

		return SalesAmount::parse( $price );
	}
}
