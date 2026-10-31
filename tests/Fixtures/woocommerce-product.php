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
	 * @param int $id Object id (0: a new object, stored at save()).
	 */
	public function __construct( $id = 0 ) {
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
	 * The first value of a key ('' without one).
	 *
	 * @param string $key     Key.
	 * @param bool   $single  Single value.
	 * @param string $context Context.
	 * @return mixed
	 */
	public function get_meta( $key = '', $single = true, $context = 'view' ) {
		foreach ( $this->get_meta_data() as $meta ) {
			if ( $meta->key === $key ) {
				return $meta->value;
			}
		}

		return '';
	}

	/**
	 * Replaces a key's value.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function update_meta_data( $key, $value ) {
		$this->add_meta_data( $key, $value, true );
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
	 * Direct product_cat term ids.
	 *
	 * @param string $context Context.
	 * @return list<int>
	 */
	public function get_category_ids( $context = 'view' ) {
		return WcProductStorage::$products[ $this->id ]['categories'] ?? array();
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

	/**
	 * Active price ('' without one; a filter may return a float).
	 *
	 * @param string $context Context.
	 * @return string|float
	 */
	public function get_price( $context = 'view' ) {
		return WcProductStorage::$products[ $this->id ]['price'] ?? '';
	}

	/**
	 * Regular price.
	 *
	 * @param string $context Context.
	 * @return string
	 */
	public function get_regular_price( $context = 'view' ) {
		return 0 === $this->id ? '' : ( WcProductStorage::$products[ $this->id ]['regular_price'] ?? '' );
	}

	/**
	 * Stock status.
	 *
	 * @param string $context Context.
	 * @return string
	 */
	public function get_stock_status( $context = 'view' ) {
		return WcProductStorage::$products[ $this->id ]['stock_status'] ?? 'instock';
	}

	/**
	 * Image attachment id (WooCommerce returns it as a string).
	 *
	 * @param string $context Context.
	 * @return string
	 */
	public function get_image_id( $context = 'view' ) {
		return (string) ( WcProductStorage::$products[ $this->id ]['image_id'] ?? '' );
	}

	/**
	 * Unsaved property changes.
	 *
	 * @var array<string, mixed>
	 */
	private $changes = array();

	/**
	 * Product type.
	 *
	 * @return string
	 */
	public function get_type() {
		return WcProductStorage::$products[ $this->id ]['type'];
	}

	/**
	 * Sale price ('' without one).
	 *
	 * @param string $context Context.
	 * @return string
	 */
	public function get_sale_price( $context = 'view' ) {
		return WcProductStorage::$products[ $this->id ]['sale_price'] ?? '';
	}

	/**
	 * Records a property change (the designer writer, step 30.10).
	 *
	 * @param string $property Property.
	 * @param mixed  $value    Value.
	 * @return void
	 */
	private function change( $property, $value ) {
		WcProductStorage::$setters[] = array( $this->id, $property, $value );
		$this->changes[ $property ]  = $value;
	}

	/**
	 * Sets the name.
	 *
	 * @param string $name Name.
	 * @return void
	 */
	public function set_name( $name ) {
		$this->change( 'name', $name );
	}

	/**
	 * Sets the description.
	 *
	 * @param string $description Description.
	 * @return void
	 */
	public function set_description( $description ) {
		$this->change( 'description', $description );
	}

	/**
	 * Sets the categories.
	 *
	 * @param list<int> $ids Term ids.
	 * @return void
	 */
	public function set_category_ids( $ids ) {
		$this->change( 'categories', $ids );
	}

	/**
	 * Sets the image.
	 *
	 * @param int $id Attachment id.
	 * @return void
	 */
	public function set_image_id( $id = '' ) {
		$this->change( 'image_id', $id );
	}

	/**
	 * Sets the gallery.
	 *
	 * @param list<int> $ids Attachment ids.
	 * @return void
	 */
	public function set_gallery_image_ids( $ids ) {
		$this->change( 'gallery', $ids );
	}

	/**
	 * Sets the attributes.
	 *
	 * @param array<int|string, mixed> $attributes Attributes.
	 * @return void
	 */
	public function set_attributes( $attributes ) {
		$this->change( 'attributes', $attributes );
	}

	/**
	 * The attributes.
	 *
	 * @param string $context Context.
	 * @return array<int|string, mixed>
	 */
	public function get_attributes( $context = 'view' ) {
		return $this->changes['attributes'] ?? ( WcProductStorage::$products[ $this->id ]['attributes'] ?? array() );
	}

	/**
	 * Sets the status.
	 *
	 * @param string $status Status.
	 * @return void
	 */
	public function set_status( $status ) {
		$this->change( 'status', $status );
	}

	/**
	 * Sets the parent.
	 *
	 * @param int $id Parent id.
	 * @return void
	 */
	public function set_parent_id( $id ) {
		$this->change( 'parent_id', $id );
	}

	/**
	 * Records the setter call and the change.
	 *
	 * @param string $price Regular price.
	 * @return void
	 */
	public function set_regular_price( $price ) {
		WcProductStorage::$setters[]    = array( $this->id, 'regular_price', $price );
		$this->changes['regular_price'] = $price;
	}

	/**
	 * Child ids: variations whose parent is this product, not trashed.
	 *
	 * @return list<int>
	 */
	public function get_children() {
		$ids = array();

		foreach ( WcProductStorage::$products as $id => $product ) {
			if ( 'variation' === $product['type'] && $product['parent_id'] === $this->id && 'trash' !== $product['status'] ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Records the setter call (Merch Dashboard must never call it).
	 *
	 * @param string $price Sale price.
	 * @return void
	 */
	public function set_sale_price( $price ) {
		WcProductStorage::$setters[] = array( $this->id, 'sale_price', $price );
	}

	/**
	 * Saves the changes like WooCommerce 11.1.2 for a simple product or a
	 * variation (WC_Product_Data_Store_CPT::handle_updated_props()): a sale
	 * price not below the new regular price is removed, and the active price
	 * is the sale price when on sale, else the regular price. Returns 0 when
	 * WcProductStorage::$fail_save is set.
	 *
	 * @return int
	 */
	public function save() {
		if ( WcProductStorage::$fail_save ) {
			return 0;
		}

		if ( 0 === $this->id ) {
			$this->id = array() === WcProductStorage::$products ? 1 : max( array_keys( WcProductStorage::$products ) ) + 1;

			WcProductStorage::$products[ $this->id ] = array(
				'type'       => $this instanceof WC_Product_Variation ? 'variation' : ( $this instanceof WC_Product_Variable ? 'variable' : 'simple' ),
				'parent_id'  => 0,
				'status'     => 'draft',
				'name'       => '',
				'sku'        => '',
				'categories' => array(),
			);

			foreach ( WcProductStorage::$setters as $index => $setter ) {
				if ( 0 === $setter[0] ) {
					WcProductStorage::$setters[ $index ][0] = $this->id;
				}
			}
		}

		$product = WcProductStorage::$products[ $this->id ];

		// As WooCommerce does: the class saved is the product's type.
		if ( $this instanceof WC_Product_Simple || $this instanceof WC_Product_Variable ) {
			$product['type'] = $this instanceof WC_Product_Simple ? 'simple' : 'variable';
		}

		foreach ( array( 'name', 'description', 'categories', 'image_id', 'gallery', 'attributes', 'status', 'parent_id' ) as $property ) {
			if ( array_key_exists( $property, $this->changes ) ) {
				$product[ $property ] = $this->changes[ $property ];
			}
		}

		WcProductStorage::$saved[] = $this->id;

		if ( array_key_exists( 'regular_price', $this->changes ) ) {
			$product['regular_price'] = $this->changes['regular_price'];
			$sale                     = $product['sale_price'] ?? '';

			if ( '' !== $sale && $sale >= $product['regular_price'] ) {
				$sale = '';
			}

			$product['sale_price'] = $sale;
			$product['price']      = '' !== $sale && $product['regular_price'] > $sale ? $sale : $product['regular_price'];
		}

		WcProductStorage::$products[ $this->id ] = $product;
		$this->changes                           = array();
		// WooCommerce saves the meta data with the product.
		$this->save_meta_data();

		return $this->id;
	}
}

/**
 * A product attribute: its properties as set.
 */
class WC_Product_Attribute {

	/**
	 * Properties.
	 *
	 * @var array<string, mixed>
	 */
	public $data = array();

	/**
	 * Records any setter (set_id, set_name, set_options, …).
	 *
	 * @param string       $name      Method.
	 * @param array<mixed> $arguments Arguments.
	 * @return void
	 */
	public function __call( $name, $arguments ) {
		if ( str_starts_with( $name, 'set_' ) ) {
			$this->data[ substr( $name, 4 ) ] = $arguments[0] ?? null;
		}
	}
}

/**
 * Variable product: the prices of its visible variations.
 */
class WC_Product_Variable extends WC_Product {

	/**
	 * Variation prices by variation id; records $for_display.
	 *
	 * @param bool $for_display Whether to adjust for display.
	 * @return array<string, array<int, string|float>>
	 */
	public function get_variation_prices( $for_display = false ) {
		WcProductStorage::$variation_price_calls[] = $for_display;

		return WcProductStorage::$products[ $this->id ]['variation_prices'] ?? array(
			'price'         => array(),
			'regular_price' => array(),
			'sale_price'    => array(),
		);
	}

	/**
	 * Records the price sync of a parent.
	 *
	 * @param WC_Product|int $product Product or id.
	 * @param bool           $save    Whether to save.
	 * @return WC_Product|int
	 */
	public static function sync( $product, $save = true ) {
		WcProductStorage::$synced[] = is_int( $product ) ? $product : $product->get_id();

		return $product;
	}
}

/**
 * A variation.
 */
class WC_Product_Variation extends WC_Product {
}

/**
 * A simple product.
 */
class WC_Product_Simple extends WC_Product {
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

	return array_slice( $ids, (int) ( $args['offset'] ?? 0 ), $args['limit'] );
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
	$id                          = (int) $the_product;
	WcProductStorage::$lookups[] = $id;

	if ( ! isset( WcProductStorage::$products[ $id ] ) ) {
		return false;
	}

	return match ( WcProductStorage::$products[ $id ]['type'] ) {
		'variable'  => new WC_Product_Variable( $id ),
		'variation' => new WC_Product_Variation( $id ),
		'simple'    => new WC_Product_Simple( $id ),
		default     => new WC_Product( $id ),
	};
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
