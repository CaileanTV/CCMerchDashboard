<?php
/**
 * Storage of the creators' master data for settlement documents.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts\Settlements;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Creators\CreatorMetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * The creator meta `billing_details` (ADR-062 K09). No authorization or
 * audit here (PayoutSettings does that). Saving empty master data removes
 * the meta; every write is read back (CreatorMetaStore).
 */
final class CreatorBillingDetailsStore {

	/**
	 * Constructor.
	 *
	 * @param CreatorMetaStore $meta Creator meta.
	 */
	public function __construct( private CreatorMetaStore $meta ) {
	}

	/**
	 * The creator's master data (all fields '' when none is registered).
	 *
	 * @param int $creator_id Creator.
	 */
	public function find( int $creator_id ): CreatorBillingDetails {
		return CreatorBillingDetails::from_stored( $this->meta->get( $creator_id, CreatorMetaStore::BILLING_DETAILS ) );
	}

	/**
	 * Stores the creator's master data.
	 *
	 * @param int                   $creator_id Creator.
	 * @param CreatorBillingDetails $details    Master data.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function save( int $creator_id, CreatorBillingDetails $details ): void {
		$this->meta->set( $creator_id, CreatorMetaStore::BILLING_DETAILS, $details->to_stored() );
	}
}
