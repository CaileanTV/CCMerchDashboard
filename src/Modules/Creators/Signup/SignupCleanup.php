<?php
/**
 * Removes a deleted creator's sign-up and requests to go live.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Signup;

use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorDataCleaner;
use MerchBoard\Modules\Creators\GoLive\GoLiveRepository;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-076 for ADR-091: the channel link of the sign-up and the requests to
 * go live go with the creator. The agreement's acceptances stay: they
 * document what was accepted (ADR-092).
 */
final class SignupCleanup implements CreatorDataCleaner {

	/**
	 * Constructor.
	 *
	 * @param SignupRepository $signups  Sign-ups.
	 * @param GoLiveRepository $requests Requests to go live.
	 */
	public function __construct( private SignupRepository $signups, private GoLiveRepository $requests ) {
	}

	/**
	 * The audit key.
	 */
	public function key(): string {
		return 'signup';
	}

	/**
	 * Nothing to refuse.
	 *
	 * @param Creator $creator The creator.
	 */
	public function check( Creator $creator ): void {
	}

	/**
	 * Removes the sign-up and the requests.
	 *
	 * @param Creator $creator The creator.
	 * @return array<string, int>
	 */
	public function remove( Creator $creator ): array {
		$this->signups->delete( $creator->id );
		$this->requests->delete_for( $creator->id );

		return array();
	}
}
