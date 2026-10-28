<?php
/**
 * The daily "balance ready for payout" check.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Payouts;

use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\WooCommerce\JobQueue;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Once a day (ADR-079, owner's choice 2026-10-27), each current creator
 * whose amount available for payout has grown since the last check is told
 * (`balance_ready`), per currency. The last known amount is kept in the
 * creator meta (`balance_notified`: `{ amounts: { currency: units } }`,
 * also for a creator without any balance yet); a creator checked for the
 * first time is only noted, so the release does not notify everyone at
 * once, while a new creator's first payable profit is told. A smaller
 * amount (a payout) is noted too, so the next growth is told again.
 *
 * The job queues the next run 24 hours later every time it runs; an
 * administrator page load (at most once an hour, never an AJAX request)
 * schedules it when none is queued.
 */
final class BalanceReadyNotices {

	public const HOOK = 'merchboard_notification_balance_check';

	/**
	 * Transient: an administrator page load checked the schedule within the hour.
	 */
	public const CHECKED = 'merchboard_balance_check_scheduled';

	/**
	 * Constructor.
	 *
	 * @param CreatorRepository $creators Creators.
	 * @param PayoutBalances    $balances Balances.
	 * @param CreatorMetaStore  $meta     Last known amounts.
	 * @param JobQueue          $queue    Queue.
	 * @param Logger            $logger   Technical log.
	 */
	public function __construct(
		private CreatorRepository $creators,
		private PayoutBalances $balances,
		private CreatorMetaStore $meta,
		private JobQueue $queue,
		private Logger $logger
	) {
	}

	/**
	 * Schedules the check when none is pending.
	 *
	 * @param JobQueue $queue Queue.
	 */
	public static function ensure_scheduled( JobQueue $queue ): void {
		if ( wp_doing_ajax() || false !== get_transient( self::CHECKED ) ) {
			return;
		}

		set_transient( self::CHECKED, 1, HOUR_IN_SECONDS );

		if ( $queue->available() && ! $queue->has_any( self::HOOK ) ) {
			$queue->enqueue( self::HOOK, array(), DAY_IN_SECONDS );
		}
	}

	/**
	 * Runs the check and schedules the next one.
	 *
	 * @return int Notifications fired.
	 */
	public function run(): int {
		// The running action counts as scheduled, so the next run is always queued here.
		if ( $this->queue->available() ) {
			$this->queue->enqueue( self::HOOK, array(), DAY_IN_SECONDS );
		}

		try {
			$creators = $this->creators->all();
		} catch ( Throwable $e ) {
			$this->logger->error( 'The creators for the balance check could not be read.', array( 'exception' => $e::class ) );

			return 0;
		}

		$fired = 0;

		foreach ( $creators as $creator ) {
			if ( $creator->is_deleted() || ! $creator->is_active() ) {
				continue;
			}

			try {
				$fired += $this->check( $creator->id );
			} catch ( Throwable $e ) {
				$this->logger->error(
					'The balance of a creator could not be checked.',
					array(
						'creator_id' => $creator->id,
						'exception'  => $e::class,
					)
				);
			}
		}

		return $fired;
	}

	/**
	 * Checks one creator.
	 *
	 * @param int $creator_id Creator.
	 * @return int Notifications fired.
	 */
	private function check( int $creator_id ): int {
		$stored = $this->meta->get( $creator_id, CreatorMetaStore::BALANCE_NOTIFIED );
		$known  = is_array( $stored );
		// 29.7 runtime test: an early value without `amounts` holds the amounts itself.
		$last  = $known ? ( is_array( $stored['amounts'] ?? null ) ? $stored['amounts'] : $stored ) : array();
		$now   = array();
		$fired = 0;

		foreach ( $this->balances->for_creator( $creator_id ) as $currency => $balance ) {
			$units = $balance->available_units();

			if ( null === $units ) {
				continue;
			}

			$previous         = is_int( $last[ $currency ] ?? null ) ? $last[ $currency ] : 0;
			$now[ $currency ] = $units;
			$grown            = $known && $units > 0 && $units > $previous;

			if ( $grown ) {
				do_action(
					'merchboard_notify',
					'balance_ready',
					$creator_id,
					array(
						'available' => SalesAmount::format( $units ),
						'currency'  => $currency,
					),
					'balance:' . $currency . ':' . gmdate( 'Y-m-d' )
				);
				++$fired;
			}
		}

		// Never empty: a creator without a balance yet is known, so the first growth is told.
		if ( ! $known || ! isset( $stored['amounts'] ) || $now !== $last ) {
			$this->meta->set( $creator_id, CreatorMetaStore::BALANCE_NOTIFIED, array( 'amounts' => $now ) );
		}

		return $fired;
	}
}
