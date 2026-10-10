/**
 * The creator's realized profit of a group of ledger rows (ADR-049, ADR-050),
 * as `/me/products`, `/me/orders` and `/me/sales` send it. Profit is not
 * payout and not a sales figure.
 *
 * - `complete`: every row is known; `amount` is the profit (can be 0 or negative);
 * - `incomplete`: some rows lack historical data; no amount;
 * - `unknown`: no row has the data; no amount.
 *
 * An unknown or incomplete profit is never shown as an amount (never 0).
 */
export type ProfitStatus = 'complete' | 'incomplete' | 'unknown';

export interface Profit {
	/** ISO 4217 code, e.g. "DKK". */
	readonly currency: string;
	readonly status: ProfitStatus;
	/** Decimal string with four decimals; only for `complete`. */
	readonly amount: string | null;
}
