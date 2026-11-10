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
 * A deleted creator's gifts (ADR-076, ADR-107): gifts not claimed yet go
 * back to their buyers with the code (the buyer paid), and finished gifts
 * are removed. Gifts with a code not used yet stay, so the code works. The
 * gift orders and the sales ledger stay.
 */
final class GiftCreatorCleanup implements CreatorDataCleaner {

	/**
	 * Constructor.
	 *
	 * @param GiftRepository $gifts Gifts.
	 * @param GiftDraws      $draws The draws (gives gifts back).
	 */
	public function __construct( private GiftRepository $gifts, private GiftDraws $draws ) {
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
	 * Gives open gifts back and deletes the finished ones.
	 *
	 * @param Creator $creator The creator.
	 * @return array<string, int>
	 */
	public function remove( Creator $creator ): array {
		$returned = $this->draws->give_back_open( $creator->id, true );

		return array(
			'gifts_returned' => $returned,
			'gifts'          => $this->gifts->delete_for_creator( $creator->id ),
		);
	}
}
