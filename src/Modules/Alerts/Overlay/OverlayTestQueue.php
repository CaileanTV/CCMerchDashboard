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
 * the test sees it within a second. The global overlay (step 25.9.4) has its
 * own scope, so its queue never shares a transient with a creator's overlay
 * of the same row id.
 */
final class OverlayTestQueue {

	/**
	 * Longest queue.
	 */
	public const MAX = 20;

	/**
	 * Seconds a test alert waits.
	 */
	public const TTL = 300;

	/**
	 * Constructor.
	 *
	 * @param string $scope Which overlays: 'overlay' for the creators' (the default), 'global' for the global overlay.
	 */
	public function __construct( private string $scope = 'overlay' ) {
	}

	/**
	 * Adds a rendered alert.
	 *
	 * @param int                                                                           $overlay Overlay row id.
	 * @param array{id: int, image: string, text: string, secondary: string, sound: string} $alert   Rendered alert.
	 */
	public function push( int $overlay, array $alert ): void {
		$queue   = $this->read( $overlay );
		$queue[] = $alert;

		set_transient( $this->key( $overlay ), array_slice( $queue, -self::MAX ), self::TTL );
	}

	/**
	 * Drops the waiting alerts ("Stop all alerts", step 25.7).
	 *
	 * @param int $overlay Overlay row id.
	 */
	public function clear( int $overlay ): void {
		delete_transient( $this->key( $overlay ) );
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
			delete_transient( $this->key( $overlay ) );
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
		$key = $this->key( $overlay );

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
	private function key( int $overlay ): string {
		return 'merchboard_alerts_test_' . substr( hash( 'sha256', $this->scope . ':' . $overlay ), 0, 24 );
	}
}
