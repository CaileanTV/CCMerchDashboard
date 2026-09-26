<?php
/**
 * Stand-in for WooCommerce's product CRUD meta handling (WC_Data, WC_Product,
 * WC_Meta_Data, wc_get_product), modelled on WooCommerce 10.3.8. Loaded only
 * in isolated test processes (global classes cannot be undone). Storage lives
 * in MerchBoard\Tests\Unit\Support\WcProductStorage.
 *
 * @package MerchBoard
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter -- Mirrors WooCommerce's global classes and functions.

use MerchBoard\Tests\Unit\Support\WcProductStorage;

/**
 * One meta entry: id is null until saved.
 */
class WC_Meta_Data {

	/**
	 * Meta id (null before save).
	 *
	 * @var int|null
	 */
	public $id;

	/**
	 * Meta key.
	 *
	 * @var string
	 */
	public $key;

	/**
	 * Meta value (null marks the entry for deletion).
	 *
	 * @var mixed
	 */
	public $value;

	/**
	 * Constructor.
	 *
	 * @param int|null $id    Meta id.
	 * @param string   $key   Key.
	 * @param mixed    $value Value.
	 */
	public function __construct( $id, $key, $value ) {
		$this->id    = $id;
		$this->key   = $key;
		$this->value = $value;
	}

	/**
	 * Mirrors WC_Meta_Data::get_data().
	 *
	 * @return array{id: int|null, key: string, value: mixed}
	 */
	public function get_data() {
		return array(
			'id'    => $this->id,
			'key'   => $this->key,
			'value' => $this->value,
		);
	}
}

/**
 * Meta handling of WC_Data.
 */
abstract class WC_Data {

	/**
	 * Object id.
	 *
	 * @var int
	 */
	protected $id;

	/**
	 * Meta entries.
	 *
	 * @var list<WC_Meta_Data>
	 */
	protected $meta_data = array();

	/**
	 * Constructor: reads the stored meta rows.
	 *
	 * @param int $id Object id.
	 */
	public function __construct( $id ) {
		$this->id = $id;

		foreach ( WcProductStorage::$meta[ $id ] ?? array() as $row ) {
			$this->meta_data[] = new WC_Meta_Data( $row['id'], $row['key'], $row['value'] );
		}
	}

	/**
	 * Object id.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Entries not marked for deletion.
	 *
	 * @return list<WC_Meta_Data>
	 */
	public function get_meta_data() {
		return array_values( array_filter( $this->meta_data, static fn( $m ) => null !== $m->value ) );
	}

	/**
	 * Adds an entry; with $unique, entries with the exact key are removed first.
	 *
	 * @param string $key    Key.
	 * @param mixed  $value  Value.
	 * @param bool   $unique Replace existing entries.
	 * @return void
	 */
	public function add_meta_data( $key, $value, $unique = false ) {
		if ( $unique ) {
			foreach ( $this->meta_data as $meta ) {
				if ( $meta->key === $key ) {
					$meta->value = null;
				}
			}
		}

		$this->meta_data[] = new WC_Meta_Data( null, $key, $value );
	}

	/**
	 * Marks the entry with this meta id for deletion.
	 *
	 * @param int $mid Meta id.
	 * @return void
	 */
	public function delete_meta_data_by_mid( $mid ) {
		foreach ( $this->meta_data as $meta ) {
			if ( $meta->id === $mid ) {
				$meta->value = null;
			}
		}
	}

	/**
	 * Persists additions and deletions.
	 *
	 * @return void
	 */
	public function save_meta_data() {
		if ( WcProductStorage::$drop_writes ) {
			return;
		}

		$rows = array();

		foreach ( $this->meta_data as $meta ) {
			if ( null === $meta->value ) {
				continue;
			}

			if ( null === $meta->id ) {
				$meta->id = WcProductStorage::$next_meta_id++;
			}

			$rows[] = array(
				'id'    => $meta->id,
				'key'   => $meta->key,
				'value' => (string) $meta->value,
			);
		}

		WcProductStorage::$meta[ $this->id ] = $rows;
	}
}

/**
 * Product facts.
 */
class WC_Product extends WC_Data {

	/**
	 * Parent id.
	 *
	 * @param string $context Context.
	 * @return int
	 */
	public function get_parent_id( $context = 'view' ) {
		return WcProductStorage::$products[ $this->id ]['parent_id'];
	}

	/**
	 * Post status.
	 *
	 * @param string $context Context.
	 * @return string
	 */
	public function get_status( $context = 'view' ) {
		return WcProductStorage::$products[ $this->id ]['status'];
	}

	/**
	 * Product type check.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public function is_type( $type ) {
		return WcProductStorage::$products[ $this->id ]['type'] === $type;
	}

	/**
	 * Name.
	 *
	 * @param string $context Context.
	 * @return string
	 */
	public function get_name( $context = 'view' ) {
		return WcProductStorage::$products[ $this->id ]['name'];
	}

	/**
	 * SKU.
	 *
	 * @param string $context Context.
	 * @return string
	 */
	public function get_sku( $context = 'view' ) {
		return WcProductStorage::$products[ $this->id ]['sku'];
	}
}

/**
 * Data store loader; for 'product' the search of WC_Product_Data_Store_CPT.
 */
class WC_Data_Store {

	/**
	 * Mirrors WC_Data_Store::load().
	 *
	 * @param string $object_type Object type.
	 * @return WC_Data_Store
	 */
	public static function load( $object_type ) {
		return new self();
	}

	/**
	 * Mirrors search_products(): name or SKU contains the term; variations
	 * only when asked for; all statuses when $all_statuses.
	 *
	 * @param string   $term               Term.
	 * @param string   $type               Type.
	 * @param bool     $include_variations Include variations.
	 * @param bool     $all_statuses       All statuses.
	 * @param int|null $limit              Limit.
	 * @return list<int>
	 */
	public function search_products( $term, $type = '', $include_variations = false, $all_statuses = false, $limit = null ) {
		WcProductStorage::$searches[] = array( $term, $type, $include_variations, $all_statuses, $limit );
		$ids                          = array();

		foreach ( WcProductStorage::$products as $id => $product ) {
			$matches  = false !== stripos( $product['name'], $term ) || ( '' !== $product['sku'] && false !== stripos( $product['sku'], $term ) );
			$variant  = 'variation' === $product['type'];
			$statuses = $all_statuses || 'publish' === $product['status'];

			if ( $matches && $statuses && ( $include_variations || ! $variant ) ) {
				$ids[] = $id;
			}
		}

		return null === $limit ? $ids : array_slice( $ids, 0, $limit );
	}
}

/**
 * Mirrors wc_get_products() for the arguments the store uses: status,
 * limit, return ids, meta_key/meta_value compared like MySQL
 * (case-insensitive key) and a product_cat tax_query by term id, which like
 * WP_Tax_Query includes child categories unless include_children is false.
 * Variations are not a queried product type.
 *
 * @param array<string, mixed> $args Arguments.
 * @return list<int>
 */
function wc_get_products( $args ) {
	WcProductStorage::$queries[] = $args;
	$ids                         = array();

	foreach ( WcProductStorage::$products as $id => $product ) {
		if ( 'variation' === $product['type'] || ! in_array( $product['status'], $args['status'], true ) ) {
			continue;
		}

		if ( isset( $args['tax_query'] ) ) {
			$clause = $args['tax_query'][0];
			$terms  = array();

			$children = $clause['include_children'] ?? true;

			foreach ( $clause['terms'] as $term ) {
				$terms = array_merge( $terms, $children ? WcProductStorage::category_with_children( $term ) : array( $term ) );
			}

			if ( 'product_cat' === $clause['taxonomy'] && 'term_id' === $clause['field'] && array() !== array_intersect( $product['categories'], $terms ) ) {
				$ids[] = $id;
			}

			continue;
		}

		foreach ( WcProductStorage::$meta[ $id ] ?? array() as $row ) {
			if ( strtolower( trim( $row['key'] ) ) === strtolower( $args['meta_key'] ) && $row['value'] === $args['meta_value'] ) {
				$ids[] = $id;
				break;
			}
		}
	}

	return array_slice( $ids, 0, $args['limit'] );
}

/**
 * Records cache group invalidations.
 */
class WC_Cache_Helper {

	/**
	 * Mirrors WC_Cache_Helper::invalidate_cache_group().
	 *
	 * @param string $group Cache group.
	 * @return void
	 */
	public static function invalidate_cache_group( $group ) {
		WcProductStorage::$invalidated_groups[] = $group;
	}
}

/**
 * Loads a product.
 *
 * @param mixed $the_product Product id.
 * @return WC_Product|false
 */
function wc_get_product( $the_product = false ) {
	return isset( WcProductStorage::$products[ (int) $the_product ] ) ? new WC_Product( (int) $the_product ) : false;
}

/**
 * Records transient deletions.
 *
 * @param int $post_id Product id.
 * @return void
 */
function wc_delete_product_transients( $post_id = 0 ) {
	$GLOBALS['merchboard_test_deleted_transients'][] = $post_id;
}
