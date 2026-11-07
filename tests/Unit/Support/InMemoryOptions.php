<?php
/**
 * In-memory options and transients.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use Brain\Monkey\Functions;

/**
 * Backs the WordPress option and transient functions with arrays, with the
 * same semantics the code relies on (add_option() fails when the option exists).
 */
final class InMemoryOptions {

	/**
	 * Stored options.
	 *
	 * @var array<string, mixed>
	 */
	public array $options = array();

	/**
	 * Stored transients.
	 *
	 * @var array<string, mixed>
	 */
	public array $transients = array();

	/**
	 * Expiration (seconds) passed to set_transient(), per transient.
	 *
	 * @var array<string, int>
	 */
	public array $transient_expiration = array();

	/**
	 * Option names passed to update_option(), in order.
	 *
	 * @var list<string>
	 */
	public array $updates = array();

	/**
	 * Installs the Brain Monkey aliases. Call inside a test (after setUp).
	 *
	 * @param array<string, mixed> $options Initial options.
	 */
	public static function install( array $options = array() ): self {
		$lock_db         = new RecordingWpdb();
		$lock_db->var    = '1';
		$GLOBALS['wpdb'] = $lock_db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test-only database double.

		$store                   = new self();
		$store->options          = $options;
		$lock_db->var_callback   = static fn( string $sql ): mixed => str_starts_with( $sql, 'SELECT option_value' )
			? ( isset( $store->options['merchboard_settings'] ) ? serialize( $store->options['merchboard_settings'] ) : null ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Models WordPress option storage.
			: $lock_db->var;
		$lock_db->query_callback = static function ( array $prepared ) use ( $store ): int {
			$args   = $prepared['args'];
			$exists = array_key_exists( 'merchboard_settings', $store->options );
			if ( str_starts_with( $prepared['query'], 'INSERT IGNORE' ) ) {
				if ( $exists ) {
					return 0;
				}
				$value = $args[2];
			} else {
				if ( ! $exists || serialize( $store->options['merchboard_settings'] ) !== $args[4] ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Models exact stored bytes.
					return 0;
				}
				$value = $args[1];
			}
			$store->options['merchboard_settings'] = unserialize( (string) $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Synthetic fixture only.
			$store->updates[]                      = 'merchboard_settings';
			return 1;
		};
		Functions\when( 'maybe_serialize' )->alias( 'serialize' );

		Functions\when( 'maybe_unserialize' )->alias( static fn( string $raw ): mixed => unserialize( $raw, array( 'allowed_classes' => false ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Synthetic test data only.

		Functions\when( 'get_option' )->alias(
			static fn( string $name, mixed $fallback = false ): mixed => $store->options[ $name ] ?? $fallback
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $name, mixed $value ) use ( $store ): bool {
				$store->updates[]        = $name;
				$store->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( string $name, mixed $value = '' ) use ( $store ): bool {
				if ( array_key_exists( $name, $store->options ) ) {
					return false;
				}
				$store->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $name ) use ( $store ): bool {
				unset( $store->options[ $name ] );
				return true;
			}
		);
		// The migration lock bypasses the option cache and only clears it.
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_transient' )->alias(
			static fn( string $name ): mixed => $store->transients[ $name ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $name, mixed $value, int $expiration = 0 ) use ( $store ): bool {
				$store->transients[ $name ]           = $value;
				$store->transient_expiration[ $name ] = $expiration;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( string $name ) use ( $store ): bool {
				unset( $store->transients[ $name ] );
				return true;
			}
		);

		return $store;
	}
}
