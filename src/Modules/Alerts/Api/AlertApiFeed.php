<?php
/**
 * What the alert API gives a bot.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Api;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlays;
use MerchBoard\Modules\Creators\CreatorRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's alerts for their own bots (ADR-073), polled with a cursor
 * like the overlay's feed: without a cursor the answer starts from now (the
 * newest id, no alerts), after that only alerts with a higher id, oldest
 * first, so a bot never gets an alert twice. Tests and resends wait in the
 * key's queue and come with the next answer (id 0, so they never move the
 * cursor).
 *
 * Only while the administrator has the API on and alerts are being sent
 * (the kill switch and the MerchAlert guard, AlertsAvailability), and only
 * for an active creator with access to API keys (AlertApiAccess, step
 * 25.12.4).
 */
class AlertApiFeed {

	/**
	 * Alerts per answer by default.
	 */
	public const LIMIT = 20;

	/**
	 * Most alerts per answer.
	 */
	public const MAX_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param AlertApiSwitch       $api          The administrator's switch.
	 * @param AlertsAvailability   $availability Kill switch and MerchAlert guard.
	 * @param CreatorRepository    $creators     Creators.
	 * @param AlertEventRepository $events       Alerts.
	 * @param AlertApiEvents       $shape        Alerts for bots.
	 * @param AlertApiQueue        $queue        Tests and resends.
	 * @param AlertOverlays        $overlays     Test alerts.
	 * @param AlertApiAccess       $access       The creators' access (step 25.12.4).
	 */
	public function __construct(
		private AlertApiSwitch $api,
		private AlertsAvailability $availability,
		private CreatorRepository $creators,
		private AlertEventRepository $events,
		private AlertApiEvents $shape,
		private AlertApiQueue $queue,
		private AlertOverlays $overlays,
		private AlertApiAccess $access
	) {
	}

	/**
	 * Whether bots can fetch alerts now.
	 */
	public function available(): bool {
		return $this->api->enabled() && $this->availability->sending();
	}

	/**
	 * Whether a key's creator is active and may use API keys (step 25.12.4).
	 *
	 * @param AlertApiKey $key Key.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function creator_active( AlertApiKey $key ): bool {
		return true === $this->creators->find( $key->creator_id )?->is_active() && $this->access->keys( $key->creator_id );
	}

	/**
	 * Whether a creator may use API keys.
	 *
	 * @param int $creator_id Creator.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function allowed( int $creator_id ): bool {
		return $this->access->keys( $creator_id );
	}

	/**
	 * The answer for a key.
	 *
	 * @param AlertApiKey $key   Key.
	 * @param int|null    $after Cursor: the last id the bot has; null to start from now.
	 * @param int         $limit Most alerts (1–MAX_LIMIT).
	 * @return array{cursor: int, events: list<array<string, mixed>>}
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function read( AlertApiKey $key, ?int $after, int $limit = self::LIMIT ): array {
		$queued = $this->queue->drain( $key->id );

		if ( null === $after ) {
			return array(
				'cursor' => $this->events->latest_id( $key->creator_id ),
				'events' => $queued,
			);
		}

		$cursor = max( 0, $after );
		$events = array();

		foreach ( $this->events->after( $key->creator_id, $cursor, max( 1, min( self::MAX_LIMIT, $limit ) ) ) as $event ) {
			$events[] = $this->shape->event( $event, AlertApiEvents::PURCHASE );
			$cursor   = max( $cursor, $event->id );
		}

		return array(
			'cursor' => $cursor,
			'events' => array_merge( $events, $queued ),
		);
	}

	/**
	 * Queues a test event for the creator's bots.
	 *
	 * @param int $creator_id Creator.
	 * @return int Keys that will get it.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function send_test( int $creator_id ): int {
		return $this->queue->push( $creator_id, array( $this->shape->event( $this->overlays->test_event( $creator_id ), AlertApiEvents::TEST ) ) );
	}

	/**
	 * Queues resent alerts for the creator's bots (the history's resend);
	 * nothing while the API is off.
	 *
	 * @phpstan-param list<AlertEvent> $events
	 *
	 * @param int          $creator_id Creator.
	 * @param AlertEvent[] $events     The creator's alerts.
	 * @return int Keys that will get them.
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function resend( int $creator_id, array $events ): int {
		if ( array() === $events || ! $this->api->enabled() || ! $this->access->keys( $creator_id ) ) {
			return 0;
		}

		return $this->queue->push( $creator_id, array_map( fn( AlertEvent $event ): array => $this->shape->event( $event, AlertApiEvents::RESEND ), $events ) );
	}
}
