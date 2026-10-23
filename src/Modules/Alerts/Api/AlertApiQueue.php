<?php
/**
 * Tests and resends waiting for the creator's bots.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Api;

use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * Test events and resends for the alert API (ADR-073), kept five minutes
 * until each of the creator's keys fetches them: one queue per working key,
 * so two bots both get them. At most MAX wait per key; older ones are
 * dropped. The transient is keyed by a hash of the key's row id, never the
 * key, and read past WordPress' per-request option cache (as
 * OverlayTestQueue).
 */
class AlertApiQueue {

	/**
	 * Longest queue per key.
	 */
	public const MAX = 20;

	/**
	 * Seconds an event waits.
	 */
	public const TTL = 300;

	/**
	 * Constructor.
	 *
	 * @param AlertApiKeyRepository $keys The creator's keys.
	 */
	public function __construct( private AlertApiKeyRepository $keys ) {
	}

	/**
	 * Adds events for every working key of the creator.
	 *
	 * @phpstan-param list<array<string, mixed>> $events
	 *
	 * @param int                    $creator_id Creator.
	 * @param array<string, mixed>[] $events     Events (AlertApiEvents::event()).
	 * @return int Keys that will get them.
	 * @throws DatabaseException When the keys cannot be read.
	 */
	public function push( int $creator_id, array $events ): int {
		$keys = $this->keys->active_for( $creator_id );

		foreach ( $keys as $key ) {
			set_transient( self::name( $key->id ), array_slice( array_merge( $this->read( $key->id ), $events ), -self::MAX ), self::TTL );
		}

		return count( $keys );
	}

	/**
	 * Takes a key's waiting events, oldest first.
	 *
	 * @param int $key_id Key.
	 * @return list<array<string, mixed>>
	 */
	public function drain( int $key_id ): array {
		$queue = $this->read( $key_id );

		if ( array() !== $queue ) {
			delete_transient( self::name( $key_id ) );
		}

		return $queue;
	}

	/**
	 * A key's waiting events, read fresh.
	 *
	 * @param int $key_id Key.
	 * @return list<array<string, mixed>>
	 */
	private function read( int $key_id ): array {
		$name = self::name( $key_id );

		wp_cache_delete( '_transient_' . $name, 'options' );
		wp_cache_delete( '_transient_timeout_' . $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$stored = get_transient( $name );
		$queue  = array();

		foreach ( is_array( $stored ) ? $stored : array() as $event ) {
			if ( is_array( $event ) && is_string( $event['kind'] ?? null ) ) {
				/**
				 * An event as push() stored it.
				 *
				 * @var array<string, mixed> $event
				 */
				$queue[] = $event;
			}
		}

		return $queue;
	}

	/**
	 * The transient name.
	 *
	 * @param int $key_id Key.
	 */
	private static function name( int $key_id ): string {
		return 'merchboard_alerts_api_q_' . substr( hash( 'sha256', 'api:' . $key_id ), 0, 24 );
	}
}
