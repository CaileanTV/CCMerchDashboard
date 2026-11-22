<?php
/**
 * The design collections in the shop, as the collection plugin stores them.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Collections;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-120: the one reader of what the plugin "CCMerch Design-samling" stores.
 * A collection is a product on whose page the customer chooses a design; the
 * designs are products of their own, and those are what is bought. While the
 * collection is published its designs are hidden in the shop.
 *
 * Only WordPress' own data is read, so the answers are the same while that
 * plugin is inactive. WooCommerce then loads a collection as a simple product
 * with a price, and saving it can make it one; a simple or untyped product
 * that still lists designs therefore counts as a collection. A product of
 * another type with designs left over from before is none.
 */
class DesignCollections {

	/**
	 * The plugin's product type.
	 */
	public const TYPE = 'ccm_design_group';

	/**
	 * The plugin's class for that type; without it a collection has no page.
	 */
	public const PRODUCT_CLASS = 'WC_Product_CCM_Design_Group';

	/**
	 * A collection's designs: list of `id` and `label`.
	 */
	public const MEMBERS_KEY = '_ccmds_members';

	/**
	 * The published collection that hides a design.
	 */
	public const GROUP_KEY = '_ccmds_group';

	/**
	 * A hidden design's visibility from before it was collected.
	 */
	public const PREVIOUS_VISIBILITY_KEY = '_ccmds_prev_visibility';

	/**
	 * The types a collection can have when it has lost its own.
	 */
	private const UNTYPED = array( '', 'simple' );

	/**
	 * The visibilities WooCommerce has besides hidden.
	 */
	private const SHOWN = array( 'visible', 'catalog', 'search' );

	/**
	 * Whether the collection plugin is active.
	 *
	 * @var Closure(): bool
	 */
	private Closure $active;

	/**
	 * Constructor.
	 *
	 * @phpstan-param (Closure(): bool)|null $active
	 *
	 * @param Closure|null $active Whether the collection plugin is active; its product class by default.
	 */
	public function __construct( ?Closure $active = null ) {
		$this->active = $active ?? static fn(): bool => class_exists( self::PRODUCT_CLASS, false );
	}

	/**
	 * Whether the collection plugin is active.
	 */
	public function active(): bool {
		return ( $this->active )();
	}

	/**
	 * Whether a product is a collection, which is never bought, given away or
	 * a creator's product itself.
	 *
	 * @param int $product_id Product.
	 */
	public function is_collection( int $product_id ): bool {
		if ( $product_id < 1 || 'product' !== get_post_type( $product_id ) ) {
			return false;
		}

		$type = self::slugs( $product_id, 'product_type' )[0] ?? '';

		return self::TYPE === $type || ( in_array( $type, self::UNTYPED, true ) && array() !== $this->members( $product_id ) );
	}

	/**
	 * A collection's designs in its order; rows that name no other product are left out.
	 *
	 * @param int $collection_id Collection.
	 * @return list<array{id: int, label: string}>
	 */
	public function members( int $collection_id ): array {
		$stored  = get_post_meta( $collection_id, self::MEMBERS_KEY, true );
		$members = array();

		foreach ( is_array( $stored ) ? $stored : array() as $row ) {
			$id = is_array( $row ) && is_numeric( $row['id'] ?? null ) ? (int) $row['id'] : 0;

			if ( $id < 1 || $id === $collection_id || isset( $members[ $id ] ) ) {
				continue;
			}

			$members[ $id ] = array(
				'id'    => $id,
				'label' => is_scalar( $row['label'] ?? null ) ? (string) $row['label'] : '',
			);
		}

		return array_values( $members );
	}

	/**
	 * The published collection a design is sold from, or null. The design's
	 * own mark is not enough: the collection must list it, as a copied product
	 * carries the mark too.
	 *
	 * @param int $product_id Parent product.
	 */
	public function collection_of( int $product_id ): ?int {
		$marked = get_post_meta( $product_id, self::GROUP_KEY, true );
		$id     = is_numeric( $marked ) ? (int) $marked : 0;

		if ( $id < 1 || $id === $product_id || 'publish' !== get_post_status( $id ) || ! $this->is_collection( $id ) ) {
			return null;
		}

		return in_array( $product_id, array_column( $this->members( $id ), 'id' ), true ) ? $id : null;
	}

	/**
	 * Whether a product is shown in the creator's channels (the Twitch panel
	 * and the merch ad): published and visible, or hidden only because a
	 * collection sells it. What an administrator hid stays hidden.
	 *
	 * @param int $product_id Parent product.
	 */
	public function shown_in_channels( int $product_id ): bool {
		if ( ! $this->published_design( $product_id ) ) {
			return false;
		}

		if ( ! self::hidden( $product_id ) ) {
			return true;
		}

		return null !== $this->collection_of( $product_id ) && in_array( get_post_meta( $product_id, self::PREVIOUS_VISIBILITY_KEY, true ), self::SHOWN, true );
	}

	/**
	 * Every collection outside the trash, by name.
	 *
	 * @return list<int>
	 */
	public function all(): array {
		$found = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'fields'           => 'ids',
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'meta_key'         => self::MEMBERS_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only collections carry the key.
			)
		);
		$all   = array();

		foreach ( $found as $id ) {
			if ( $this->is_collection( (int) $id ) ) {
				$all[] = (int) $id;
			}
		}

		return $all;
	}

	/**
	 * The design a product page shows: on a collection's page the one the
	 * plugin has selected, otherwise the product itself.
	 *
	 * @param int $product_id The page's product.
	 */
	public function displayed( int $product_id ): int {
		if ( ! $this->active() || ! $this->is_collection( $product_id ) ) {
			return $product_id;
		}

		$collection = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$select     = array( $collection, 'get_selected_member' );
		$selected   = is_callable( $select ) ? $select() : null;

		return is_array( $selected ) && is_numeric( $selected['id'] ?? null ) ? (int) $selected['id'] : $product_id;
	}

	/**
	 * Whether a product can be asked to be collected: published, visible in
	 * the shop and in no published collection.
	 *
	 * @param int $product_id Parent product.
	 */
	public function collectable( int $product_id ): bool {
		return $this->published_design( $product_id ) && ! self::hidden( $product_id ) && null === $this->collection_of( $product_id );
	}

	/**
	 * Where a product is bought now: the collection with the design chosen,
	 * otherwise the product's own page. Never stored, as a design can join or
	 * leave a collection; '' when the product has no page.
	 *
	 * @param int $product_id Parent product.
	 */
	public function design_url( int $product_id ): string {
		$collection = $this->active() ? $this->collection_of( $product_id ) : null;
		$url        = get_permalink( $collection ?? $product_id );

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		return null === $collection ? $url : (string) add_query_arg( 'design', $product_id, $url );
	}

	/**
	 * Whether it is a published parent product that is no collection.
	 *
	 * @param int $product_id Product.
	 */
	private function published_design( int $product_id ): bool {
		return $product_id > 0
			&& 'product' === get_post_type( $product_id )
			&& 'publish' === get_post_status( $product_id )
			&& ! $this->is_collection( $product_id );
	}

	/**
	 * Whether WooCommerce' catalog visibility is hidden (both exclusions).
	 *
	 * @param int $product_id Product.
	 */
	private static function hidden( int $product_id ): bool {
		$terms = self::slugs( $product_id, 'product_visibility' );

		return in_array( 'exclude-from-catalog', $terms, true ) && in_array( 'exclude-from-search', $terms, true );
	}

	/**
	 * A product's term slugs, through WordPress' term cache.
	 *
	 * @param int    $product_id Product.
	 * @param string $taxonomy   Taxonomy.
	 * @return list<string>
	 */
	private static function slugs( int $product_id, string $taxonomy ): array {
		$terms = get_the_terms( $product_id, $taxonomy );
		$slugs = array();

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$slugs[] = (string) $term->slug;
		}

		return $slugs;
	}
}
