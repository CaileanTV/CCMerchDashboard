<?php
/**
 * Deny-by-default guard for the protected product meta.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use LogicException;
use MerchBoard\Core\Logging\Logger;
use WeakMap;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps `_merchboard_creator_id` and `_merchboard_creator_link_source` from
 * being added, changed, renamed or deleted through any generic write path.
 *
 * Why: the product → creator link is ownership (ADR-030/033). It may only
 * change through Merch Dashboard's approved paths, which validate, authorize
 * and audit: the manual path through ProductCreatorLink and, later, ADR-033's
 * system category reconciliation, which will use this same mechanism.
 *
 * How: WordPress' metadata pre-hooks (`add_`, `update_`, `delete_post_metadata`
 * and the `_by_mid` variants that WooCommerce CRUD uses) run for every meta
 * write through the metadata API: WooCommerce CRUD, REST, CSV import, quick
 * and bulk edit, XML-RPC, `update_post_meta()` from any code and WP-CLI.
 * A write that touches a protected key (compared trimmed and
 * case-insensitively, as MySQL compares keys; for `_by_mid` also the key
 * stored in the row, so a rename away from a protected key is caught) is
 * short-circuited as failed and logged, unless:
 * - an explicit ProtectedMetaWrite for that very product is running inside
 *   run() (scoped to the call, one product, one use, no nesting), or
 * - WordPress is permanently deleting that post (its meta goes with it).
 *
 * Direct SQL is outside the metadata API and not covered (ADR-030 D6).
 * Reads are never affected.
 */
final class ProtectedProductMeta {

	/**
	 * Protected keys (lower case).
	 */
	public const KEYS = array( ProductCreatorLink::META_KEY, ProductCreatorLink::SOURCE_META_KEY );

	/**
	 * Runs after other plugins' pre-hooks, so a protected write is denied
	 * whatever they return.
	 */
	private const PRIORITY = 1000;

	/**
	 * The write currently authorized by run(), if any.
	 *
	 * @var ProtectedMetaWrite|null
	 */
	private ?ProtectedMetaWrite $active = null;

	/**
	 * Writes that were already run (each is accepted once).
	 *
	 * @var WeakMap<ProtectedMetaWrite, true>
	 */
	private WeakMap $used;

	/**
	 * Posts WordPress is permanently deleting right now.
	 *
	 * @var array<int, true>
	 */
	private array $deleting = array();

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Technical log for denied writes.
	 */
	public function __construct( private Logger $logger ) {
		$this->used = new WeakMap();
	}

	/**
	 * Whether a meta key is protected (trimmed, case-insensitive).
	 *
	 * @param mixed $key Meta key.
	 */
	public static function is_protected_key( mixed $key ): bool {
		return is_string( $key ) && in_array( strtolower( trim( $key ) ), self::KEYS, true );
	}

	/**
	 * Hooks the guard into the metadata API and post deletion.
	 */
	public function register(): void {
		add_filter( 'add_post_metadata', array( $this, 'filter_add' ), self::PRIORITY, 3 );
		add_filter( 'update_post_metadata', array( $this, 'filter_update' ), self::PRIORITY, 3 );
		add_filter( 'delete_post_metadata', array( $this, 'filter_delete' ), self::PRIORITY, 5 );
		add_filter( 'update_post_metadata_by_mid', array( $this, 'filter_update_by_mid' ), self::PRIORITY, 4 );
		add_filter( 'delete_post_metadata_by_mid', array( $this, 'filter_delete_by_mid' ), self::PRIORITY, 2 );
		add_action( 'before_delete_post', array( $this, 'deleting_post' ), 0 );
		add_action( 'after_delete_post', array( $this, 'deleted_post' ), PHP_INT_MAX );
	}

	/**
	 * Runs $operation with $write authorized, and only for its duration.
	 * Nothing else is authorized meanwhile: meta of other posts, deletes of
	 * every row by key, and nested writes stay denied.
	 *
	 * @template T
	 * @param ProtectedMetaWrite $write     The approved write.
	 * @param callable(): T      $operation The meta writes.
	 * @return T
	 * @throws LogicException When $write was run before or another write is running.
	 */
	public function run( ProtectedMetaWrite $write, callable $operation ): mixed {
		if ( isset( $this->used[ $write ] ) ) {
			throw new LogicException( 'A protected meta write can only be run once.' );
		}

		if ( null !== $this->active ) {
			throw new LogicException( 'Protected meta writes cannot be nested.' );
		}

		$this->used[ $write ] = true;
		$this->active         = $write;

		try {
			return $operation();
		} finally {
			$this->active = null;
		}
	}

	/**
	 * `add_post_metadata`.
	 *
	 * @param mixed $check     Short-circuit value so far.
	 * @param mixed $object_id Post id.
	 * @param mixed $meta_key  Meta key.
	 * @return mixed
	 */
	public function filter_add( mixed $check, mixed $object_id, mixed $meta_key ): mixed {
		return $this->decide( $check, 'add', $meta_key, null, $this->id( $object_id ) );
	}

	/**
	 * `update_post_metadata`.
	 *
	 * @param mixed $check     Short-circuit value so far.
	 * @param mixed $object_id Post id.
	 * @param mixed $meta_key  Meta key.
	 * @return mixed
	 */
	public function filter_update( mixed $check, mixed $object_id, mixed $meta_key ): mixed {
		return $this->decide( $check, 'update', $meta_key, null, $this->id( $object_id ) );
	}

	/**
	 * `delete_post_metadata`. Deleting a key from every post is never
	 * authorized by a write for one product.
	 *
	 * @param mixed $check      Short-circuit value so far.
	 * @param mixed $object_id  Post id.
	 * @param mixed $meta_key   Meta key.
	 * @param mixed $meta_value Value to match.
	 * @param mixed $delete_all Delete from every post.
	 * @return mixed
	 */
	public function filter_delete( mixed $check, mixed $object_id, mixed $meta_key, mixed $meta_value = '', mixed $delete_all = false ): mixed {
		return $this->decide( $check, 'delete', $meta_key, null, true === $delete_all ? 0 : $this->id( $object_id ) );
	}

	/**
	 * `update_post_metadata_by_mid`: both the stored key and a new key count.
	 *
	 * @param mixed $check      Short-circuit value so far.
	 * @param mixed $meta_id    Meta row id.
	 * @param mixed $meta_value New value.
	 * @param mixed $meta_key   New key (false: unchanged).
	 * @return mixed
	 */
	public function filter_update_by_mid( mixed $check, mixed $meta_id, mixed $meta_value = null, mixed $meta_key = false ): mixed {
		$row = $this->row( $meta_id );

		return $this->decide( $check, 'update_by_mid', $row['key'] ?? null, $meta_key, $row['post_id'] ?? 0 );
	}

	/**
	 * `delete_post_metadata_by_mid`. Also used by WordPress when it deletes a
	 * post permanently; that is allowed for the post being deleted.
	 *
	 * @param mixed $check   Short-circuit value so far.
	 * @param mixed $meta_id Meta row id.
	 * @return mixed
	 */
	public function filter_delete_by_mid( mixed $check, mixed $meta_id ): mixed {
		$row     = $this->row( $meta_id );
		$post_id = $row['post_id'] ?? 0;

		if ( $post_id > 0 && isset( $this->deleting[ $post_id ] ) ) {
			return $check;
		}

		return $this->decide( $check, 'delete_by_mid', $row['key'] ?? null, null, $post_id );
	}

	/**
	 * `before_delete_post`: the post's meta may now be deleted with it.
	 *
	 * @param mixed $post_id Post id.
	 */
	public function deleting_post( mixed $post_id ): void {
		$id = $this->id( $post_id );

		if ( $id > 0 ) {
			$this->deleting[ $id ] = true;
		}
	}

	/**
	 * `after_delete_post`: the deletion is over.
	 *
	 * @param mixed $post_id Post id.
	 */
	public function deleted_post( mixed $post_id ): void {
		unset( $this->deleting[ $this->id( $post_id ) ] );
	}

	/**
	 * Lets the write through unless it touches a protected key without an
	 * authorized write for that post; then it fails and is logged.
	 *
	 * @param mixed  $check     Short-circuit value so far.
	 * @param string $operation Operation name for the log.
	 * @param mixed  $key       Key the write touches.
	 * @param mixed  $new_key   New key of a rename, if any.
	 * @param int    $post_id   Post id (0: every post or unknown).
	 * @return mixed
	 */
	private function decide( mixed $check, string $operation, mixed $key, mixed $new_key, int $post_id ): mixed {
		if ( ! self::is_protected_key( $key ) && ! self::is_protected_key( $new_key ) ) {
			return $check;
		}

		if ( null !== $this->active && $post_id > 0 && $this->active->covers( $post_id ) ) {
			return $check;
		}

		$this->logger->warning(
			'Blocked a write to protected product meta outside Merch Dashboard.',
			array(
				'operation' => $operation,
				'post_id'   => $post_id,
				'key'       => self::is_protected_key( $key ) ? strtolower( trim( (string) $key ) ) : strtolower( trim( (string) $new_key ) ),
			)
		);

		return false;
	}

	/**
	 * Post id and key of a meta row.
	 *
	 * @param mixed $meta_id Meta row id.
	 * @return array{post_id: int, key: string}|null
	 */
	private function row( mixed $meta_id ): ?array {
		$row = is_numeric( $meta_id ) && (int) $meta_id > 0 ? get_metadata_by_mid( 'post', (int) $meta_id ) : false;

		if ( ! is_object( $row ) || ! isset( $row->post_id, $row->meta_key ) ) {
			return null;
		}

		return array(
			'post_id' => $this->id( $row->post_id ),
			'key'     => (string) $row->meta_key,
		);
	}

	/**
	 * A positive id or 0.
	 *
	 * @param mixed $value Raw id.
	 */
	private function id( mixed $value ): int {
		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : 0;
	}
}
