<?php
/**
 * Deleting a creator: the creator's gifts.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorDataCleaner;

defined( 'ABSPATH' ) || exit;

/**
 * Removes a deleted creator's gifts (ADR-076, ADR-107). The gift orders and
 * the sales ledger stay; only the gift rows (names, winners, draws) go.
 */
final class GiftCreatorCleanup implements CreatorDataCleaner {

	/**
	 * Constructor.
	 *
	 * @param GiftRepository $gifts Gifts.
	 */
	public function __construct( private GiftRepository $gifts ) {
	}

	/**
	 * Key in the audit record.
	 */
	public function key(): string {
		return 'gifts';
	}

	/**
	 * Nothing refuses the deletion.
	 *
	 * @param Creator $creator The creator.
	 */
	public function check( Creator $creator ): void {
	}

	/**
	 * Deletes the gifts.
	 *
	 * @param Creator $creator The creator.
	 * @return array<string, int>
	 */
	public function remove( Creator $creator ): array {
		return array( 'gifts' => $this->gifts->delete_for_creator( $creator->id ) );
	}
}
