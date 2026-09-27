<?php
/**
 * What the category reconciliation decided for one product.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The decision for one product before anything is written (ADR-033). A real
 * run applies it through ProductCreatorLink::apply_category_link(); a dry run
 * only reports it. Both come from the same evaluation.
 */
final readonly class ReconciliationPlan {

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<int> $mapped
	 *
	 * @param int                     $product_id Product id.
	 * @param ReconciliationOutcome   $outcome    Outcome (for a dry run: the expected one).
	 * @param bool                    $writes     Whether the link must change.
	 * @param int|null                $from       Current category link's creator, if any (a manual conflict: the manual creator).
	 * @param int|null                $to         Creator to link, or null to remove (when $writes).
	 * @param string                  $reason     Audit reason (when $writes).
	 * @param array<string, mixed>    $context    Extra audit context (when $writes).
	 * @param ManualLinkConflict|null $conflict How a kept manual link deviates from its categories (diagnostic only).
	 * @param int[]                   $mapped     The distinct creators mapped to the product's direct categories (with $conflict).
	 */
	private function __construct(
		public int $product_id,
		public ReconciliationOutcome $outcome,
		public bool $writes,
		public ?int $from = null,
		public ?int $to = null,
		public string $reason = '',
		public array $context = array(),
		public ?ManualLinkConflict $conflict = null,
		public array $mapped = array()
	) {
	}

	/**
	 * Nothing to write.
	 *
	 * @param int                   $product_id Product id.
	 * @param ReconciliationOutcome $outcome    Outcome.
	 */
	public static function keep( int $product_id, ReconciliationOutcome $outcome ): self {
		return new self( $product_id, $outcome, false );
	}

	/**
	 * A manual link, kept as it is (skipped_manual), that deviates from the
	 * product's creator categories. Nothing is written; the conflict is only
	 * reported (ADR-033 point 3).
	 *
	 * @phpstan-param list<int> $mapped
	 *
	 * @param int                $product_id Product id.
	 * @param int|null           $creator_id The manually linked creator (null for an invalid link).
	 * @param ManualLinkConflict $conflict   How it deviates.
	 * @param int[]              $mapped     The distinct mapped creators.
	 */
	public static function manual_conflict( int $product_id, ?int $creator_id, ManualLinkConflict $conflict, array $mapped ): self {
		return new self( $product_id, ReconciliationOutcome::SkippedManual, false, $creator_id, null, '', array(), $conflict, $mapped );
	}

	/**
	 * The category link must change.
	 *
	 * @param int                   $product_id Product id.
	 * @param ReconciliationOutcome $outcome    Outcome.
	 * @param int|null              $from       Current category link's creator.
	 * @param int|null              $to         New creator, or null to remove.
	 * @param string                $reason     Audit reason.
	 * @param array<string, mixed>  $context    Extra audit context.
	 */
	public static function change( int $product_id, ReconciliationOutcome $outcome, ?int $from, ?int $to, string $reason, array $context ): self {
		return new self( $product_id, $outcome, true, $from, $to, $reason, $context );
	}

	/**
	 * The product could not be evaluated.
	 *
	 * @param int $product_id Product id.
	 */
	public static function failed( int $product_id ): self {
		return new self( $product_id, ReconciliationOutcome::Error, false );
	}

	/**
	 * The planned change for a report (no audit context).
	 *
	 * @return array{product_id: int, outcome: string, from_creator_id: int|null, to_creator_id: int|null, reason: string}
	 */
	public function to_array(): array {
		return array(
			'product_id'      => $this->product_id,
			'outcome'         => $this->outcome->value,
			'from_creator_id' => $this->from,
			'to_creator_id'   => $this->to,
			'reason'          => $this->reason,
		);
	}
}
