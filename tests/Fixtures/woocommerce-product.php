<?php
/**
 * Stand-in for WooCommerce's product CRUD meta handling (WC_Data, WC_Product,
 * WC_Meta_Data, wc_get_product), modelled on WooCommerce 10.3.8. Loaded only
 * in isolated test processes (global classes cannot be undone). Storage lives
 * in MerchBoard\Tests\Unit\Support\WcProductStorage.
 *
 * @package MerchBoard
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals -- Mirrors WooCommerce's global classes and functions.

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
