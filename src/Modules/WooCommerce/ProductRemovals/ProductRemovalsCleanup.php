<?php
/**
 * A deleted creator's requests to remove a legacy product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\ProductRemovals;

use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorDataCleaner;

defined( 'ABSPATH' ) || exit;

/**
 * Deleting a creator (ADR-076) deletes their requests (ADR-083). The products
 * are not touched. Idempotent.
 */
final class ProductRemovalsCleanup implements CreatorDataCleaner {

	/**
	 * Constructor.
	 *
	 * @param ProductRemovalRepository $requests Storage.
	 */
	public function __construct( private ProductRemovalRepository $requests ) {
	}

	/**
	 * The key in the audit record.
	 */
	public function key(): string {
		return 'product_removals';
	}

	/**
	 * Nothing refuses it.
	 *
	 * @param Creator $creator The creator.
	 */
	public function check( Creator $creator ): void {
	}

	/**
	 * Deletes the requests.
	 *
	 * @param Creator $creator The creator (inactive).
	 * @return array<string, int>
	 */
	public function remove( Creator $creator ): array {
		return array( 'rows' => $this->requests->delete_for( $creator->id ) );
	}
}
