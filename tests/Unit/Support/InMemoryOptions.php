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
		$store          = new self();
		$store->options = $options;

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
		Functions\when( 'get_transient' )->alias(
			static fn( string $name ): mixed => $store->transients[ $name ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $name, mixed $value ) use ( $store ): bool {
				$store->transients[ $name ] = $value;
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
