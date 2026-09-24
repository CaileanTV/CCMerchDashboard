<?php
/**
 * Module test double.
 *
 * @package CCMerch
 */

declare( strict_types=1 );

namespace CCMerch\Tests\Unit;

use CCMerch\Core\Container;
use CCMerch\Core\Module;

/**
 * Records register() calls.
 */
final class RecordingModule implements Module {

	/**
	 * Containers passed to register().
	 *
	 * @var list<Container>
	 */
	public array $registered_with = array();

	/**
	 * Constructor.
	 *
	 * @param string $module_id Module id.
	 */
	public function __construct( private string $module_id ) {
	}

	/**
	 * Module id.
	 */
	public function id(): string {
		return $this->module_id;
	}

	/**
	 * Records the call.
	 *
	 * @param Container $container Shared service container.
	 */
	public function register( Container $container ): void {
		$this->registered_with[] = $container;
	}
}
