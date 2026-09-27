<?php
/**
 * The result of a full category reconciliation run.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Counts per outcome for the whole run. For a dry run the counts are what a
 * real run would do now, `changes` lists the expected link changes and
 * `attention` the products an administrator should look at (ambiguous, could
 * not be evaluated, no new link because the creator is inactive, or a kept
 * manual link that deviates from its categories: `conflict`), each at
 * most CategoryReconciliationBatch::MAX_CHANGES (`*_truncated` when there
 * were more). A real run lists neither: its changes are in the audit log.
 * Nothing here is stored.
 */
final readonly class CategorySyncReport {

	/**
	 * Outcomes that need an administrator's attention.
	 */
	public const ATTENTION = array( ReconciliationOutcome::Ambiguous, ReconciliationOutcome::Error, ReconciliationOutcome::SkippedInactive );

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<array{product_id: int, outcome: string, from_creator_id: int|null, to_creator_id: int|null, reason: string}> $changes
	 * @phpstan-param list<array{product_id: int, outcome: string, conflict?: string, creator_id?: int|null, mapped_creator_ids?: list<int>}> $attention
	 *
	 * @param ReconciliationRunId           $run_id              Run id.
	 * @param bool                          $dry_run             Whether nothing was written.
	 * @param CategoryReconciliationTrigger $trigger             Why the run happened.
	 * @param ReconciliationScope           $scope               Which products.
	 * @param CategoryReconciliationResult  $result              Counts per outcome.
	 * @param int                           $pages               Candidate pages processed.
	 * @param array[]                       $changes             Expected changes (dry run).
	 * @param bool                          $changes_truncated   Whether $changes is cut off.
	 * @param array[]                       $attention           Products that need attention (dry run).
	 * @param bool                          $attention_truncated Whether $attention is cut off.
	 */
	public function __construct(
		public ReconciliationRunId $run_id,
		public bool $dry_run,
		public CategoryReconciliationTrigger $trigger,
		public ReconciliationScope $scope,
		public CategoryReconciliationResult $result,
		public int $pages,
		public array $changes = array(),
		public bool $changes_truncated = false,
		public array $attention = array(),
		public bool $attention_truncated = false
	) {
	}

	/**
	 * A fingerprint of what the dry run found (counts, expected changes and
	 * products that need attention; not the run id), so a confirmation can
	 * tell whether the state changed since the admin saw it.
	 */
	public function fingerprint(): string {
		return hash( 'sha256', (string) wp_json_encode( array( $this->result->to_array(), $this->changes, $this->attention ) ) );
	}

	/**
	 * The audit context of `category_sync_completed` (ADR-033): run id,
	 * dry-run status, trigger, scope and every count.
	 *
	 * @param int|null $trigger_user_id User who started the run, if known.
	 * @return array<string, mixed>
	 */
	public function audit_context( ?int $trigger_user_id ): array {
		$context = array(
			'run_id'  => $this->run_id->value,
			'dry_run' => $this->dry_run,
			'trigger' => $this->trigger->value,
		);

		if ( null !== $trigger_user_id && $trigger_user_id > 0 ) {
			$context['trigger_user_id'] = $trigger_user_id;
		}

		return $context + $this->scope->to_array() + $this->result->to_array() + array( 'total' => $this->result->total() );
	}
}
