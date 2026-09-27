<?php
/**
 * What the category mappings say about a creator's category-managed links.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\CreatorRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only view for the creator's product section (ADR-033): for each
 * category-managed link, the existing reconciliation rules decide whether
 * the current mappings still justify it (CreatorCategoryReconciler::plan_product(),
 * nothing written), and which of the product's direct categories are mapped
 * to the creator. The UI never decides this itself.
 *
 * All mappings are read once (PreloadedMappings) and the category names with
 * one get_terms() call. Manual links are not evaluated: they always win.
 */
final class CategoryLinkStatus {

	/**
	 * Constructor.
	 *
	 * @param ProductStore              $products Product reads.
	 * @param CreatorCategoryRepository $stored   Stored mappings.
	 * @param CreatorRepository         $creators Creator reads.
	 * @param ProductCreatorLink        $links    Required by the reconciler; not called here.
	 * @param Logger                    $logger   Technical log.
	 */
	public function __construct(
		private ProductStore $products,
		private CreatorCategoryRepository $stored,
		private CreatorRepository $creators,
		private ProductCreatorLink $links,
		private Logger $logger
	) {
	}

	/**
	 * The status of each category-managed link to the creator among the
	 * products, by product id. Empty when the mappings cannot be read.
	 *
	 * @phpstan-param list<ProductRecord> $products
	 *
	 * @param int             $creator_id Creator id.
	 * @param ProductRecord[] $products   Products linked to the creator.
	 * @return array<int, array{outcome: ReconciliationOutcome, categories: list<array{term_id: int, name: string|null}>}>
	 */
	public function describe( int $creator_id, array $products ): array {
		$managed = array_values( array_filter( $products, static fn( ProductRecord $p ): bool => $p->is_category_managed() && $p->creator_id() === $creator_id ) );

		if ( array() === $managed ) {
			return array();
		}

		try {
			$mappings = new PreloadedMappings( $this->stored );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The category mappings could not be read for the product section.', array( 'exception' => $e ) );

			return array();
		}

		$reconciler = new CreatorCategoryReconciler( $this->products, $mappings, $this->creators, $this->links, $this->logger );
		$justifying = array();

		foreach ( $managed as $product ) {
			$justifying[ $product->id ] = $mappings->mapped_to( $product->category_ids, $creator_id );
		}

		$names  = self::names( array_values( array_unique( array_merge( ...array_values( $justifying ) ) ) ) );
		$status = array();

		foreach ( $managed as $product ) {
			$status[ $product->id ] = array(
				'outcome'    => $reconciler->plan_product( $product->id, CategoryReconciliationTrigger::CategoryChanged )->outcome,
				'categories' => array_map(
					static fn( int $term_id ): array => array(
						'term_id' => $term_id,
						'name'    => $names[ $term_id ] ?? null,
					),
					$justifying[ $product->id ]
				),
			);
		}

		return $status;
	}

	/**
	 * Names of the existing `product_cat` terms among the ids, by id.
	 *
	 * @phpstan-param list<int> $term_ids
	 *
	 * @param int[] $term_ids Term ids.
	 * @return array<int, string>
	 */
	private static function names( array $term_ids ): array {
		if ( array() === $term_ids ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => CreatorCategoryService::TAXONOMY,
				'include'    => $term_ids,
				'hide_empty' => false,
			)
		);
		$names = array();

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$names[ $term->term_id ] = $term->name;
		}

		return $names;
	}
}
