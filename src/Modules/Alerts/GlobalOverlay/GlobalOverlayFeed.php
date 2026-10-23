<?php
/**
 * The public feed of the global alert overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\GlobalOverlay;

use Closure;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertEventRepository;
use MerchBoard\Modules\Alerts\AlertsAvailability;
use MerchBoard\Modules\Alerts\Overlay\AlertRenderer;
use MerchBoard\Modules\Alerts\Overlay\OverlayFeed;
use MerchBoard\Modules\Alerts\Overlay\OverlayId;
use MerchBoard\Modules\Alerts\Overlay\OverlayTestQueue;

defined( 'ABSPATH' ) || exit;

/**
 * The global overlay's feed (step 25.9.4), with the same cursor, long-poll
 * and version rules as a creator's (OverlayFeed), over every creator's
 * alerts. It is found only by its own secret id, and only while the
 * administrator switched it on and alerts are being sent (kill switch on,
 * MerchAlert inactive); otherwise null (404), without saying why. Its test
 * alerts come from its own queue (scope 'global'), never a creator's.
 */
final class GlobalOverlayFeed {

	/**
	 * Sleeps for a number of seconds.
	 *
	 * @var Closure(int): void
	 */
	private Closure $sleep;

	/**
	 * Constructor.
	 *
	 * @param GlobalOverlayStore        $store        The global overlay.
	 * @param AlertEventRepository      $events       Alerts.
	 * @param AlertRenderer             $renderer     Alert rendering.
	 * @param AlertsAvailability        $availability Kill switch and MerchAlert guard.
	 * @param OverlayTestQueue          $tests        Test alerts (scope 'global').
	 * @param GlobalOverlaySounds|null  $sounds       Sounds; null for none.
	 * @param (Closure(int): void)|null $sleep        Sleep; defaults to sleep().
	 */
	public function __construct(
		private GlobalOverlayStore $store,
		private AlertEventRepository $events,
		private AlertRenderer $renderer,
		private AlertsAvailability $availability,
		private OverlayTestQueue $tests,
		private ?GlobalOverlaySounds $sounds = null,
		?Closure $sleep = null
	) {
		$this->sleep = $sleep ?? static function ( int $seconds ): void {
			sleep( $seconds );
		};
	}

	/**
	 * The feed answer, or null when the overlay is unknown, off or not sending.
	 *
	 * @param string   $overlay_id Secret overlay id.
	 * @param int|null $after      Cursor; null for the first request.
	 * @param bool     $wait       Whether to wait for new alerts.
	 * @return array{cursor: int, version: string, alerts: list<array{id: int, image: string, text: string, secondary: string, sound: string}>}|null
	 * @throws DatabaseException When the database cannot be read.
	 */
	public function read( string $overlay_id, ?int $after, bool $wait ): ?array {
		$overlay = $this->active( $overlay_id );

		if ( null === $overlay ) {
			return null;
		}

		if ( null === $after ) {
			return self::answer( $this->events->latest_id_all(), $overlay, array() );
		}

		$checks  = $wait ? OverlayFeed::WAIT_SECONDS : 0;
		$version = $overlay->version();

		for ( $check = 0; ; ++$check ) {
			$events = $this->events->after_all( $after, OverlayFeed::LIMIT );
			$tests  = $this->tests->drain( GlobalOverlay::QUEUE );

			if ( array() !== $events || array() !== $tests || $check >= $checks ) {
				$last = end( $events );

				return self::answer( false === $last ? $after : $last->id, $overlay, array_merge( array_map( fn( AlertEvent $event ): array => $this->render( $overlay, $event ), $events ), $tests ) );
			}

			( $this->sleep )( 1 );

			// The settings, the id or the switch may have changed while waiting.
			$overlay = $this->active( $overlay_id );

			if ( null === $overlay ) {
				return null;
			}

			if ( $overlay->version() !== $version ) {
				return self::answer( $after, $overlay, array() );
			}
		}
	}

	/**
	 * The overlay for its secret id, if it is switched on and alerts are being sent.
	 *
	 * @param string $overlay_id Secret overlay id.
	 */
	public function active( string $overlay_id ): ?GlobalOverlay {
		if ( ! OverlayId::valid( $overlay_id ) ) {
			return null;
		}

		$overlay = $this->store->find();

		return null !== $overlay && $overlay->enabled && hash_equals( $overlay->overlay_id, $overlay_id ) && $this->availability->sending() ? $overlay : null;
	}

	/**
	 * One alert as the page shows it (also used for test alerts and replays).
	 *
	 * @param GlobalOverlay $overlay Overlay.
	 * @param AlertEvent    $event   Alert.
	 * @return array{id: int, image: string, text: string, secondary: string, sound: string}
	 */
	public function render( GlobalOverlay $overlay, AlertEvent $event ): array {
		return $this->renderer->render( $overlay->settings, $event, null === $this->sounds ? '' : $this->sounds->url_for( $overlay, $event ) );
	}

	/**
	 * The answer.
	 *
	 * @param int                                                                                 $cursor  Cursor.
	 * @param GlobalOverlay                                                                       $overlay Overlay.
	 * @param list<array{id: int, image: string, text: string, secondary: string, sound: string}> $alerts  Alerts.
	 * @return array{cursor: int, version: string, alerts: list<array{id: int, image: string, text: string, secondary: string, sound: string}>}
	 */
	private static function answer( int $cursor, GlobalOverlay $overlay, array $alerts ): array {
		return array(
			'cursor'  => $cursor,
			'version' => $overlay->version(),
			'alerts'  => $alerts,
		);
	}
}
