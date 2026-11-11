<?php
/**
 * Deleting a creator: the creator's giveaways.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Giveaways;

use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorDataCleaner;

defined( 'ABSPATH' ) || exit;

/**
 * A deleted creator's giveaways (ADR-076, ADR-110): the ones nobody can get
 * yet are cancelled (nothing is charged), and finished ones are removed.
 * A code given out stays valid with its giveaway; the payout adjustments
 * and shop orders stay. Runs before the gifts' cleanup, which then removes
 * the cancelled gifts.
 */
final class GiveawayCreatorCleanup implements CreatorDataCleaner {

	/**
	 * Constructor.
	 *
	 * @param GiveawayRepository $repository Giveaways table.
	 * @param Giveaways          $giveaways  Giveaways.
	 */
	public function __construct( private GiveawayRepository $repository, private Giveaways $giveaways ) {
	}

	/**
	 * Key in the audit record.
	 */
	public function key(): string {
		return 'giveaways';
	}

	/**
	 * Nothing refuses the deletion.
	 *
	 * @param Creator $creator The creator.
	 */
	public function check( Creator $creator ): void {
	}

	/**
	 * Cancels open giveaways and deletes the finished ones.
	 *
	 * @param Creator $creator The creator.
	 * @return array<string, int>
	 */
	public function remove( Creator $creator ): array {
		return array(
			'giveaways_cancelled' => $this->giveaways->end_for_creator( $creator->id ),
			'giveaways'           => $this->repository->delete_finished( $creator->id ),
		);
	}
}
