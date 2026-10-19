<?php
/**
 * Test alerts waiting for an overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Overlay;

defined( 'ABSPATH' ) || exit;

/**
 * Rendered test alerts for an overlay, kept for five minutes until the
 * overlay's feed picks them up (MerchAlert's pending queue, ADR-065 pkt. 3).
 * At most MAX alerts wait; older ones are dropped. The transient is keyed
 * by a hash of the overlay's row id, never the secret id, and read past
 * WordPress' per-request option cache, so a long-poll that started before
 * the test sees it within a second.
 */
final class OverlayTestQueue {

	/**
	 * Longest queue.
	 */
	public const MAX = 5;

	/**
	 * Seconds a test alert waits.
	 */
	public const TTL = 300;

	/**
	 * Adds a rendered alert.
	 *
	 * @param int                                                                           $overlay Overlay row id.
	 * @param array{id: int, image: string, text: string, secondary: string, sound: string} $alert   Rendered alert.
	 */
	public function push( int $overlay, array $alert ): void {
		$queue   = $this->read( $overlay );
		$queue[] = $alert;

		set_transient( self::key( $overlay ), array_slice( $queue, -self::MAX ), self::TTL );
	}

	/**
	 * Takes the waiting alerts, oldest first.
	 *
	 * @param int $overlay Overlay row id.
	 * @return list<array{id: int, image: string, text: string, secondary: string, sound: string}>
	 */
	public function drain( int $overlay ): array {
		$queue = $this->read( $overlay );

		if ( array() !== $queue ) {
			delete_transient( self::key( $overlay ) );
		}

		return $queue;
	}

	/**
	 * The waiting alerts, read fresh.
	 *
	 * @param int $overlay Overlay row id.
	 * @return list<array{id: int, image: string, text: string, secondary: string, sound: string}>
	 */
	private function read( int $overlay ): array {
		$key = self::key( $overlay );

		wp_cache_delete( '_transient_' . $key, 'options' );
		wp_cache_delete( '_transient_timeout_' . $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$stored = get_transient( $key );
		$queue  = array();

		foreach ( is_array( $stored ) ? $stored : array() as $alert ) {
			if ( is_array( $alert ) && is_string( $alert['text'] ?? null ) && is_string( $alert['image'] ?? null ) && is_string( $alert['secondary'] ?? null ) && is_string( $alert['sound'] ?? null ) ) {
				$queue[] = array(
					'id'        => 0,
					'image'     => $alert['image'],
					'text'      => $alert['text'],
					'secondary' => $alert['secondary'],
					'sound'     => $alert['sound'],
				);
			}
		}

		return $queue;
	}

	/**
	 * The transient name.
	 *
	 * @param int $overlay Overlay row id.
	 */
	private static function key( int $overlay ): string {
		return 'merchboard_alerts_test_' . substr( hash( 'sha256', 'overlay:' . $overlay ), 0, 24 );
	}
}
