<?php
/**
 * Read-only "Product categories" column in the Creators list.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\CreatorCategoryService;

defined( 'ABSPATH' ) || exit;

/**
 * Shows each creator's mapped `product_cat` categories in Merch Dashboard →
 * Creators (ADR-033), through the list's extension hooks. A mapping is a
 * rule, not ownership; the column only names the categories.
 *
 * Read-only: it writes nothing, queues nothing and never cleans up. For a
 * page it reads all mappings once (CreatorCategoryRepository::all(); at most
 * one row per category) and their categories with one get_terms() call, so
 * the number of queries does not grow with the rows. A mapping whose
 * category no longer exists (or is no longer a `product_cat`) is shown as
 * "Deleted category"; it is removed only on the creator's edit page.
 */
final class CreatorListCategories {

	/**
	 * Column key.
	 */
	public const COLUMN = 'merchboard_product_categories';

	/**
	 * Category labels per creator id for the current page; null until loaded.
	 *
	 * @var array<int, list<array{name: string|null, term_id: int}>>|null
	 */
	private ?array $labels = null;

	/**
	 * Whether the mappings could not be read.
	 *
	 * @var bool
	 */
	private bool $failed = false;

	/**
	 * Constructor.
	 *
	 * @param CreatorCategoryRepository $mappings Mapping reads.
	 * @param Logger                    $logger   Technical log.
	 */
	public function __construct(
		private CreatorCategoryRepository $mappings,
		private Logger $logger
	) {
	}

	/**
	 * Hooks the column into the Creators list.
	 */
	public function register(): void {
		add_filter( 'merchboard_creators_list_columns', array( $this, 'columns' ) );
		add_action( 'merchboard_creators_list_items', array( $this, 'load' ), 10, 0 );
		add_filter( 'merchboard_creators_list_column', array( $this, 'render' ), 10, 3 );
	}

	/**
	 * Adds the column.
	 *
	 * @param mixed $added Columns added so far.
	 * @return array<string, string>
	 */
	public function columns( mixed $added ): array {
		$added = is_array( $added ) ? $added : array();

		if ( current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			$added[ self::COLUMN ] = __( 'Product categories', 'merchboard' );
		}

		return $added;
	}

	/**
	 * Loads the categories for the whole list in one go, when the list has
	 * its page of creators (the mappings table is small, so all mappings are
	 * read and grouped by creator).
	 */
	public function load(): void {
		$this->labels = array();
		$this->failed = false;

		try {
			$mappings = $this->mappings->all();
		} catch ( DatabaseException $e ) {
			$this->failed = true;
			$this->logger->error( 'The product categories of the creators list could not be read.', array( 'exception' => $e ) );

			return;
		}

		if ( array() === $mappings ) {
			return;
		}

		$names = self::names( array_map( static fn( $m ): int => $m->term_id, $mappings ) );

		if ( null === $names ) {
			$this->failed = true;

			return;
		}

		foreach ( $mappings as $mapping ) {
			$this->labels[ $mapping->creator_id ][] = array(
				'name'    => $names[ $mapping->term_id ] ?? null,
				'term_id' => $mapping->term_id,
			);
		}
	}

	/**
	 * The cell of the column.
	 *
	 * @param mixed $html   Cell HTML so far.
	 * @param mixed $column Column key.
	 * @param mixed $item   Creator of the row.
	 */
	public function render( mixed $html, mixed $column, mixed $item ): mixed {
		if ( self::COLUMN !== $column || ! $item instanceof Creator || ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			return $html;
		}

		if ( null === $this->labels ) {
			$this->load();
		}

		if ( $this->failed ) {
			return esc_html__( 'Could not be loaded', 'merchboard' );
		}

		$categories = $this->labels[ $item->id ] ?? array();

		if ( array() === $categories ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No product category', 'merchboard' ) . '</span>';
		}

		$parts = array();

		foreach ( $categories as $category ) {
			$parts[] = null === $category['name']
				/* translators: %d: term id of a deleted product category. */
				? '<em>' . esc_html( sprintf( __( 'Deleted category (#%d)', 'merchboard' ), $category['term_id'] ) ) . '</em>'
				: esc_html( $category['name'] );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Names of the existing `product_cat` terms among the ids, by id; ids of
	 * deleted terms (or other taxonomies) are missing. One query. Null when
	 * the terms cannot be read at all (e.g. the taxonomy is not registered), so
	 * no mapping is wrongly shown as deleted.
	 *
	 * @phpstan-param list<int> $term_ids
	 *
	 * @param int[] $term_ids Term ids.
	 * @return array<int, string>|null
	 */
	private static function names( array $term_ids ): ?array {
		$terms = get_terms(
			array(
				'taxonomy'   => CreatorCategoryService::TAXONOMY,
				'include'    => $term_ids,
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) ) {
			return null;
		}

		$names = array();

		foreach ( $terms as $term ) {
			$names[ $term->term_id ] = $term->name;
		}

		return $names;
	}
}
