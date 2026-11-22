<?php
/**
 * The collection requests of a deleted creator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Collections;

use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorDataCleaner;

defined( 'ABSPATH' ) || exit;

/**
 * Deleting a creator (ADR-076) deletes their requests (ADR-120). The
 * collections and products are not touched. Idempotent.
 */
final class CollectionRequestsCleanup implements CreatorDataCleaner {

	/**
	 * Constructor.
	 *
	 * @param CollectionRequestRepository $requests Storage.
	 */
	public function __construct( private CollectionRequestRepository $requests ) {
	}

	/**
	 * The key in the audit record.
	 */
	public function key(): string {
		return 'collection_requests';
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
