<?php
/**
 * The backfill's result for one order.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * Status, planned rows by type and, for a failure, its code. No amounts and
 * no personal data (ADR-038, status note "ROADMAP trin 7", D3/D5).
 */
final readonly class SalesBackfillOutcome {

	/**
	 * Constructor.
	 *
	 * @param SalesBackfillStatus   $status  What happened.
	 * @param array<string, int>    $rows    Planned rows by entry type (`sale`, `refund`, `reversal`, `correction`); only for Registered.
	 * @param SalesFailureCode|null $failure Failure code; only for Failed.
	 */
	public function __construct(
		public SalesBackfillStatus $status,
		public array $rows = array(),
		public ?SalesFailureCode $failure = null
	) {
	}

	/**
	 * An outcome without rows.
	 *
	 * @param SalesBackfillStatus $status What happened.
	 */
	public static function of( SalesBackfillStatus $status ): self {
		return new self( $status );
	}

	/**
	 * A failure.
	 *
	 * @param SalesFailureCode $code Failure code.
	 */
	public static function failed( SalesFailureCode $code ): self {
		return new self( SalesBackfillStatus::Failed, array(), $code );
	}

	/**
	 * A registration with the rows the plan contains, by type.
	 *
	 * @param SalesLedgerWrite $write Planned rows.
	 */
	public static function registered( SalesLedgerWrite $write ): self {
		$rows = array();

		foreach ( SalesEntryType::cases() as $type ) {
			$rows[ $type->value ] = 0;
		}

		foreach ( $write->entries as $entry ) {
			++$rows[ $entry->type->value ];
		}

		return new self( SalesBackfillStatus::Registered, $rows );
	}
}
