<?php
/**
 * Minimal lazy service container.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Core;

use LogicException;

defined( 'ABSPATH' ) || exit;

/**
 * Holds service factories and creates each service once, on first use.
 *
 * Service ids are class or interface names, so `get()` is type-safe for static
 * analysis. This is deliberately not a DI framework: no autowiring, no
 * configuration, no tagging (see docs/MODULES.md).
 */
final class Container {


	/**
	 * Registered factories keyed by service id.
	 *
	 * @var array<string, callable(Container): object>
	 */
	private array $factories = array();

	/**
	 * Created services keyed by service id.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Service ids currently being created, to detect circular dependencies.
	 *
	 * @var array<string, true>
	 */
	private array $resolving = array();

	/**
	 * Registers a factory. Replacing a service that has already been created is
	 * not allowed, since other services may hold the old instance.
	 *
	 * @phpstan-template T of object
	 * @phpstan-param class-string<T> $id
	 * @phpstan-param callable(Container): T $factory
	 *
	 * @param string   $id      Service id (class or interface name).
	 * @param callable $factory Creates the service; receives the container.
	 * @throws LogicException When the service has already been created.
	 */
	public function set( string $id, callable $factory ): void {
		if ( isset( $this->instances[ $id ] ) ) {
			throw new LogicException( sprintf( 'Service "%s" has already been created and cannot be replaced.', $id ) );
		}

		$this->factories[ $id ] = $factory;
	}

	/**
	 * Whether a service is registered.
	 *
	 * @param string $id Service id.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Returns the service, creating it on first access.
	 *
	 * @phpstan-template T of object
	 * @phpstan-param class-string<T> $id
	 * @phpstan-return T
	 *
	 * @param string $id Service id (class or interface name).
	 * @return object The service.
	 * @throws LogicException When the service is unknown, circular or of the wrong type.
	 */
	public function get( string $id ): object {
		$cached = $this->instances[ $id ] ?? null;

		if ( $cached instanceof $id ) {
			return $cached;
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new LogicException( sprintf( 'Service "%s" is not registered.', $id ) );
		}

		if ( isset( $this->resolving[ $id ] ) ) {
			throw new LogicException( sprintf( 'Circular dependency while creating service "%s".', $id ) );
		}

		$this->resolving[ $id ] = true;

		try {
			$service = ( $this->factories[ $id ] )( $this );
		} finally {
			unset( $this->resolving[ $id ] );
		}

		if ( ! $service instanceof $id ) {
			throw new LogicException( sprintf( 'Factory for service "%s" returned an instance of "%s".', $id, get_class( $service ) ) );
		}

		$this->instances[ $id ] = $service;

		return $service;
	}
}
