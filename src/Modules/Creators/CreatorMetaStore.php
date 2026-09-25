<?php
/**
 * Creator meta storage.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators;

use InvalidArgumentException;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * `merchboard_creatormeta` through WordPress' metadata API (meta type
 * `merchboard_creator`, docs/DATABASE.md), so WordPress' meta cache works.
 * Only whitelisted keys can be read or written; one value per key.
 */
final class CreatorMetaStore {

	/**
	 * Meta type (object column `merchboard_creator_id`).
	 */
	public const META_TYPE = 'merchboard_creator';

	public const BIO          = 'bio';
	public const SOCIAL_LINKS = 'social_links';

	/**
	 * Whitelisted meta keys.
	 */
	private const KEYS = array( self::BIO, self::SOCIAL_LINKS );

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public function __construct( private wpdb $wpdb ) {
		self::register_table( $wpdb );
	}

	/**
	 * Tells the metadata API where the table is (`$wpdb->merchboard_creatormeta`).
	 *
	 * @param wpdb $wpdb WordPress database object.
	 */
	public static function register_table( wpdb $wpdb ): void {
		// wpdb allows (and WordPress itself uses) table-name properties for meta types.
		$wpdb->__set( Tables::CREATOR_META, Tables::name( $wpdb, Tables::CREATOR_META ) );
	}

	/**
	 * A meta value, or null when not set.
	 *
	 * @param int    $creator_id Creator id.
	 * @param string $key        Whitelisted key.
	 */
	public function get( int $creator_id, string $key ): mixed {
		self::assert_key( $key );

		$values = get_metadata( self::META_TYPE, $creator_id, $key, false );

		return is_array( $values ) && array() !== $values ? $values[0] : null;
	}

	/**
	 * Stores a value; an empty value ('' or empty array) deletes the key.
	 *
	 * @param int    $creator_id Creator id.
	 * @param string $key        Whitelisted key.
	 * @param mixed  $value      Value.
	 * @throws DatabaseException When the value could not be stored.
	 */
	public function set( int $creator_id, string $key, mixed $value ): void {
		self::assert_key( $key );

		if ( '' === $value || array() === $value ) {
			delete_metadata( self::META_TYPE, $creator_id, $key );
			$this->assert_stored( $creator_id, $key, null );
			return;
		}

		// The metadata API unslashes its input; slash so backslashes survive.
		update_metadata( self::META_TYPE, $creator_id, $key, wp_slash( $value ) );
		$this->assert_stored( $creator_id, $key, $value );
	}

	/**
	 * Verifies a write by reading it back from the database (update_metadata()
	 * returns false both for failures and for unchanged values).
	 *
	 * @param int    $creator_id Creator id.
	 * @param string $key        Key.
	 * @param mixed  $expected   Expected value, null for "not set".
	 * @throws DatabaseException When the stored value differs.
	 */
	private function assert_stored( int $creator_id, string $key, mixed $expected ): void {
		wp_cache_delete( $creator_id, self::META_TYPE . '_meta' );

		if ( $this->get( $creator_id, $key ) !== $expected ) {
			throw new DatabaseException( 'Could not save creator profile.' );
		}
	}

	/**
	 * Only whitelisted keys.
	 *
	 * @param string $key Key.
	 * @throws InvalidArgumentException For any other key.
	 */
	private static function assert_key( string $key ): void {
		if ( ! in_array( $key, self::KEYS, true ) ) {
			throw new InvalidArgumentException( 'Unknown creator meta key.' );
		}
	}
}
