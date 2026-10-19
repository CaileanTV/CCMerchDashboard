<?php
/**
 * Request limit of an overlay's public feed.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * At most LIMIT feed requests per overlay id per minute (ADR-065 pkt. 4).
 * A browser source long-polls about 8 times a minute and falls back to a
 * request every 5 seconds, so the limit leaves room for a few open copies
 * of the overlay but stops anyone who knows the id from tying up the
 * server. The counter is a transient keyed by a hash of the id, so the
 * secret never appears as an option name.
 */
final class OverlayRateLimiter {

	/**
	 * Requests per overlay id per window.
	 */
	public const LIMIT = 60;

	/**
	 * Window in seconds.
	 */
	public const WINDOW = 60;

	/**
	 * The current Unix time.
	 *
	 * @var Closure(): int
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @param (Closure(): int)|null $clock Current Unix time; defaults to time().
	 */
	public function __construct( ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * Counts a request and tells whether it is allowed.
	 *
	 * @param string $overlay_id Secret overlay id.
	 */
	public function allow( string $overlay_id ): bool {
		$key   = 'merchboard_alerts_rl_' . substr( hash( 'sha256', $overlay_id ), 0, 32 );
		$now   = ( $this->clock )();
		$state = get_transient( $key );
		$start = is_array( $state ) && is_int( $state['start'] ?? null ) ? $state['start'] : 0;
		$count = is_array( $state ) && is_int( $state['count'] ?? null ) ? $state['count'] : 0;

		if ( $now - $start >= self::WINDOW || $now < $start ) {
			$start = $now;
			$count = 0;
		}

		if ( $count >= self::LIMIT ) {
			return false;
		}

		set_transient(
			$key,
			array(
				'start' => $start,
				'count' => $count + 1,
			),
			max( 1, self::WINDOW - ( $now - $start ) )
		);

		return true;
	}
}
