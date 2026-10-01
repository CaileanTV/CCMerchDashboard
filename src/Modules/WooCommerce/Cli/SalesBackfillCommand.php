<?php
/**
 * `wp merchboard sales-backfill`.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Cli;

use InvalidArgumentException;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatuses;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfill;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillRun;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillScheduler;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillStatus;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillTally;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Registers historical WooCommerce orders in the Merch Dashboard sales ledger.
 *
 * Only orders without ledger history are registered: existing ledger history
 * is never changed, and orders that do not count now get no rows. The real
 * run happens in the background with Action Scheduler, page by page; only one
 * run at a time. The dry run runs here and writes nothing.
 *
 * WP-CLI trusts whoever runs it (shell access); there is no separate
 * capability (ADR-038, status note "ROADMAP trin 7", D6). The output is
 * translatable (text domain `merchboard`); the help text is WP-CLI's docblock.
 */
final class SalesBackfillCommand {

	/**
	 * Constructor.
	 *
	 * @param SalesBackfill          $backfill  Dry run.
	 * @param SalesBackfillScheduler $scheduler Runs.
	 * @param CountedOrderStatuses   $counted   Statuses of the dry run.
	 * @param CliOutput              $out       Output.
	 */
	public function __construct(
		private SalesBackfill $backfill,
		private SalesBackfillScheduler $scheduler,
		private CountedOrderStatuses $counted,
		private CliOutput $out
	) {
	}

	/**
	 * Shows what a backfill would register, without writing anything.
	 *
	 * No ledger rows, no lock, no failure marker and no audit record.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : Orders per page (1-200).
	 * ---
	 * default: 50
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp merchboard sales-backfill dry-run
	 *
	 * @subcommand dry-run
	 *
	 * @param array<mixed> $args       Positional arguments (none).
	 * @param array<mixed> $assoc_args Options.
	 */
	public function dry_run( array $args, array $assoc_args ): void {
		$batch    = $this->options( $args, $assoc_args, array( 'batch-size' ) );
		$statuses = $this->counted->statuses();

		/* translators: %s: comma-separated order status slugs. */
		$this->out->line( sprintf( __( 'Counted statuses: %s', 'merchboard' ), implode( ', ', $statuses ) ) );

		try {
			$tally = $this->backfill->dry_run(
				$statuses,
				$batch,
				function ( int $scanned ): void {
					/* translators: %d: number of orders scanned so far. */
					$this->out->line( sprintf( __( 'Scanned %d orders…', 'merchboard' ), $scanned ) );
				}
			);
		} catch ( RuntimeException $e ) {
			/* translators: %s: technical reason. */
			$this->out->error( sprintf( __( 'The dry run stopped: %s', 'merchboard' ), $e->getMessage() ) );
		}

		$this->summary( $tally, true );
		$this->out->success( __( 'Dry run finished. Nothing was written.', 'merchboard' ) );
	}

	/**
	 * Starts a backfill run in the background (Action Scheduler).
	 *
	 * Refused while another backfill run is pending or running.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : Orders per page (1-200).
	 * ---
	 * default: 50
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp merchboard sales-backfill start
	 *
	 * @param array<mixed> $args       Positional arguments (none).
	 * @param array<mixed> $assoc_args Options.
	 */
	public function start( array $args, array $assoc_args ): void {
		$batch = $this->options( $args, $assoc_args, array( 'batch-size' ) );
		$user  = get_current_user_id();

		[ $outcome, $run ] = $this->scheduler->start( $batch, $user > 0 ? $user : null );

		if ( SalesBackfillScheduler::STARTED !== $outcome || null === $run ) {
			$this->out->error( self::refusal( $outcome ) );
		}

		/* translators: %s: comma-separated order status slugs. */
		$this->out->line( sprintf( __( 'Counted statuses: %s', 'merchboard' ), implode( ', ', $run->statuses ) ) );
		/* translators: 1: run id, 2: orders per page. */
		$this->out->success( sprintf( __( 'Backfill run %1$s queued (%2$d orders per page). The summary is written to the audit log when it finishes.', 'merchboard' ), $run->run_id->value, $run->batch_size ) );
	}

	/**
	 * Resumes a failed backfill run from its last cursor.
	 *
	 * A run stops when a page fails; its failed job keeps the cursor, and
	 * resume continues from there. There is no automatic retry. A run whose
	 * jobs were cancelled (deactivation, uninstall) can only be resumed while
	 * Action Scheduler still keeps the cancelled job; after its cleanup, start
	 * the backfill again (safe: it is idempotent).
	 *
	 * ## EXAMPLES
	 *
	 *     wp merchboard sales-backfill resume
	 *
	 * @param array<mixed> $args       Positional arguments (none).
	 * @param array<mixed> $assoc_args Options (none).
	 */
	public function resume( array $args, array $assoc_args ): void {
		$this->options( $args, $assoc_args, array() );

		[ $outcome, $run ] = $this->scheduler->resume();

		if ( SalesBackfillScheduler::RESUMED !== $outcome || null === $run ) {
			$this->out->error( self::refusal( $outcome ) );
		}

		/* translators: 1: run id, 2: position of the first order of the page. */
		$this->out->success( sprintf( __( 'Backfill run %1$s resumed at order %2$d.', 'merchboard' ), $run->run_id->value, $run->offset + 1 ) );
	}

	/**
	 * Shows whether a backfill run is active or can be resumed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp merchboard sales-backfill status
	 *
	 * @param array<mixed> $args       Positional arguments (none).
	 * @param array<mixed> $assoc_args Options (none).
	 */
	public function status( array $args, array $assoc_args ): void {
		$this->options( $args, $assoc_args, array() );

		if ( $this->scheduler->active() ) {
			$this->out->line( __( 'A backfill run is pending or running.', 'merchboard' ) );

			return;
		}

		$run = $this->scheduler->resumable();

		if ( null === $run ) {
			$this->out->line( __( 'No backfill run is active, and none can be resumed.', 'merchboard' ) );

			return;
		}

		/* translators: 1: run id, 2: number of orders processed. */
		$this->out->line( sprintf( __( 'Backfill run %1$s stopped after %2$d orders and can be resumed (`wp merchboard sales-backfill resume`).', 'merchboard' ), $run->run_id->value, $run->offset ) );
		$this->summary( $run->tally, false );
	}

	/**
	 * Validates the arguments; returns the page size.
	 *
	 * @phpstan-param list<string> $allowed
	 *
	 * @param array<mixed> $args       Positional arguments.
	 * @param array<mixed> $assoc_args Options.
	 * @param string[]     $allowed    Allowed options.
	 */
	private function options( array $args, array $assoc_args, array $allowed ): int {
		if ( array() !== $args ) {
			$this->out->error( __( 'This command takes no positional arguments.', 'merchboard' ) );
		}

		foreach ( array_keys( $assoc_args ) as $key ) {
			if ( ! in_array( (string) $key, $allowed, true ) ) {
				/* translators: %s: option name. */
				$this->out->error( sprintf( __( 'Unknown option: --%s', 'merchboard' ), (string) $key ) );
			}
		}

		$batch = $assoc_args['batch-size'] ?? (string) SalesBackfillRun::DEFAULT_BATCH_SIZE;

		if ( ! ( is_string( $batch ) || is_int( $batch ) ) || 1 !== preg_match( '/^[0-9]+$/', (string) $batch ) ) {
			$this->out->error( self::invalid_batch_size() );
		}

		try {
			return SalesBackfillRun::batch_size( (int) $batch );
		} catch ( InvalidArgumentException ) {
			$this->out->error( self::invalid_batch_size() );
		}
	}

	/**
	 * The message for an invalid page size.
	 */
	private static function invalid_batch_size(): string {
		/* translators: %d: largest allowed number of orders per page. */
		return sprintf( __( '--batch-size must be a whole number from 1 to %d.', 'merchboard' ), SalesBackfillRun::MAX_BATCH_SIZE );
	}

	/**
	 * Prints the counts (no amounts, no order ids).
	 *
	 * @param SalesBackfillTally $tally   Counts.
	 * @param bool               $dry_run Whether the orders would be registered.
	 */
	private function summary( SalesBackfillTally $tally, bool $dry_run ): void {
		$registered = $tally->count( SalesBackfillStatus::Registered );

		/* translators: %d: number of orders. */
		$this->out->line( sprintf( __( 'Orders scanned: %d', 'merchboard' ), $tally->scanned ) );
		$this->out->line(
			$dry_run
				/* translators: %d: number of orders. */
				? sprintf( __( 'Would register: %d', 'merchboard' ), $registered )
				/* translators: %d: number of orders. */
				: sprintf( __( 'Registered: %d', 'merchboard' ), $registered )
		);
		/* translators: %d: number of orders. */
		$this->out->line( sprintf( __( 'Skipped, already in the ledger: %d', 'merchboard' ), $tally->count( SalesBackfillStatus::HasHistory ) ) );
		/* translators: %d: number of orders. */
		$this->out->line( sprintf( __( 'Skipped, not counted: %d', 'merchboard' ), $tally->count( SalesBackfillStatus::NotCounted ) ) );
		/* translators: %d: number of orders. */
		$this->out->line( sprintf( __( 'Skipped, no creator: %d', 'merchboard' ), $tally->count( SalesBackfillStatus::NoCreator ) ) );
		/* translators: %d: number of orders. */
		$this->out->line( sprintf( __( 'Skipped, not found: %d', 'merchboard' ), $tally->count( SalesBackfillStatus::Missing ) ) );
		/* translators: %d: number of orders. */
		$this->out->line( sprintf( __( 'Skipped, busy: %d', 'merchboard' ), $tally->count( SalesBackfillStatus::Busy ) ) );

		$rows = array();

		foreach ( $tally->rows as $type => $count ) {
			$rows[] = $type . ': ' . $count;
		}

		$this->out->line(
			$dry_run
				/* translators: %s: ledger rows per type, e.g. "sale: 2, refund: 1". */
				? sprintf( __( 'Rows that would be written: %s', 'merchboard' ), implode( ', ', $rows ) )
				/* translators: %s: ledger rows per type, e.g. "sale: 2, refund: 1". */
				: sprintf( __( 'Rows written: %s', 'merchboard' ), implode( ', ', $rows ) )
		);

		$failures = array();

		foreach ( $tally->failures as $code => $count ) {
			$failures[] = $code . ': ' . $count;
		}

		/* translators: %d: number of orders. */
		$this->out->line( sprintf( __( 'Failed: %d', 'merchboard' ), $tally->count( SalesBackfillStatus::Failed ) ) . ( array() === $failures ? '' : ' (' . implode( ', ', $failures ) . ')' ) );
	}

	/**
	 * The message for a refused start or resume.
	 *
	 * @param string $outcome SalesBackfillScheduler outcome.
	 */
	private static function refusal( string $outcome ): string {
		return match ( $outcome ) {
			SalesBackfillScheduler::ALREADY_RUNNING   => __( 'A backfill run is already pending or running. Wait for it, or check `wp merchboard sales-backfill status`.', 'merchboard' ),
			SalesBackfillScheduler::NOTHING_TO_RESUME => __( 'There is no stopped backfill run to resume.', 'merchboard' ),
			SalesBackfillScheduler::QUEUE_UNAVAILABLE => __( 'Action Scheduler is not available, so nothing was queued.', 'merchboard' ),
			default                                   => __( 'The backfill job could not be queued.', 'merchboard' ),
		};
	}
}
