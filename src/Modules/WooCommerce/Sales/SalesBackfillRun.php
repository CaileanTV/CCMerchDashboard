<?php
/**
 * State of a backfill run between two pages.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use InvalidArgumentException;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\WooCommerce\ReconciliationRunId;

defined( 'ABSPATH' ) || exit;

/**
 * The cursor of a backfill run, carried in the Action Scheduler payload
 * (ADR-038, status note "ROADMAP trin 7", D6): run id, the counted statuses
 * taken when the run started (so the pages stay the same query), page size,
 * offset of the next page, counts so far and the user who started it.
 *
 * The offset only advances after a page was processed. `wc_get_orders()` has
 * no portable "id greater than" cursor, so pages use an offset (D2): an order
 * deleted during a run can shift a later order past the cursor; a later run
 * registers it (the backfill is idempotent).
 */
final readonly class SalesBackfillRun {

	/**
	 * Default orders per page.
	 */
	public const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Most orders per page.
	 */
	public const MAX_BATCH_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<string> $statuses
	 *
	 * @param ReconciliationRunId $run_id          Run id.
	 * @param string[]            $statuses        Counted statuses at the start (without `wc-`).
	 * @param int                 $batch_size      Orders per page.
	 * @param int                 $offset          Orders already scanned (the next page starts here).
	 * @param SalesBackfillTally  $tally           Counts so far.
	 * @param int|null            $trigger_user_id User who started the run, if known.
	 * @param bool                $complete        Whether the last page was processed.
	 */
	private function __construct(
		public ReconciliationRunId $run_id,
		public array $statuses,
		public int $batch_size,
		public int $offset,
		public SalesBackfillTally $tally,
		public ?int $trigger_user_id,
		public bool $complete = false
	) {
	}

	/**
	 * A new run before its first page.
	 *
	 * @phpstan-param list<string> $statuses
	 *
	 * @param string[] $statuses        Counted statuses.
	 * @param int      $batch_size      Orders per page.
	 * @param int|null $trigger_user_id User who starts it, if known.
	 * @throws InvalidArgumentException When the statuses or the page size are invalid.
	 */
	public static function start( array $statuses, int $batch_size, ?int $trigger_user_id ): self {
		return new self(
			ReconciliationRunId::generate(),
			self::statuses( $statuses ),
			self::batch_size( $batch_size ),
			0,
			SalesBackfillTally::empty(),
			null !== $trigger_user_id && $trigger_user_id > 0 ? $trigger_user_id : null
		);
	}

	/**
	 * The run after a processed page.
	 *
	 * @param int                $scanned Orders on the page.
	 * @param SalesBackfillTally $tally   Counts including the page.
	 */
	public function advanced( int $scanned, SalesBackfillTally $tally ): self {
		return new self( $this->run_id, $this->statuses, $this->batch_size, $this->offset + $scanned, $tally, $this->trigger_user_id, $scanned < $this->batch_size );
	}

	/**
	 * The job payload.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'run_id'          => $this->run_id->value,
			'statuses'        => $this->statuses,
			'batch_size'      => $this->batch_size,
			'offset'          => $this->offset,
			'tally'           => $this->tally->to_array(),
			'trigger_user_id' => $this->trigger_user_id,
		);
	}

	/**
	 * A run from a job payload.
	 *
	 * @param mixed $payload Job payload.
	 * @throws InvalidArgumentException When the payload is not a valid run.
	 */
	public static function from_array( mixed $payload ): self {
		if ( ! is_array( $payload ) || ! is_string( $payload['run_id'] ?? null ) || ! is_array( $payload['statuses'] ?? null )
			|| ! is_int( $payload['batch_size'] ?? null ) || ! is_int( $payload['offset'] ?? null ) || $payload['offset'] < 0
			|| ! is_array( $payload['tally'] ?? null ) ) {
			throw new InvalidArgumentException( 'Invalid sales backfill run.' );
		}

		$user = $payload['trigger_user_id'] ?? null;

		if ( null !== $user && ( ! is_int( $user ) || $user < 1 ) ) {
			throw new InvalidArgumentException( 'Invalid sales backfill run.' );
		}

		return new self(
			new ReconciliationRunId( $payload['run_id'] ),
			self::statuses( $payload['statuses'] ),
			self::batch_size( $payload['batch_size'] ),
			$payload['offset'],
			SalesBackfillTally::from_array( $payload['tally'] ),
			$user
		);
	}

	/**
	 * A valid page size.
	 *
	 * @param int $batch_size Orders per page.
	 * @throws InvalidArgumentException When it is out of range.
	 */
	public static function batch_size( int $batch_size ): int {
		if ( $batch_size < 1 || $batch_size > self::MAX_BATCH_SIZE ) {
			throw new InvalidArgumentException( 'Invalid sales backfill batch size.' );
		}

		return $batch_size;
	}

	/**
	 * Valid, distinct status slugs (the format of Settings::valid_status_slug()).
	 *
	 * @param array<mixed> $statuses Statuses.
	 * @return list<string>
	 * @throws InvalidArgumentException When one is invalid or none is left.
	 */
	private static function statuses( array $statuses ): array {
		$valid = array();

		foreach ( $statuses as $status ) {
			if ( ! is_string( $status ) || ! Settings::valid_status_slug( $status ) ) {
				throw new InvalidArgumentException( 'Invalid sales backfill status.' );
			}

			if ( ! CountedOrderStatuses::denied( $status ) && ! in_array( $status, $valid, true ) ) {
				$valid[] = $status;
			}
		}

		if ( array() === $valid ) {
			throw new InvalidArgumentException( 'A sales backfill needs at least one counted status.' );
		}

		return $valid;
	}
}
