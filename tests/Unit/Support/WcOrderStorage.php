<?php
/**
 * Storage behind the WooCommerce order stand-in (tests/Fixtures/woocommerce-order.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

/**
 * Orders and refunds as plain data (the stand-in builds fresh objects from it
 * on every wc_get_order(), like a database), saved order meta, the queries
 * made, and what `save_meta_data()` observed.
 */
final class WcOrderStorage {

	/**
	 * Orders and refunds by id.
	 *
	 * @var array<int, array{type: string, status: string, currency: string, created: string, paid: string|null, parent: int, items: list<array<string, mixed>>, number?: string|int, billing?: array<string, string>}>
	 */
	public static array $orders = array();

	/**
	 * Saved order meta by order id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $meta = array();

	/**
	 * Arguments of every wc_get_orders() call.
	 *
	 * @var list<array<string, mixed>>
	 */
	public static array $queries = array();

	/**
	 * For every save_meta_data(): whether HPOS' save after a meta change was suppressed.
	 *
	 * @var list<bool>
	 */
	public static array $saves = array();

	/**
	 * Makes save_meta_data() throw.
	 *
	 * @var bool
	 */
	public static bool $fail_save = false;

	/**
	 * The argument of every wc_get_order() call.
	 *
	 * @var list<mixed>
	 */
	public static array $lookups = array();

	/**
	 * Resets everything.
	 */
	public static function reset(): void {
		self::$orders    = array();
		self::$meta      = array();
		self::$queries   = array();
		self::$saves     = array();
		self::$fail_save = false;
		self::$lookups   = array();
	}

	/**
	 * Adds an order in DKK, created 2026-09-01 10:00 Copenhagen time.
	 *
	 * @param int                        $id     Order id.
	 * @param string                     $status Status (without `wc-`).
	 * @param list<array<string, mixed>> $items  Lines from item().
	 * @param string|null                $paid   Payment date (ISO 8601 with offset), or null.
	 * @param string                     $type   Order type.
	 */
	public static function add_order( int $id, string $status, array $items = array(), ?string $paid = null, string $type = 'shop_order' ): void {
		self::$orders[ $id ] = array(
			'type'     => $type,
			'status'   => $status,
			'currency' => 'DKK',
			'created'  => '2026-09-01T10:00:00+02:00',
			'paid'     => $paid,
			'parent'   => 0,
			'items'    => $items,
		);
	}

	/**
	 * Adds a refund created 2026-09-02 09:00 UTC.
	 *
	 * @param int                        $id       Refund id.
	 * @param int                        $order_id Parent order id.
	 * @param list<array<string, mixed>> $items    Lines from item().
	 */
	public static function add_refund( int $id, int $order_id, array $items = array() ): void {
		self::$orders[ $id ] = array(
			'type'     => 'shop_order_refund',
			'status'   => 'completed',
			'currency' => 'DKK',
			'created'  => '2026-09-02T09:00:00+00:00',
			'paid'     => null,
			'parent'   => $order_id,
			'items'    => $items,
		);
	}

	/**
	 * A product line as WooCommerce stores it.
	 *
	 * @param int                  $id           Item id.
	 * @param int                  $product_id   Parent product.
	 * @param int                  $variation_id Variation or 0.
	 * @param string               $name         Name.
	 * @param mixed                $quantity     Quantity as WooCommerce returns it.
	 * @param mixed                $total        Total as WooCommerce returns it.
	 * @param array<string, mixed> $meta         Item meta.
	 * @return array<string, mixed>
	 */
	public static function item( int $id, int $product_id, int $variation_id, string $name, mixed $quantity, mixed $total, array $meta = array() ): array {
		return array(
			'id'           => $id,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'name'         => $name,
			'quantity'     => $quantity,
			'total'        => $total,
			'meta'         => $meta,
		);
	}
}
