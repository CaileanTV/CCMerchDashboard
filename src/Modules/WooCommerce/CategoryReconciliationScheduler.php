<?php
/**
 * Queues category reconciliation jobs.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

use MerchBoard\Core\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that queues reconciliation work (ADR-033 point 6). Event
 * handlers call it; it never reconciles or writes a link itself.
 *
 * Two jobs:
 * - `merchboard_reconcile_product`: one product (category or status change).
 *   Deduplicated: an identical pending job (same product, trigger and user)
 *   is not queued again. A different trigger may queue a second job; the
 *   reconciliation is state-based, so it is a no-op.
 * - `merchboard_reconcile_run`: one page of a bounded run (a category, or a
 *   mapping's category links). The first job carries no run yet and is
 *   deduplicated the same way; follow-up pages and retries carry the run
 *   (CategorySyncPage) and are always queued.
 *
 * Without an available queue nothing is queued: logged as a warning with the
 * ids, no synchronous or cron fallback. The full reconciliation
 * (CategoryReconciliationBatch) is the safety net.
 */
final class CategoryReconciliationScheduler {

	public const PRODUCT_HOOK = 'merchboard_reconcile_product';

	public const RUN_HOOK = 'merchboard_reconcile_run';

	/**
	 * Constructor.
	 *
	 * @param JobQueue $queue  Background queue.
	 * @param Logger   $logger Technical log.
	 */
	public function __construct( private JobQueue $queue, private Logger $logger ) {
	}

	/**
	 * Queues the reconciliation of one product.
	 *
	 * @param int                           $product_id      Product id.
	 * @param CategoryReconciliationTrigger $trigger         Why.
	 * @param int|null                      $trigger_user_id User behind the change, if known.
	 * @return bool Whether a job is queued (also when an identical one already was).
	 */
	public function product( int $product_id, CategoryReconciliationTrigger $trigger, ?int $trigger_user_id ): bool {
		if ( $product_id < 1 ) {
			return false;
		}

		return $this->queue_unique(
			self::PRODUCT_HOOK,
			array(
				'product_id'      => $product_id,
				'trigger'         => $trigger->value,
				'trigger_user_id' => self::user( $trigger_user_id ),
				'attempt'         => 0,
			)
		);
	}

	/**
	 * Queues a new run over a scope (its first page).
	 *
	 * @param ReconciliationScope           $scope           Which products.
	 * @param CategoryReconciliationTrigger $trigger         Why.
	 * @param int|null                      $trigger_user_id User behind it, if known.
	 * @return bool Whether a job is queued (also when an identical one already was).
	 */
	public function run( ReconciliationScope $scope, CategoryReconciliationTrigger $trigger, ?int $trigger_user_id ): bool {
		return $this->queue_unique(
			self::RUN_HOOK,
			array(
				'run'             => null,
				'scope'           => $scope->to_array(),
				'trigger'         => $trigger->value,
				'trigger_user_id' => self::user( $trigger_user_id ),
				'attempt'         => 0,
			)
		);
	}

	/**
	 * Queues the next page of a run, or a repeat of the same page after a
	 * failure (same run id, cursor and counts).
	 *
	 * @param CategorySyncPage $state   Run state before the page.
	 * @param int              $attempt Attempt number of that page (0: first).
	 * @param int              $delay   Seconds to wait.
	 * @return bool Whether the job was queued.
	 */
	public function continue_run( CategorySyncPage $state, int $attempt = 0, int $delay = 0 ): bool {
		return $this->queue(
			self::RUN_HOOK,
			array(
				'run'     => $state->to_array(),
				'attempt' => $attempt,
			),
			$delay
		);
	}

	/**
	 * Queues a repeat of a product job after a failure.
	 *
	 * @param int                           $product_id      Product id.
	 * @param CategoryReconciliationTrigger $trigger         Why.
	 * @param int|null                      $trigger_user_id User behind it.
	 * @param int                           $attempt         Attempt number of the repeat.
	 * @param int                           $delay           Seconds to wait.
	 * @return bool Whether the job was queued.
	 */
	public function retry_product( int $product_id, CategoryReconciliationTrigger $trigger, ?int $trigger_user_id, int $attempt, int $delay ): bool {
		return $this->queue(
			self::PRODUCT_HOOK,
			array(
				'product_id'      => $product_id,
				'trigger'         => $trigger->value,
				'trigger_user_id' => self::user( $trigger_user_id ),
				'attempt'         => $attempt,
			),
			$delay
		);
	}

	/**
	 * Queues a job unless an identical one is pending or running.
	 *
	 * @param string               $hook    Job hook.
	 * @param array<string, mixed> $payload Job payload.
	 */
	private function queue_unique( string $hook, array $payload ): bool {
		if ( $this->queue->available() && $this->queue->has_pending( $hook, array( $payload ) ) ) {
			return true;
		}

		return $this->queue( $hook, $payload, 0 );
	}

	/**
	 * Queues a job; logs when that is not possible.
	 *
	 * @param string               $hook    Job hook.
	 * @param array<string, mixed> $payload Job payload.
	 * @param int                  $delay   Seconds to wait.
	 */
	private function queue( string $hook, array $payload, int $delay ): bool {
		if ( ! $this->queue->available() ) {
			$this->logger->warning( 'Category reconciliation not queued: Action Scheduler is not available.', self::log_context( $hook, $payload ) );

			return false;
		}

		if ( ! $this->queue->enqueue( $hook, array( $payload ), $delay ) ) {
			$this->logger->error( 'Category reconciliation could not be queued.', self::log_context( $hook, $payload ) );

			return false;
		}

		return true;
	}

	/**
	 * Ids for the log (no meta values, no personal data).
	 *
	 * @param string               $hook    Job hook.
	 * @param array<string, mixed> $payload Job payload.
	 * @return array<string, mixed>
	 */
	private static function log_context( string $hook, array $payload ): array {
		$run = is_array( $payload['run'] ?? null ) ? $payload['run'] : $payload;

		return array_filter(
			array(
				'job'        => $hook,
				'product_id' => $payload['product_id'] ?? null,
				'scope'      => $run['scope'] ?? null,
				'run_id'     => $run['run_id'] ?? null,
				'trigger'    => $run['trigger'] ?? null,
			),
			static fn( mixed $value ): bool => null !== $value
		);
	}

	/**
	 * A user id, or null for none.
	 *
	 * @param int|null $user_id User id.
	 */
	private static function user( ?int $user_id ): ?int {
		return null !== $user_id && $user_id > 0 ? $user_id : null;
	}
}
