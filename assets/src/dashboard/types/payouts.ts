/**
 * Payouts from `GET/POST /merchboard/v1/me/payouts` (docs/API.md, ADR-054,
 * ADR-055, ADR-056, ADR-057). Payment details only ever arrive as the masked
 * `destinationHint`; amounts are decimal strings, never floats.
 */

export const PAYOUT_STATUSES = [
	'pending',
	'completed',
	'rejected',
	'cancelled',
] as const;

export type PayoutStatus = ( typeof PAYOUT_STATUSES )[ number ];

/** Methods the dashboard can request. */
export const PAYOUT_METHODS = [ 'bank', 'paypal', 'gift_card' ] as const;

export type PayoutMethod = ( typeof PAYOUT_METHODS )[ number ];

export interface Payout {
	readonly id: number;
	readonly status: PayoutStatus;
	readonly currency: string;
	/** Decimal string with four decimals. */
	readonly amount: string;
	/** The method as the server sends it (also one this build does not offer). */
	readonly method: string;
	/** Masked destination, e.g. "•••• 1234" or "j•••@g•••.com". */
	readonly destinationHint: string;
	/** ISO 8601 UTC. */
	readonly requestedAt: string;
	/** ISO 8601 UTC, null while pending. */
	readonly decidedAt: string | null;
	/** The admin's reason, only for a rejected payout. */
	readonly rejectionReason: string | null;
	/** Whether a gift card is registered on it and can be shown (ADR-057). */
	readonly giftCard: boolean;
	/** Number of its settlement document (ADR-060), null when it has none. */
	readonly settlementDocument: string | null;
}

/**
 * Balance of one currency. With `status: 'unknown'` the amounts that depend
 * on the ledger are null: unknown, never 0.
 */
export interface PayoutCurrencyBalance {
	readonly currency: string;
	readonly status: 'complete' | 'unknown';
	readonly earned: string | null;
	readonly completed: string;
	readonly reserved: string;
	readonly available: string | null;
	readonly outstanding: string | null;
	readonly unknownRows: number;
}

export interface PayoutBalance {
	readonly currencies: readonly PayoutCurrencyBalance[];
	readonly holdingDays: number;
	/** ISO 8601 UTC, null for the whole history. */
	readonly payoutStart: string | null;
	/** Methods the server knows. */
	readonly methods: readonly string[];
	/** Whether bank and PayPal can be requested now (a valid key is configured). */
	readonly methodsUsable: boolean;
	/** Whether a gift card can be requested now (a gift card plugin is chosen and available). */
	readonly giftCardUsable: boolean;
}

/** Details of a new request, per method. */
export type PayoutDetails =
	| {
			readonly method: 'bank';
			readonly registration: string;
			readonly account: string;
	  }
	| { readonly method: 'paypal'; readonly email: string }
	| { readonly method: 'gift_card' };

/**
 * The gift card of a completed gift card payout, read live from the gift card
 * plugin by `GET /me/payouts/{id}/gift-card` (ADR-057). The code is kept only
 * in component state, never in browser storage.
 */
export interface PayoutGiftCard {
	readonly code: string;
	/** Decimal string with four decimals: the original amount. */
	readonly amount: string;
	/** Decimal string with four decimals: the balance now. */
	readonly balance: string;
	readonly currency: string;
	readonly status: 'active' | 'disabled' | 'expired' | 'other';
	/** ISO 8601 UTC, null when it does not expire. */
	readonly expiresAt: string | null;
}

/** One settled ledger row of a settlement document. */
export interface SettlementDocumentRow {
	readonly orderId: number;
	readonly itemName: string;
	readonly type: string;
	readonly quantity: number;
	/** Decimal strings with four decimals. */
	readonly net: string;
	readonly tax: string;
	readonly payout: string;
}

/**
 * A settlement document (afregningsbilag) as issued, from
 * `GET /me/payouts/{id}/settlement-document` (ADR-060). Preliminary: not
 * approved by an auditor or SKAT. Never recalculated by the dashboard.
 */
export interface SettlementDocument {
	readonly number: string;
	readonly type: 'settlement' | 'correction';
	readonly preliminary: true;
	/** ISO 8601 UTC. */
	readonly issuedAt: string;
	/** ISO 8601 UTC, null for the whole history. */
	readonly periodFrom: string | null;
	readonly periodTo: string;
	readonly currency: string;
	readonly commission: string;
	readonly openingBalance: string;
	readonly payout: string;
	readonly closingBalance: string;
	/** The creator's VAT status at issue, null when unknown. */
	readonly vatRegistered: boolean | null;
	readonly creatorName: string;
	readonly companyName: string;
	readonly companyAddress: readonly string[];
	readonly method: string;
	readonly destination: string;
	readonly rows: readonly SettlementDocumentRow[];
	readonly corrections: readonly {
		readonly number: string;
		readonly issuedAt: string;
		readonly reason: string;
	}[];
}

export interface PayoutRequestInput {
	readonly currency: string;
	/** Decimal string, or "all". */
	readonly amount: string;
	readonly details: PayoutDetails;
	/** The client's key; the same key always returns the same payout. */
	readonly idempotencyKey: string;
}
