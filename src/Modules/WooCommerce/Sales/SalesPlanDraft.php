<?php
/**
 * Internal: the ledger as it grows during one planning run.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\WooCommerce\Sales;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * Stored ledger rows of one order plus the rows planned so far, aggregated per
 * order line. Created and discarded by SalesPlanner for every plan() call, so
 * the planner itself holds no state.
 *
 * - History: the attribution of a line (creator, product, variation, name) is
 *   taken from its earliest row and never changes (ADR-038, B3).
 * - Totals: quantity and amount (1/10000 units) over all cycles; closed cycles
 *   sum to 0, so the total is the balance of the latest cycle.
 * - Tax (ADR-048): the actual tax of each row, stored and planned, summed per
 *   line like the amount. A row without known tax (e.g. registered before
 *   schema version 7) makes the line's tax balance unknown (null) for good,
 *   so no later row of that line gets a guessed tax.
 *
 * @internal
 */
final class SalesPlanDraft {

	/**
	 * Event keys stored or planned.
	 *
	 * @var array<string, true>
	 */
	private array $keys = array();

	/**
	 * Attribution per order line with history.
	 *
	 * @var array<int, array{creator_id: int, product_id: int, variation_id: int, name: string}>
	 */
	private array $history = array();

	/**
	 * Quantity and amount per order line, stored + planned.
	 *
	 * @var array<int, array{quantity: int, net: int}>
	 */
	private array $totals = array();

	/**
	 * Stored `refund` rows by order line and refund id.
	 *
	 * @var array<int, array<int, SalesEntry>>
	 */
	private array $refund_rows = array();

	/**
	 * Highest `c-l` number per "item:cycle".
	 *
	 * @var array<string, int>
	 */
	private array $line_corrections = array();

	/**
	 * Tax balance per order line, stored + planned; null when unknown.
	 *
	 * @var array<int, int|null>
	 */
	private array $tax_totals = array();

	/**
	 * Actual tax of the planned rows by event key (known ones only).
	 *
	 * @var array<string, int>
	 */
	private array $taxes = array();

	/**
	 * Planned rows.
	 *
	 * @var list<SalesEntry>
	 */
	private array $entries = array();

	/**
	 * Planned cycle events.
	 *
	 * @var list<SalesCycleEvent>
	 */
	private array $events = array();

	/**
	 * Currency of the planned rows.
	 *
	 * @var string
	 */
	private string $currency;

	/**
	 * Constructor.
	 *
	 * @param SalesLedgerState $ledger         Stored state of the order.
	 * @param string           $order_currency The order's currency ('' for a deleted order).
	 * @throws SalesPlanAbort When the stored rows have another or mixed currencies, or rows exist without a cycle.
	 */
	public function __construct( private SalesLedgerState $ledger, string $order_currency ) {
		if ( array() !== $ledger->entries && 0 === $ledger->cycles->latest_cycle() ) {
			throw new SalesPlanAbort( SalesFailureCode::Unexpected );
		}

		$entries = $ledger->entries;
		usort( $entries, static fn( SalesEntry $a, SalesEntry $b ): int => (int) $a->id <=> (int) $b->id );
		$currencies = array();

		foreach ( $entries as $entry ) {
			$item                            = $entry->order_item_id;
			$this->keys[ $entry->event_key ] = true;
			$currencies[ $entry->currency ]  = true;
			$this->history[ $item ]        ??= self::attribution( $entry->creator_id, $entry->product_id, $entry->variation_id, $entry->item_name );
			$this->accumulate( $item, $entry->quantity, SalesAmount::parse( $entry->net_amount ) );
			$this->accumulate_tax( $item, $ledger->taxes[ $entry->event_key ] ?? null );

			if ( SalesEntryType::Refund === $entry->type ) {
				$this->refund_rows[ $item ][ $entry->refund_id ] = $entry;
			}

			if ( 1 === preg_match( '/^c-l:(\d+):(\d+):(\d+)$/', $entry->event_key, $parts ) ) {
				$slot                            = $parts[1] . ':' . $parts[2];
				$this->line_corrections[ $slot ] = max( $this->line_corrections[ $slot ] ?? 0, (int) $parts[3] );
			}
		}

		if ( count( $currencies ) > 1 || ( '' !== $order_currency && array() !== $currencies && ! isset( $currencies[ $order_currency ] ) ) ) {
			throw new SalesPlanAbort( SalesFailureCode::CurrencyMismatch );
		}

		$this->currency = '' !== $order_currency ? $order_currency : (string) array_key_first( $currencies );
	}

	/**
	 * Stored cycle state (before this plan).
	 */
	public function cycles(): SalesCycleState {
		return $this->ledger->cycles;
	}

	/**
	 * Whether an order line has ledger history (stored or planned).
	 *
	 * @param int $item_id Order item id.
	 */
	public function has_history( int $item_id ): bool {
		return isset( $this->history[ $item_id ] );
	}

	/**
	 * Order lines with history, ascending.
	 *
	 * @return list<int>
	 */
	public function history_items(): array {
		$items = array_keys( $this->history );
		sort( $items );

		return $items;
	}

	/**
	 * Quantity and amount of an order line over all cycles, stored + planned.
	 *
	 * @param int $item_id Order item id.
	 * @return array{quantity: int, net: int}
	 */
	public function total( int $item_id ): array {
		return $this->totals[ $item_id ] ?? array(
			'quantity' => 0,
			'net'      => 0,
		);
	}

	/**
	 * Tax balance of an order line over all cycles, stored + planned; 0 for a
	 * line without rows, null when unknown.
	 *
	 * @param int $item_id Order item id.
	 */
	public function tax_total( int $item_id ): ?int {
		return array_key_exists( $item_id, $this->tax_totals ) ? $this->tax_totals[ $item_id ] : 0;
	}

	/**
	 * Stored actual tax of a row, null when unknown.
	 *
	 * @param string $event_key Event key.
	 */
	public function stored_tax( string $event_key ): ?int {
		return $this->ledger->taxes[ $event_key ] ?? null;
	}

	/**
	 * Stored `refund` rows by order line (ascending) and refund id (ascending).
	 *
	 * @return array<int, array<int, SalesEntry>>
	 */
	public function refund_rows(): array {
		$rows = $this->refund_rows;
		ksort( $rows );

		foreach ( $rows as &$by_refund ) {
			ksort( $by_refund );
		}

		return $rows;
	}

	/**
	 * Whether an event key is stored or planned.
	 *
	 * @param string $event_key Event key.
	 */
	public function has_key( string $event_key ): bool {
		return isset( $this->keys[ $event_key ] );
	}

	/**
	 * Next `c-l` key of an order line in a cycle: highest existing number + 1.
	 *
	 * @param int $item_id Order item id.
	 * @param int $cycle   Cycle.
	 */
	public function next_line_correction_key( int $item_id, int $cycle ): string {
		$slot = $item_id . ':' . $cycle;

		return 'c-l:' . $slot . ':' . ( ( $this->line_corrections[ $slot ] ?? 0 ) + 1 );
	}

	/**
	 * Plans a row. The attribution comes from the line's history; a line
	 * without history needs $first_attribution (its first row), which then
	 * becomes its history.
	 *
	 * @param SalesEntryType                                                                $type              Row type.
	 * @param SalesCorrectionReason|null                                                    $reason            Correction reason.
	 * @param string                                                                        $event_key         Event key.
	 * @param int                                                                           $item_id           Order item id.
	 * @param int                                                                           $cycle             Cycle.
	 * @param int                                                                           $refund_id         Refund id or 0.
	 * @param int                                                                           $quantity          Signed quantity.
	 * @param int                                                                           $net               Signed amount in 1/10000 units.
	 * @param DateTimeImmutable                                                             $occurred_at       Event time.
	 * @param array{creator_id: int, product_id: int, variation_id: int, name: string}|null $first_attribution Attribution of a line without history.
	 * @param int|null                                                                      $tax               Actual tax of the row in 1/10000 units, null when unknown (ADR-048).
	 * @throws SalesPlanAbort When the line has no attribution.
	 */
	public function add(
		SalesEntryType $type,
		?SalesCorrectionReason $reason,
		string $event_key,
		int $item_id,
		int $cycle,
		int $refund_id,
		int $quantity,
		int $net,
		DateTimeImmutable $occurred_at,
		?array $first_attribution = null,
		?int $tax = null
	): void {
		if ( $this->has_key( $event_key ) ) {
			return;
		}

		$attribution = $this->history[ $item_id ] ?? $first_attribution;

		if ( null === $attribution ) {
			throw new SalesPlanAbort( SalesFailureCode::Unexpected, $item_id, $refund_id > 0 ? $refund_id : null );
		}

		$this->entries[]             = new SalesEntry(
			$event_key,
			$attribution['creator_id'],
			$this->ledger->order_id,
			$item_id,
			$cycle,
			$refund_id,
			$attribution['product_id'],
			$attribution['variation_id'],
			$attribution['name'],
			$type,
			$reason,
			$quantity,
			SalesAmount::format( $net ),
			$this->currency,
			$occurred_at
		);
		$this->keys[ $event_key ]    = true;
		$this->history[ $item_id ] ??= $attribution;
		$this->accumulate( $item_id, $quantity, $net );
		$this->accumulate_tax( $item_id, $tax );

		if ( null !== $tax ) {
			$this->taxes[ $event_key ] = $tax;
		}

		if ( 1 === preg_match( '/^c-l:(\d+):(\d+):(\d+)$/', $event_key, $parts ) ) {
			$this->line_corrections[ $parts[1] . ':' . $parts[2] ] = (int) $parts[3];
		}
	}

	/**
	 * Plans a cycle event.
	 *
	 * @param int                 $cycle       Cycle.
	 * @param SalesCycleEventType $event       Open or close.
	 * @param DateTimeImmutable   $occurred_at Event time.
	 */
	public function cycle_event( int $cycle, SalesCycleEventType $event, DateTimeImmutable $occurred_at ): void {
		if ( ! $this->ledger->cycles->has( $cycle, $event ) ) {
			$this->events[] = new SalesCycleEvent( $this->ledger->order_id, $cycle, $event, $occurred_at );
		}
	}

	/**
	 * Everything planned, in planning order.
	 */
	public function write(): SalesLedgerWrite {
		return new SalesLedgerWrite( $this->entries, $this->events, $this->taxes );
	}

	/**
	 * Attribution of a line.
	 *
	 * @param int    $creator_id   Creator.
	 * @param int    $product_id   Parent product.
	 * @param int    $variation_id Variation or 0.
	 * @param string $name         Line name.
	 * @return array{creator_id: int, product_id: int, variation_id: int, name: string}
	 */
	public static function attribution( int $creator_id, int $product_id, int $variation_id, string $name ): array {
		return array(
			'creator_id'   => $creator_id,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'name'         => $name,
		);
	}

	/**
	 * Adds a row's tax to a line's tax balance; an unknown tax makes the
	 * balance unknown.
	 *
	 * @param int      $item_id Order item id.
	 * @param int|null $tax     Signed tax, or null when unknown.
	 */
	private function accumulate_tax( int $item_id, ?int $tax ): void {
		$total                        = $this->tax_total( $item_id );
		$this->tax_totals[ $item_id ] = null === $total || null === $tax ? null : SalesAmount::add( $total, $tax );
	}

	/**
	 * Adds to a line's totals.
	 *
	 * @param int $item_id  Order item id.
	 * @param int $quantity Signed quantity.
	 * @param int $net      Signed amount.
	 */
	private function accumulate( int $item_id, int $quantity, int $net ): void {
		$total                    = $this->total( $item_id );
		$this->totals[ $item_id ] = array(
			'quantity' => SalesAmount::add( $total['quantity'], $quantity ),
			'net'      => SalesAmount::add( $total['net'], $net ),
		);
	}
}
