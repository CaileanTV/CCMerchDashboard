<?php
/**
 * Contract for internal modules.
 *
 * @package CCMerch
 */

declare( strict_types=1 );

namespace CCMerch\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A self-contained feature area under src/Modules/<Name>/ (see docs/MODULES.md).
 */
interface Module {

	/**
	 * Stable, unique id, e.g. 'creators'. Used in filters, settings and logs.
	 */
	public function id(): string;

	/**
	 * Registers services and hooks. Must not query the database, produce output
	 * or do other heavy work; that belongs in the hooked callbacks.
	 *
	 * @param Container $container Shared service container.
	 */
	public function register( Container $container ): void;
}
