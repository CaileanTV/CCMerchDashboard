<?php
/**
 * Writes design collections in the shop.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Collections;

use Closure;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\NamedLock;
use MerchBoard\Core\Lifecycle\Uninstaller;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-120 pkt. 7 and 9: the one writer of what the collection plugin stores,
 * and only while that plugin is active, as it is the plugin that hides and
 * restores the designs.
 *
 * A draft carries the request's token until the request has recorded it, so
 * approving again finds it instead of making a second. An addition appends
 * rows and changes none. Nothing here runs in a database transaction:
 * WooCommerce' saves commit an outer one.
 */
class CollectionWriter {

	/**
	 * The unprotected meta of a draft until its request has recorded it.
	 */
	public const TOKEN_KEY = Uninstaller::COLLECTION_TOKEN_META;

	/**
	 * The plugin's function that hides and restores a collection's designs.
	 */
	private const SYNC = 'ccmds_sync_group';

	/**
	 * Every status a draft can have been given since; 'any' leaves out the trash.
	 */
	private const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' );

	/**
	 * Seconds an open editor holds a collection (WordPress' own window).
	 */
	private const EDIT_WINDOW = 150;

	/**
	 * Seconds to wait for a collection's lock.
	 */
	private const LOCK_TIMEOUT = 5;

	/**
	 * Saves a new draft and gives its id.
	 *
	 * @var Closure(array{name: string, members: list<array{id: int, label: string}>, categories: list<int>, tags: list<int>, image: int, token: string}): int
	 */
	private Closure $create;

	/**
	 * Has the plugin hide a collection's designs; false without the plugin's function.
	 *
	 * @var Closure(int): bool
	 */
	private Closure $sync;

	/**
	 * Constructor.
	 *
	 * @phpstan-param (Closure(array{name: string, members: list<array{id: int, label: string}>, categories: list<int>, tags: list<int>, image: int, token: string}): int)|null $create
	 * @phpstan-param (Closure(int): bool)|null $sync
	 *
	 * @param DesignCollections $collections The reader.
	 * @param NamedLock         $lock        Named locks.
	 * @param Closure|null      $create      Saves a new draft; WooCommerce' CRUD by default.
	 * @param Closure|null      $sync        The plugin's sync by default.
	 */
	public function __construct( private DesignCollections $collections, private NamedLock $lock, ?Closure $create = null, ?Closure $sync = null ) {
		$this->create = $create ?? self::save_draft( ... );
		$this->sync   = $sync ?? static function ( int $collection_id ): bool {
			$sync = self::sync_function();

			if ( ! is_callable( $sync ) ) {
				return false;
			}

			$sync( $collection_id );

			return true;
		};
	}

	/**
	 * The draft of a new collection's request: the one made before, or a new
	 * one with the designs, the name, their categories and tags and the first
	 * design's image.
	 *
	 * @param CollectionRequest $request An approved request of a new collection without a collection.
	 * @return int The draft.
	 * @throws DatabaseException When the draft could not be made.
	 */
	public function draft( CollectionRequest $request ): int {
		$this->assert_active();

		$found = $this->existing( $request );

		if ( null !== $found ) {
			$this->complete( $found, $request );

			return $found;
		}

		$ids     = $request->product_ids();
		$members = array_map( self::member( ... ), $ids );
		$id      = ( $this->create )(
			array(
				'name'       => $request->name,
				'members'    => $members,
				'categories' => self::terms( $ids, 'product_cat' ),
				'tags'       => self::terms( $ids, 'product_tag' ),
				'image'      => array() === $ids ? 0 : (int) get_post_thumbnail_id( $ids[0] ),
				'token'      => $request->token,
			)
		);

		if ( $id < 1 ) {
			throw new DatabaseException( 'Could not create the collection draft.' );
		}

		wp_cache_delete( $id, 'post_meta' );

		// WooCommerce' saves report no errors: read back what makes it a collection.
		if ( ! $this->collections->is_collection( $id ) || $this->collections->members( $id ) !== $members ) {
			throw new DatabaseException( 'The collection draft was not stored.', array( 'product_id' => $id ) );
		}

		return $id;
	}

	/**
	 * The product a request's draft became, whatever its status now, or null.
	 *
	 * @param CollectionRequest $request The request.
	 * @throws CollectionRequestException When several products carry the token.
	 */
	public function existing( CollectionRequest $request ): ?int {
		$found = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => self::STATUSES,
				'fields'           => 'ids',
				'numberposts'      => 2,
				'suppress_filters' => true,
				'meta_key'         => self::TOKEN_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Once per approved collection.
				'meta_value'       => $request->token, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Once per approved collection.
			)
		);

		if ( count( $found ) > 1 ) {
			// A copy of the draft: which one is meant cannot be told.
			throw new CollectionRequestException( CollectionRequestException::DRAFT_UNCLEAR );
		}

		return 1 === count( $found ) ? (int) $found[0] : null;
	}

	/**
	 * What is inserted for a draft: no text, as the plugin's product class
	 * answers with a design's own while the collection's is empty and
	 * WooCommerce stores that answer; and the token from the first write, so
	 * a save that stops halfway leaves a draft that is found again.
	 *
	 * @param array<mixed> $data  What WooCommerce would insert.
	 * @param string       $token The request's token.
	 * @return array<mixed>
	 */
	public static function insert_data( array $data, string $token ): array {
		$meta = is_array( $data['meta_input'] ?? null ) ? $data['meta_input'] : array();

		$data['post_content'] = '';
		$data['post_excerpt'] = '';
		$data['meta_input']   = array_merge( $meta, array( self::TOKEN_KEY => $token ) );

		return $data;
	}

	/**
	 * Finishes a draft whose save stopped halfway. One that lists designs is
	 * the administrator's and is left as it is.
	 *
	 * @param int               $id      The draft.
	 * @param CollectionRequest $request Its request.
	 * @throws DatabaseException When it is still no collection.
	 */
	private function complete( int $id, CollectionRequest $request ): void {
		wp_cache_delete( $id, 'post_meta' );

		if ( array() === $this->collections->members( $id ) ) {
			$ids = $request->product_ids();

			update_post_meta( $id, DesignCollections::MEMBERS_KEY, wp_slash( array_map( self::member( ... ), $ids ) ) );

			if ( taxonomy_exists( 'product_type' ) ) {
				wp_set_object_terms( $id, DesignCollections::TYPE, 'product_type' );
			}

			foreach ( array( 'product_cat', 'product_tag' ) as $taxonomy ) {
				$terms = self::terms( $ids, $taxonomy );

				if ( array() !== $terms ) {
					wp_set_object_terms( $id, $terms, $taxonomy );
				}
			}

			$image = array() === $ids ? 0 : (int) get_post_thumbnail_id( $ids[0] );

			if ( $image > 0 ) {
				set_post_thumbnail( $id, $image );
			}

			wp_cache_delete( $id, 'post_meta' );
		}

		if ( ! $this->collections->is_collection( $id ) ) {
			throw new DatabaseException( 'The collection draft was not stored.', array( 'product_id' => $id ) );
		}
	}

	/**
	 * Takes the token off a draft its request has recorded: a copy of the
	 * product must never be taken for it.
	 *
	 * @param int $collection_id The draft.
	 */
	public function recorded( int $collection_id ): void {
		delete_post_meta( $collection_id, self::TOKEN_KEY );
	}

	/**
	 * Appends designs to a collection. A published one hides them at once, so
	 * that takes $publish (the administrator's second click); without it a
	 * published collection is left as it is.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param int   $collection_id The collection.
	 * @param int[] $product_ids   Products.
	 * @param bool  $publish       Whether a published collection may be changed.
	 * @return bool Whether the designs are in the collection now.
	 * @throws CollectionRequestException When the plugin is inactive, the collection is being edited or busy.
	 */
	public function add( int $collection_id, array $product_ids, bool $publish ): bool {
		$this->assert_active();

		// Boxed, as null is also how a lock that was not had answers.
		$added = $this->lock->run(
			'merchboard_collection_' . $collection_id,
			self::LOCK_TIMEOUT,
			fn(): array => array( $this->append( $collection_id, $product_ids, $publish ) )
		);

		if ( null === $added ) {
			throw new CollectionRequestException( CollectionRequestException::BUSY );
		}

		return $added[0];
	}

	/**
	 * Appends under the collection's lock, reading what is there first.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param int   $collection_id The collection.
	 * @param int[] $product_ids   Products.
	 * @param bool  $publish       Whether a published collection may be changed.
	 * @throws CollectionRequestException When it cannot be done.
	 * @throws DatabaseException When the rows were not stored.
	 */
	private function append( int $collection_id, array $product_ids, bool $publish ): bool {
		clean_post_cache( $collection_id );
		wp_cache_delete( $collection_id, 'post_meta' );

		$status = get_post_status( $collection_id );

		if ( false === $status || in_array( $status, array( 'trash', 'auto-draft' ), true ) || ! $this->collections->is_collection( $collection_id ) ) {
			throw new CollectionRequestException( CollectionRequestException::COLLECTION_UNKNOWN );
		}

		$missing = array_values( array_diff( $product_ids, array_column( $this->collections->members( $collection_id ), 'id' ) ) );

		if ( 'publish' === $status && ! $publish ) {
			return array() === $missing;
		}

		if ( array() !== $missing ) {
			// An open editor would save its own rows over these.
			if ( self::being_edited( $collection_id ) ) {
				throw new CollectionRequestException( CollectionRequestException::EDITING );
			}

			$stored = get_post_meta( $collection_id, DesignCollections::MEMBERS_KEY, true );
			$rows   = array_merge( is_array( $stored ) ? array_values( $stored ) : array(), array_map( self::member( ... ), $missing ) );

			update_post_meta( $collection_id, DesignCollections::MEMBERS_KEY, wp_slash( $rows ) );
			wp_cache_delete( $collection_id, 'post_meta' );

			if ( array() !== array_diff( $missing, array_column( $this->collections->members( $collection_id ), 'id' ) ) ) {
				throw new DatabaseException( 'The collection\'s designs were not stored.', array( 'product_id' => $collection_id ) );
			}
		}

		// Also on a repeated click: the rows may be stored and the designs not yet hidden.
		if ( 'publish' === $status && ! ( $this->sync )( $collection_id ) ) {
			throw new CollectionRequestException( CollectionRequestException::PLUGIN_INACTIVE );
		}

		return true;
	}

	/**
	 * Refuses while the plugin is inactive.
	 *
	 * @throws CollectionRequestException When it is.
	 */
	private function assert_active(): void {
		if ( ! $this->collections->active() ) {
			throw new CollectionRequestException( CollectionRequestException::PLUGIN_INACTIVE );
		}
	}

	/**
	 * The name of the plugin's function, which exists only while it is active.
	 */
	private static function sync_function(): string {
		return self::SYNC;
	}

	/**
	 * Whether someone has the collection open in the editor.
	 *
	 * @param int $collection_id The collection.
	 */
	private static function being_edited( int $collection_id ): bool {
		$lock = get_post_meta( $collection_id, '_edit_lock', true );
		$time = is_string( $lock ) ? (int) explode( ':', $lock )[0] : 0;

		return $time > time() - self::EDIT_WINDOW;
	}

	/**
	 * A row as the plugin stores it. Without a label the plugin shows the
	 * name it works out from the product's.
	 *
	 * @param int $product_id Product.
	 * @return array{id: int, label: string}
	 */
	private static function member( int $product_id ): array {
		return array(
			'id'    => $product_id,
			'label' => '',
		);
	}

	/**
	 * The terms of all the designs.
	 *
	 * @phpstan-param list<int> $product_ids
	 *
	 * @param int[]  $product_ids Products.
	 * @param string $taxonomy    Taxonomy.
	 * @return list<int>
	 */
	private static function terms( array $product_ids, string $taxonomy ): array {
		$ids = array();

		foreach ( $product_ids as $product_id ) {
			$terms = get_the_terms( $product_id, $taxonomy );

			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$ids[ (int) $term->term_id ] = (int) $term->term_id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Saves a draft through WooCommerce' CRUD with the plugin's class.
	 *
	 * @phpstan-param array{name: string, members: list<array{id: int, label: string}>, categories: list<int>, tags: list<int>, image: int, token: string} $draft
	 *
	 * @param array<string, mixed> $draft The draft.
	 * @throws CollectionRequestException When the plugin's class is missing.
	 */
	private static function save_draft( array $draft ): int {
		$class = DesignCollections::PRODUCT_CLASS;

		if ( ! class_exists( $class ) || ! is_subclass_of( $class, WC_Product::class ) ) {
			throw new CollectionRequestException( CollectionRequestException::PLUGIN_INACTIVE );
		}

		$product = new $class();
		$product->set_name( $draft['name'] );
		$product->set_status( 'draft' );
		$product->update_meta_data( DesignCollections::MEMBERS_KEY, $draft['members'] );

		if ( array() !== $draft['categories'] ) {
			$product->set_category_ids( $draft['categories'] );
		}

		if ( array() !== $draft['tags'] ) {
			$product->set_tag_ids( $draft['tags'] );
		}

		if ( $draft['image'] > 0 ) {
			$product->set_image_id( $draft['image'] );
		}

		$insert = static fn( mixed $data ): array => self::insert_data( is_array( $data ) ? $data : array(), $draft['token'] );

		add_filter( 'woocommerce_new_product_data', $insert, PHP_INT_MAX );

		try {
			return (int) $product->save();
		} finally {
			remove_filter( 'woocommerce_new_product_data', $insert, PHP_INT_MAX );
		}
	}
}
