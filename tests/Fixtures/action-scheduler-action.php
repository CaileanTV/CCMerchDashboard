<?php
/**
 * Stand-in for Action Scheduler's action class (args only). Loaded only in
 * isolated test processes (a class cannot be undone); PHPStan uses
 * tests/Stubs/woocommerce.stub instead.
 *
 * @package MerchBoard
 */

/**
 * An action with its arguments.
 */
class ActionScheduler_Action {

	/**
	 * Arguments.
	 *
	 * @var array<mixed>
	 */
	private array $args;

	/**
	 * Constructor (the real one also takes a schedule and a group).
	 *
	 * @param string       $hook Hook.
	 * @param array<mixed> $args Arguments.
	 */
	public function __construct( string $hook, array $args = array() ) {
		unset( $hook );
		$this->args = $args;
	}

	/**
	 * The arguments.
	 *
	 * @return array<mixed>
	 */
	public function get_args(): array {
		return $this->args;
	}
}
