/**
 * Order lines from `GET /merchboard/v1/me/orders` (docs/API.md, ADR-039 P2,
 * P3, P4, P6). One item per order line, not per order. The types mirror the
 * backend contract: no customer data, no sales amounts, no internal ids
 * shown. The only amounts are the creator's own profit (ADR-050).
 */
import type { Profit } from './profit';

export interface OrderLine {
	/** Order item id; the row key only, never shown. */
	readonly lineId: number;
	/** WooCommerce order number, or the order id for a deleted order. */
	readonly orderNumber: string;
	/** ISO 8601 UTC date of the line's first sale. */
	readonly date: string;
	/** Machine status (WooCommerce status, `trashed` or `deleted`). */
	readonly status: string;
	/** Human-readable status from the server. */
	readonly statusLabel: string;
	/** Current product name or the ledger snapshot ('' when unknown). */
	readonly productName: string;
	/** The line's ledger balance (not the purchased quantity). */
	readonly quantity: number;
	/** A refund still applies to the line (not necessarily a full refund). */
	readonly refunded: boolean;
	/** The order has an open counted cycle. */
	readonly counted: boolean;
	/** The line's realized profit over all its ledger rows (refunds included). */
	readonly profit: Profit | null;
	/** Sum of the creator's lines in the same order (also on other pages). */
	readonly orderProfit: Profit | null;
	/** Number of the creator's lines in the order. */
	readonly orderLines: number;
}
