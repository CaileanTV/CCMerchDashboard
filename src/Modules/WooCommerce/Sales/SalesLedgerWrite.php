<?php
/**
 * Rows to write for one order.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * The new ledger rows and cycle events of one synchronization, written by
 * SalesRepository::write() in one transaction. Empty means "nothing to do".
 * `campaigns` holds the company's campaigns that cover designer lines of the
 * order (ADR-080 pkt. 9); it is read only for a line's first designer row.
 * `vat_exempt` holds the VAT rate of lines sold without VAT (review 30.15);
 * a designer product's sale compares without VAT then.
 */
final readonly class SalesLedgerWrite {

	/**
	 * Constructor.
	 *
	 * @phpstan-param list<SalesEntry> $entries
	 * @phpstan-param list<SalesCycleEvent> $cycle_events
	 *
	 * @param SalesEntry[]       $entries      New ledger rows.
	 * @param SalesCycleEvent[]  $cycle_events New cycle events.
	 * @param array<string, int> $taxes        Actual tax of new rows by event key (ADR-048); rows with unknown tax are left out.
	 * @param array<int, int>    $campaigns    Campaign id by order item of the covered designer lines.
	 * @param array<int, int>    $vat_exempt   VAT rate (basis points) by order item of lines sold without VAT.
	 */
	public function __construct( public array $entries = array(), public array $cycle_events = array(), public array $taxes = array(), public array $campaigns = array(), public array $vat_exempt = array() ) {
	}

	/**
	 * The same rows with the campaigns of the order's designer lines.
	 *
	 * @param array<int, int> $campaigns Campaign id by order item.
	 */
	public function with_campaigns( array $campaigns ): self {
		return new self( $this->entries, $this->cycle_events, $this->taxes, $campaigns, $this->vat_exempt );
	}

	/**
	 * The same rows with the VAT rate of the order's lines sold without VAT.
	 *
	 * @param array<int, int> $vat_exempt VAT rate (basis points) by order item.
	 */
	public function with_vat_exempt( array $vat_exempt ): self {
		return new self( $this->entries, $this->cycle_events, $this->taxes, $this->campaigns, $vat_exempt );
	}

	/**
	 * Whether there is nothing to write.
	 */
	public function is_empty(): bool {
		return array() === $this->entries && array() === $this->cycle_events;
	}
}
