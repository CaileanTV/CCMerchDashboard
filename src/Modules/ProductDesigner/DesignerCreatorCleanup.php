<?php
/**
 * Deleting a creator: their product designer data.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorDataCleaner;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Deleting a creator (ADR-076, ADR-080 "Migration/data implications"): the
 * creator's uploaded images and the designer products that never reached
 * the shop (no WooCommerce product) are deleted with their versions and
 * mockup tasks. Designer products that are in the shop stay with their
 * versions, so the order history keeps its print files; they are
 * unpublished when the apply pipeline exists (step 30.10). Idempotent.
 */
class DesignerCreatorCleanup implements CreatorDataCleaner {

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Database.
	 */
	public function __construct( private wpdb $wpdb ) {
	}

	/**
	 * The key in the audit record.
	 */
	public function key(): string {
		return 'designer';
	}

	/**
	 * Nothing to check.
	 *
	 * @param Creator $creator The creator.
	 */
	public function check( Creator $creator ): void {
	}

	/**
	 * Deletes the creator's designer data that never reached the shop.
	 *
	 * @param Creator $creator The creator (inactive).
	 * @return array<string, int>
	 * @throws DatabaseException When it cannot be deleted.
	 */
	public function remove( Creator $creator ): array {
		$products = Tables::name( $this->wpdb, Tables::DESIGNER_PRODUCTS );
		$versions = Tables::name( $this->wpdb, Tables::DESIGNER_VERSIONS );
		$tasks    = Tables::name( $this->wpdb, Tables::DESIGNER_PROVIDER_TASKS );
		$assets   = Tables::name( $this->wpdb, Tables::DESIGNER_ASSETS );

		$counts = array(
			'tasks'    => $this->query( 'DELETE t FROM %i t INNER JOIN %i v ON v.id = t.version_id INNER JOIN %i p ON p.id = v.design_product_id WHERE p.creator_id = %d AND p.wc_product_id IS NULL', $tasks, $versions, $products, $creator->id ),
			'versions' => $this->query( 'DELETE v FROM %i v INNER JOIN %i p ON p.id = v.design_product_id WHERE p.creator_id = %d AND p.wc_product_id IS NULL', $versions, $products, $creator->id ),
			'products' => $this->query( 'DELETE FROM %i WHERE creator_id = %d AND wc_product_id IS NULL', $products, $creator->id ),
			'assets'   => $this->query( 'DELETE FROM %i WHERE creator_id = %d', $assets, $creator->id ),
		);

		return $counts;
	}

	/**
	 * Runs a prepared delete.
	 *
	 * @phpstan-param literal-string $query
	 *
	 * @param string           $query Query with placeholders.
	 * @param string|int|float ...$args Arguments.
	 * @return int Rows deleted.
	 * @throws DatabaseException When it fails.
	 */
	private function query( string $query, string|int|float ...$args ): int {
		$deleted = $this->wpdb->query( (string) $this->wpdb->prepare( $query, ...$args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared right here.

		if ( false === $deleted || '' !== $this->wpdb->last_error ) {
			throw new DatabaseException( 'Could not delete the product designer data of the deleted creator.' );
		}

		return (int) $deleted;
	}
}
