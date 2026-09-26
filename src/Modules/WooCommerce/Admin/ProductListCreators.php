<?php
/**
 * Read-only "Creator" column and creator filter in WooCommerce's product list.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Admin\CreatorsAdminPage;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Modules\WooCommerce\ProductStore;

defined( 'ABSPATH' ) || exit;

/**
 * Shows each product's creator in WooCommerce's product list (Products →
 * All Products) and filters the list by creator (ADR-032 pkt. 4). Purely a
 * read path: it never writes, never calls ProductCreatorLink's write methods
 * and records no audit.
 *
 * - The only ownership signal is `_merchboard_creator_id` on the parent
 *   product (ADR-030/033); categories are not read.
 * - Hooked only on the product list screen (`edit-product`, also for its
 *   quick edit, which sets the same screen) and only for users who may see
 *   creators (`merchboard_manage_creators`). For others nothing is added and
 *   the filter parameter is ignored, so it cannot probe product ownership.
 * - The list shows parent products; a variation would be resolved to its
 *   parent, like ProductCreatorLink::creator_id().
 * - The filter adds a meta query on the link key to WordPress' request, next
 *   to WooCommerce's own filters. Like ProductStore::linked_to(), MySQL
 *   compares the key case-insensitively, so a product with an invalid link
 *   stays findable; its column says "Invalid link".
 */
final class ProductListCreators {

	/**
	 * Column id.
	 */
	public const COLUMN = 'merchboard_creator';

	/**
	 * GET parameter of the filter (creator id; empty for all creators).
	 */
	public const FILTER_ARG = 'merchboard_creator';

	/**
	 * Screen id of WooCommerce's product list.
	 */
	private const SCREEN = 'edit-product';

	/**
	 * Every creator by id, loaded once per request.
	 *
	 * @var array<int, Creator>|null
	 */
	private ?array $creators = null;

	/**
	 * Constructor.
	 *
	 * @param ProductStore      $products Product reads.
	 * @param CreatorRepository $repo     Creator reads.
	 */
	public function __construct(
		private ProductStore $products,
		private CreatorRepository $repo
	) {
	}

	/**
	 * Waits for the product list screen.
	 */
	public function register(): void {
		add_action( 'current_screen', array( $this, 'on_screen' ) );
	}

	/**
	 * Adds the column, the filter and the query on the product list for users
	 * who may see creators. Runs on `current_screen`, which WordPress also
	 * fires for the product list's quick edit.
	 */
	public function on_screen(): void {
		$screen = get_current_screen();

		if ( null === $screen || self::SCREEN !== $screen->id || ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			return;
		}

		add_filter( 'manage_product_posts_columns', array( $this, 'columns' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'render_filter' ), 20, 2 );
		add_filter( 'request', array( $this, 'filter_request' ), 20 );
	}

	/**
	 * Adds the column before the date column (or at the end).
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		$added = array();

		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$added[ self::COLUMN ] = __( 'Creator', 'merchboard' );
			}

			$added[ $key ] = $label;
		}

		if ( ! isset( $added[ self::COLUMN ] ) ) {
			$added[ self::COLUMN ] = __( 'Creator', 'merchboard' );
		}

		return $added;
	}

	/**
	 * Prints the creator cell of a product.
	 *
	 * @param string $column  Column id.
	 * @param int    $post_id Product id.
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		echo $this->cell( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in cell().
	}

	/**
	 * Prints the creator select next to WooCommerce's own product filters.
	 *
	 * @param string $post_type Post type of the list.
	 * @param string $which     'top' or 'bottom'.
	 */
	public function render_filter( string $post_type, string $which = 'top' ): void {
		if ( 'product' !== $post_type || 'top' !== $which ) {
			return;
		}

		$selected = $this->selected_creator();

		echo '<label for="' . esc_attr( self::FILTER_ARG ) . '" class="screen-reader-text">' . esc_html__( 'Filter by creator', 'merchboard' ) . '</label>';
		echo '<select name="' . esc_attr( self::FILTER_ARG ) . '" id="' . esc_attr( self::FILTER_ARG ) . '">';
		echo '<option value="">' . esc_html__( 'All creators', 'merchboard' ) . '</option>';

		foreach ( $this->creators() as $creator ) {
			$label = CreatorStatus::Active === $creator->status
				? $creator->display_name
				/* translators: %s: creator display name. */
				: sprintf( __( '%s (inactive)', 'merchboard' ), $creator->display_name );

			echo '<option value="' . esc_attr( (string) $creator->id ) . '"' . ( $selected === $creator->id ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select>';
	}

	/**
	 * Limits WordPress' product list query to the selected creator's link.
	 * Without a valid selection the query vars are returned unchanged.
	 *
	 * @param array<string, mixed> $query_vars Request query vars.
	 * @return array<string, mixed>
	 */
	public function filter_request( array $query_vars ): array {
		$creator_id = $this->selected_creator();

		if ( null === $creator_id || ( isset( $query_vars['post_type'] ) && 'product' !== $query_vars['post_type'] ) ) {
			return $query_vars;
		}

		$clause = array(
			'key'     => ProductCreatorLink::META_KEY,
			'value'   => (string) $creator_id,
			'compare' => '=',
		);

		// Keep any existing meta query intact; both must match.
		$existing                 = $query_vars['meta_query'] ?? null;
		$query_vars['meta_query'] = is_array( $existing ) && array() !== $existing // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The creator filter; one key and value.
			? array(
				'relation' => 'AND',
				$existing,
				$clause,
			)
			: array( $clause );

		return $query_vars;
	}

	/**
	 * The creator cell: name (linked to the creator's edit page), "Invalid
	 * link", "Creator #ID (not found)" or an empty marker.
	 *
	 * @param int $post_id Product id.
	 */
	private function cell( int $post_id ): string {
		$product = $this->products->find( $post_id );

		if ( null !== $product && $product->is_variation ) {
			$product = $product->parent_id > 0 ? $this->products->find( $product->parent_id ) : null;
		}

		if ( null === $product || ! $product->has_link() ) {
			return '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( 'No creator', 'merchboard' ) . '</span>';
		}

		$creator_id = $product->creator_id();

		if ( null === $creator_id ) {
			return esc_html__( 'Invalid link', 'merchboard' );
		}

		$creator = $this->creators()[ $creator_id ] ?? null;

		if ( null === $creator ) {
			/* translators: %d: creator id. */
			return esc_html( sprintf( __( 'Creator #%d (not found)', 'merchboard' ), $creator_id ) );
		}

		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( CreatorsAdminPage::edit_url( $creator->id ) ), esc_html( $creator->display_name ) );
	}

	/**
	 * The creator id from the filter, when it names an existing creator.
	 */
	private function selected_creator(): ?int {
		$raw = isset( $_GET[ self::FILTER_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::FILTER_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.

		if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $raw ) ) {
			return null;
		}

		$creator_id = (int) $raw;

		return isset( $this->creators()[ $creator_id ] ) ? $creator_id : null;
	}

	/**
	 * Every creator by id (one read per request).
	 *
	 * @return array<int, Creator>
	 */
	private function creators(): array {
		if ( null === $this->creators ) {
			$this->creators = array();

			foreach ( $this->repo->all() as $creator ) {
				$this->creators[ $creator->id ] = $creator;
			}
		}

		return $this->creators;
	}
}
