<?php
/**
 * The WooCommerce module's part of deleting a creator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeRepository;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorDataCleaner;
use MerchBoard\Modules\Creators\CreatorDeletionException;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeException;
use MerchBoard\Modules\WooCommerce\CreatorCodes\CreatorCodeService;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Deleting a creator (phase 27, step 27.3, ADR-076): the product category
 * mapping is removed, every product loses its link to the creator (so a new
 * order never creates sales for a creator that no longer exists), an active
 * discount code is ended, and the creator's product markups are deleted.
 * The sales ledger, payouts and settlement documents stay (CreatorDeletion
 * decides whether the creator is kept anonymized). Idempotent.
 */
class WooCommerceCreatorCleanup implements CreatorDataCleaner {

	/**
	 * Products handled per read.
	 */
	private const BATCH = 100;

	/**
	 * Constructor.
	 *
	 * @param ProductStore           $products   Products.
	 * @param ProductCreatorLink     $links      Products' creators.
	 * @param CreatorCategoryService $categories Category mappings.
	 * @param CreatorCodeService     $codes      Discount codes.
	 * @param CreatorCodeRepository  $code_rows  The codes' storage (the check).
	 * @param wpdb                   $wpdb       Database (product markups).
	 */
	public function __construct(
		private ProductStore $products,
		private ProductCreatorLink $links,
		private CreatorCategoryService $categories,
		private CreatorCodeService $codes,
		private CreatorCodeRepository $code_rows,
		private wpdb $wpdb
	) {
	}

	/**
	 * The key in the audit record.
	 */
	public function key(): string {
		return 'woocommerce';
	}

	/**
	 * Ending an active discount code needs `merchboard_manage_creator_pricing`
	 * (as on the creator's page): checked before anything changes.
	 *
	 * @param Creator $creator The creator.
	 * @throws CreatorDeletionException When the current user may not end it.
	 * @throws DatabaseException When the code cannot be read.
	 */
	public function check( Creator $creator ): void {
		if ( null !== $this->code_rows->active_for_creator( $creator->id ) && ! current_user_can( Capabilities::MANAGE_CREATOR_PRICING ) ) {
			self::refuse();
		}
	}

	/**
	 * Removes the creator's category mapping, product links, discount code and
	 * product markups.
	 *
	 * @param Creator $creator The creator (inactive).
	 * @return array<string, int>
	 * @throws CreatorDeletionException When the current user may not end the discount code (nothing more is removed).
	 * @throws DatabaseException When something cannot be removed.
	 */
	public function remove( Creator $creator ): array {
		$categories = 0;

		// First the mapping, so no category reconciliation links its products again.
		foreach ( $this->categories->for_creator( $creator->id ) as $mapping ) {
			try {
				$categories += $this->categories->unlink( $creator->id, $mapping->term_id, MappingRemovalChoice::RemoveLinks ) ? 1 : 0;
			} catch ( CreatorCategoryException ) {
				self::refuse();
			}
		}

		return array(
			'categories' => $categories,
			'products'   => $this->release_products( $creator->id ),
			'codes'      => $this->end_code( $creator->id ),
			'markups'    => $this->delete_markups( $creator->id ),
		);
	}

	/**
	 * Removes every product's link to the creator.
	 *
	 * @param int $creator_id Creator.
	 * @return int Products released.
	 * @throws DatabaseException When a link cannot be removed.
	 */
	private function release_products( int $creator_id ): int {
		$released = 0;
		$seen     = array();

		do {
			$batch = array_values( array_filter( $this->products->linked_to( $creator_id, self::BATCH ), static fn( ProductRecord $product ): bool => ! isset( $seen[ $product->id ] ) ) );

			foreach ( $batch as $product ) {
				// A link that is not exactly this creator (e.g. an invalid one) is left and never read twice.
				$seen[ $product->id ] = true;
				$released            += $this->links->release_for_deleted_creator( $product->id, $creator_id ) ? 1 : 0;
			}
		} while ( array() !== $batch );

		// Finally no product may still belong to the creator (a new order would create sales for it).
		foreach ( $this->products->linked_to( $creator_id, self::BATCH ) as $product ) {
			if ( $creator_id === $product->creator_id() ) {
				throw new DatabaseException( 'Products are still linked to the creator being deleted.' );
			}
		}

		return $released;
	}

	/**
	 * Ends the creator's active discount code.
	 *
	 * @param int $creator_id Creator.
	 * @return int 1 when a code was ended.
	 * @throws CreatorDeletionException When the current user may not end it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function end_code( int $creator_id ): int {
		try {
			$this->codes->end( $creator_id );
		} catch ( CreatorCodeException $e ) {
			if ( CreatorCodeException::NOT_AUTHORIZED === $e->reason() ) {
				self::refuse();
			}

			return 0;
		}

		return 1;
	}

	/**
	 * Refuses: the current user may not do a part of the deletion.
	 *
	 * @throws CreatorDeletionException Always.
	 */
	private static function refuse(): never {
		throw new CreatorDeletionException( CreatorDeletionException::NOT_AUTHORIZED );
	}

	/**
	 * Deletes the creator's product markups.
	 *
	 * @param int $creator_id Creator.
	 * @return int Markups deleted.
	 * @throws DatabaseException When they cannot be deleted.
	 */
	private function delete_markups( int $creator_id ): int {
		$deleted = $this->wpdb->delete( Tables::name( $this->wpdb, Tables::PRICING_OVERRIDES ), array( 'creator_id' => $creator_id ), array( '%d' ) );

		if ( false === $deleted ) {
			throw new DatabaseException( 'Could not delete the product markups of the deleted creator.' );
		}

		return $deleted;
	}
}
