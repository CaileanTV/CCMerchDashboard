<?php
/**
 * Limits on the overlays' long-polls.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

use MerchBoard\Core\Database\NamedLock;
use MerchBoard\Core\Http\ClientIp;

defined( 'ABSPATH' ) || exit;

/**
 * What keeps the public feeds (a creator's overlay and the global one) from
 * tying up the web server's workers (ADR-116, security review 2026-11-21):
 *
 * - **Slots:** at most SLOTS requests of one overlay id wait at the same
 *   time, each holding a MySQL named lock while it waits. A request that
 *   gets no slot answers at once, as without `wait`; the page then asks
 *   again after a short pause. Without named locks (SQLite) no request
 *   waits.
 * - **Address:** at most PER_ADDRESS feed requests a minute per visitor
 *   address (ClientIp), across all ids, before the id is looked up, so
 *   guessing ids is limited too. The counter is a transient keyed by a hash
 *   of the address.
 */
final class OverlayLongPoll {

	/**
	 * Requests of one overlay id that may wait at the same time.
	 */
	public const SLOTS = 2;

	/**
	 * Feed requests a minute per visitor address. A streamer's PC runs a
	 * few overlays: each long-polls about 8 times a minute, or asks every 5
	 * seconds without a slot.
	 */
	public const PER_ADDRESS = 120;

	/**
	 * Lock name prefix.
	 */
	private const PREFIX = 'merchboard_feed_';

	/**
	 * Constructor.
	 *
	 * @param NamedLock|null     $locks     Named locks; null for none (no request waits).
	 * @param OverlayRateLimiter $addresses Requests per visitor address.
	 */
	public function __construct( private ?NamedLock $locks, private OverlayRateLimiter $addresses ) {
	}

	/**
	 * Counts a request of the visitor and tells whether it is allowed.
	 *
	 * @param string|null $address The visitor's address; null for ClientIp::address().
	 */
	public function allow_address( ?string $address = null ): bool {
		return $this->addresses->allow( 'address|' . ( $address ?? ClientIp::address() ) );
	}

	/**
	 * A slot to wait in, or null when all of the overlay's slots are taken.
	 *
	 * @param string $overlay_id Secret overlay id (only a hash names the lock).
	 */
	public function slot( string $overlay_id ): ?string {
		if ( null === $this->locks ) {
			return null;
		}

		$base = self::PREFIX . substr( hash( 'sha256', $overlay_id ), 0, 24 ) . '_';

		for ( $slot = 1; $slot <= self::SLOTS; $slot++ ) {
			if ( $this->locks->acquire( $base . $slot, 0 ) ) {
				return $base . $slot;
			}
		}

		return null;
	}

	/**
	 * Frees a slot.
	 *
	 * @param string|null $slot The slot from slot(); null for none.
	 */
	public function release( ?string $slot ): void {
		if ( null !== $slot && null !== $this->locks ) {
			$this->locks->release( $slot );
		}
	}
}
