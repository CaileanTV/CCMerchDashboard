<?php
/**
 * Stand-in for WooCommerce' order CRUD (only what the sales ledger reads and
 * writes). Loaded only in isolated test processes (global classes cannot be
 * undone); tests fill MerchBoard\Tests\Unit\Support\WcOrderStorage and
 * wc_get_order() builds fresh objects from it. The `view` context returns
 * different ("filtered") values than `edit`, so tests prove the plugin reads
 * with `edit`. `get_refunds()` throws: the plugin must query refunds instead.
 *
 * @package MerchBoard
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing -- Mirrors WooCommerce's global classes and functions.

use MerchBoard\Tests\Unit\Support\WcOrderStorage;

/**
 * WooCommerce date.
 */
class WC_DateTime extends DateTime {
}

/**
 * A product line (of an order or a refund).
 */
class WC_Order_Item_Product {

	private $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function get_id() {
		return $this->data['id'];
	}

	public function get_product_id( $context = 'view' ) {
		return 'edit' === $context ? $this->data['product_id'] : 999;
	}

	public function get_variation_id( $context = 'view' ) {
		return 'edit' === $context ? $this->data['variation_id'] : 999;
	}

	public function get_name( $context = 'view' ) {
		return 'edit' === $context ? $this->data['name'] : 'Filtered';
	}

	public function get_quantity( $context = 'view' ) {
		return 'edit' === $context ? $this->data['quantity'] : 999;
	}

	public function get_total( $context = 'view' ) {
		return 'edit' === $context ? $this->data['total'] : 999.99;
	}

	public function get_total_tax( $context = 'view' ) {
		return 'edit' === $context ? ( $this->data['total_tax'] ?? '0' ) : 999.99;
	}

	public function get_meta( $key = '', $single = true, $context = 'view' ) {
		return 'edit' === $context ? ( $this->data['meta'][ $key ] ?? '' ) : '999';
	}
}

/**
 * Common order data.
 */
abstract class WC_Abstract_Order {

	protected $id;
	protected $data;
	protected $meta;

	public function __construct( int $id, array $data ) {
		$this->id   = $id;
		$this->data = $data;
		$this->meta = WcOrderStorage::$meta[ $id ] ?? array();
	}

	public function get_id() {
		return $this->id;
	}

	public function get_type() {
		return $this->data['type'];
	}

	public function get_status( $context = 'view' ) {
		return 'edit' === $context ? $this->data['status'] : 'filtered';
	}

	public function get_currency( $context = 'view' ) {
		return 'edit' === $context ? $this->data['currency'] : 'XXX';
	}

	public function get_date_created( $context = 'view' ) {
		return new WC_DateTime( $this->data['created'] );
	}

	public function get_items( $types = 'line_item' ) {
		return 'line_item' === $types ? array_map( static fn( $item ) => new WC_Order_Item_Product( $item ), $this->data['items'] ) : array();
	}

	public function get_refunds() {
		throw new LogicException( 'get_refunds() must not be used: its list cache can be stale.' );
	}

	public function get_meta( $key = '', $single = true, $context = 'view' ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( $key, $value, $meta_id = 0 ) {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}

	public function save_meta_data() {
		// Whether HPOS' save after a meta change is suppressed right now.
		WcOrderStorage::$saves[] = false !== has_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', '__return_false' );

		if ( WcOrderStorage::$fail_save ) {
			throw new RuntimeException( 'Save failed.' );
		}

		WcOrderStorage::$meta[ $this->id ] = $this->meta;
	}
}

/**
 * Order.
 */
class WC_Order extends WC_Abstract_Order {

	public function get_date_paid( $context = 'view' ) {
		return null === $this->data['paid'] ? null : new WC_DateTime( $this->data['paid'] );
	}

	/**
	 * Order number: the stored `number` (a sequential-number plugin), else the id.
	 *
	 * @return string|int
	 */
	public function get_order_number() {
		return $this->data['number'] ?? $this->id;
	}

	public function get_billing_first_name( $context = 'view' ) {
		return 'edit' === $context ? ( $this->data['billing_first_name'] ?? '' ) : 'Filtered';
	}
}

/**
 * Refund.
 */
class WC_Order_Refund extends WC_Abstract_Order {
}

function wc_get_order( $the_order = false ) {
	WcOrderStorage::$lookups[] = $the_order;
	$data                      = WcOrderStorage::$orders[ $the_order ] ?? null;

	if ( null === $data ) {
		return false;
	}

	return 'shop_order_refund' === $data['type'] ? new WC_Order_Refund( $the_order, $data ) : new WC_Order( $the_order, $data );
}

function wc_get_orders( $args ) {
	WcOrderStorage::$queries[] = $args;
	$ids                       = array();

	foreach ( WcOrderStorage::$orders as $id => $data ) {
		if ( ( $args['type'] ?? '' ) !== $data['type'] ) {
			continue;
		}

		// Orders that have a meta key (`meta_key` + `meta_compare` EXISTS). Like
		// WooCommerce 10.3.8's legacy storage, `meta_query` is ignored.
		if ( isset( $args['meta_key'] ) && 'EXISTS' === ( $args['meta_compare'] ?? '' ) && ! array_key_exists( $args['meta_key'], WcOrderStorage::$meta[ $id ] ?? array() ) ) {
			continue;
		}

		if ( isset( $args['parent'] ) && $args['parent'] !== $data['parent'] ) {
			continue;
		}

		$ids[] = $id;
	}

	$limit = (int) ( $args['limit'] ?? 10 );

	return array_map( 'wc_get_order', -1 === $limit ? $ids : array_slice( $ids, 0, $limit ) );
}
