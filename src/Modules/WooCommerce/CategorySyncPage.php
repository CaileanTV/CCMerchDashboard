<?php
/**
 * The state of a real reconciliation run between pages.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the next page of a run needs (ADR-033): the run id, scope and
 * trigger, the counts so far, the keyset cursor and whether the run is
 * complete. Immutable; an Action Scheduler job carries it as its payload
 * (to_array()/from_array()), so a later or repeated job continues the same
 * run without shared state.
 */
final readonly class CategorySyncPage {

	/**
	 * Constructor.
	 *
	 * @param ReconciliationRunId           $run_id          Run id.
	 * @param ReconciliationScope           $scope           Which products.
	 * @param CategoryReconciliationTrigger $trigger         Why the run happens.
	 * @param int|null                      $trigger_user_id User who started it, if known.
	 * @param CategoryReconciliationResult  $result          Counts so far.
	 * @param int                           $after_id        Last processed product id (0: none).
	 * @param int                           $pages           Non-empty pages so far.
	 * @param bool                          $complete        Whether the last page was processed.
	 */
	public function __construct(
		public ReconciliationRunId $run_id,
		public ReconciliationScope $scope,
		public CategoryReconciliationTrigger $trigger,
		public ?int $trigger_user_id,
		public CategoryReconciliationResult $result,
		public int $after_id,
		public int $pages,
		public bool $complete
	) {
	}

	/**
	 * The first page of a new run.
	 *
	 * @param ReconciliationScope           $scope           Which products.
	 * @param CategoryReconciliationTrigger $trigger         Why the run happens.
	 * @param int|null                      $trigger_user_id User who started it, if known.
	 */
	public static function start( ReconciliationScope $scope, CategoryReconciliationTrigger $trigger, ?int $trigger_user_id ): self {
		return new self( ReconciliationRunId::generate(), $scope, $trigger, null !== $trigger_user_id && $trigger_user_id > 0 ? $trigger_user_id : null, CategoryReconciliationResult::empty(), 0, 0, false );
	}

	/**
	 * Job payload (scalars and arrays only).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'run_id'          => $this->run_id->value,
			'scope'           => $this->scope->to_array(),
			'trigger'         => $this->trigger->value,
			'trigger_user_id' => $this->trigger_user_id,
			'counts'          => $this->result->to_array(),
			'after_id'        => $this->after_id,
			'pages'           => $this->pages,
		);
	}

	/**
	 * Rebuilds an incomplete run from a job payload, validating every field.
	 *
	 * @param array<mixed> $data Payload.
	 * @throws InvalidArgumentException When the payload is not a valid run.
	 */
	public static function from_array( array $data ): self {
		$trigger = is_string( $data['trigger'] ?? null ) ? CategoryReconciliationTrigger::tryFrom( $data['trigger'] ) : null;
		$user_id = $data['trigger_user_id'] ?? null;

		if ( ! is_string( $data['run_id'] ?? null ) || ! is_array( $data['scope'] ?? null ) || null === $trigger
			|| ( null !== $user_id && ( ! is_int( $user_id ) || $user_id < 1 ) )
			|| ! is_array( $data['counts'] ?? null ) || ! is_int( $data['after_id'] ?? null ) || $data['after_id'] < 0
			|| ! is_int( $data['pages'] ?? null ) || $data['pages'] < 0 ) {
			throw new InvalidArgumentException( 'Invalid reconciliation run payload.' );
		}

		return new self(
			new ReconciliationRunId( $data['run_id'] ),
			ReconciliationScope::from_array( $data['scope'] ),
			$trigger,
			$user_id,
			CategoryReconciliationResult::from_array( $data['counts'] ),
			$data['after_id'],
			$data['pages'],
			false
		);
	}
}
