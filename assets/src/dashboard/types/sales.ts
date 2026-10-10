/**
 * Sales statistics from `GET /merchboard/v1/me/sales` (docs/API.md, ADR-040).
 * The Overview reads the summary (period and totals per currency); the Sales
 * section also reads the dense series. Amounts stay decimal strings (four
 * decimals), never numbers.
 */

import type { Profit } from './profit';

export type SalesInterval = 'day' | 'week' | 'month';

export interface SalesTotals {
	/** `sales + adjustments`, e.g. "1234.0000" (can be negative). */
	readonly netSales: string;
	/** Sum of the sale rows. */
	readonly sales: string;
	/** Sum of refunds, reversals and corrections (usually negative). */
	readonly adjustments: string;
	/** Signed sum of quantities (can be negative). */
	readonly units: number;
	/** Distinct orders with a sale in the period. */
	readonly orders: number;
	/**
	 * The same sums including the rows' stored VAT (ADR-053); null when a row
	 * of the period has no stored VAT (never treated as 0).
	 */
	readonly netSalesInclVat: string | null;
	readonly salesInclVat: string | null;
	readonly adjustmentsInclVat: string | null;
}

export interface SalesCurrencyTotals {
	/** ISO 4217 code, e.g. "DKK". */
	readonly currency: string;
	readonly totals: SalesTotals;
	/**
	 * Whether every row of the period has its stored VAT, so the amounts
	 * including VAT are known (ADR-053). Otherwise the figures fall back to the
	 * amounts excluding VAT, labelled as such.
	 */
	readonly vatIncluded: boolean;
	/** Rows of the period without stored VAT. */
	readonly missingVatRows: number;
	/**
	 * The creator's realized profit of the same rows (ADR-050), separate from
	 * the sales figures; null when the server sent none.
	 */
	readonly profit: Profit | null;
}

export interface SalesSummary {
	/** First day, `YYYY-MM-DD` in the site's time zone (inclusive). */
	readonly from: string;
	/** Last day, `YYYY-MM-DD` in the site's time zone (inclusive). */
	readonly to: string;
	readonly interval: SalesInterval;
	/** The site's time zone, e.g. "Europe/Copenhagen". */
	readonly timezone: string;
	/** One entry per currency, sorted by currency; empty without sales. */
	readonly currencies: readonly SalesCurrencyTotals[];
}

/** One period of the dense series (every period of the query, zeros included). */
export interface SalesPoint {
	/** First day of the period, `YYYY-MM-DD` (inclusive). */
	readonly start: string;
	/** Last day of the period, `YYYY-MM-DD` (inclusive). */
	readonly end: string;
	readonly netSales: string;
	/** Incl. VAT; null when the currency's VAT is incomplete. */
	readonly netSalesInclVat: string | null;
	readonly units: number;
	readonly orders: number;
}

export interface SalesCurrencyReport extends SalesCurrencyTotals {
	readonly series: readonly SalesPoint[];
}

export interface SalesReport extends SalesSummary {
	readonly currencies: readonly SalesCurrencyReport[];
}

/** Parameters of `GET /me/sales`; omitted ones take the server's defaults. */
export interface SalesQuery {
	readonly from?: string;
	readonly to?: string;
	readonly interval?: SalesInterval;
}
