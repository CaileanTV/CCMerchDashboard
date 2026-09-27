<?php
/**
 * In-memory job queue for tests.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Support;

use MerchBoard\Modules\WooCommerce\JobQueue;
use Throwable;

/**
 * Keeps queued jobs in order, like Action Scheduler's pending actions:
 * has_pending() matches hook and arguments exactly. run_next() hands the
 * oldest job to a runner and removes it, also when the runner throws (Action
 * Scheduler marks such an action failed). Can be made unavailable or told to
 * refuse jobs.
 */
final class FakeJobQueue implements JobQueue {

	/**
	 * Pending jobs.
	 *
	 * @var list<array{hook: string, args: array<mixed>, delay: int}>
	 */
	public array $pending = array();

	/**
	 * Every job ever queued, in order.
	 *
	 * @var list<array{hook: string, args: array<mixed>, delay: int}>
	 */
	public array $queued = array();

	/**
	 * Jobs whose runner threw.
	 *
	 * @var list<array{hook: string, args: array<mixed>, delay: int}>
	 */
	public array $failed = array();

	/**
	 * Whether jobs can be queued.
	 *
	 * @var bool
	 */
	public bool $is_available = true;

	/**
	 * Whether enqueue() fails.
	 *
	 * @var bool
	 */
	public bool $refuse = false;

	public function available(): bool {
		return $this->is_available;
	}

	/**
	 * Whether an identical job is pending.
	 *
	 * @param string       $hook Job hook.
	 * @param array<mixed> $args Job arguments.
	 */
	public function has_pending( string $hook, array $args ): bool {
		foreach ( $this->pending as $job ) {
			if ( $job['hook'] === $hook && $job['args'] === $args ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Queues a job.
	 *
	 * @param string       $hook  Job hook.
	 * @param array<mixed> $args  Job arguments.
	 * @param int          $delay Seconds to wait.
	 */
	public function enqueue( string $hook, array $args, int $delay = 0 ): bool {
		if ( ! $this->is_available || $this->refuse ) {
			return false;
		}

		$job             = array(
			'hook'  => $hook,
			'args'  => $args,
			'delay' => $delay,
		);
		$this->pending[] = $job;
		$this->queued[]  = $job;

		return true;
	}

	/**
	 * Runs the oldest pending job with the runner; false when none is left.
	 *
	 * @phpstan-param callable(string, array<mixed>): void $runner
	 *
	 * @param callable $runner Receives hook and arguments.
	 */
	public function run_next( callable $runner ): bool {
		$job = array_shift( $this->pending );

		if ( null === $job ) {
			return false;
		}

		try {
			$runner( $job['hook'], $job['args'] );
		} catch ( Throwable ) {
			$this->failed[] = $job;
		}

		return true;
	}

	/**
	 * Runs pending jobs (also those they queue) until none is left.
	 *
	 * @phpstan-param callable(string, array<mixed>): void $runner
	 *
	 * @param callable $runner Receives hook and arguments.
	 * @param int      $limit  Safety limit.
	 * @return int Jobs run.
	 */
	public function drain( callable $runner, int $limit = 1000 ): int {
		$runs = 0;

		while ( $runs < $limit && $this->run_next( $runner ) ) {
			++$runs;
		}

		return $runs;
	}

	/**
	 * Hooks of the pending jobs.
	 *
	 * @return list<string>
	 */
	public function pending_hooks(): array {
		return array_column( $this->pending, 'hook' );
	}

	/**
	 * First argument (the payload) of each pending job.
	 *
	 * @return list<mixed>
	 */
	public function pending_payloads(): array {
		return array_map( static fn( array $job ): mixed => $job['args'][0] ?? null, $this->pending );
	}
}
