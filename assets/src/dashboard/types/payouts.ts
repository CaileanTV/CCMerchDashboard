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
 * VAT on the commission of a settlement document as issued: the commission
 * of sales while the creator was VAT registered includes VAT (ADR-062 K05).
 * Decimal strings with four decimals; incl = excl + vat.
 */
export interface SettlementCommissionVat {
	/** VAT rate in basis points, e.g. 2500 for 25 %. */
	readonly rateBps: number;
	readonly excl: string;
	readonly vat: string;
	readonly incl: string;
	/** Commission of sales while the creator was not VAT registered: no VAT. */
	readonly untaxed: string;
}

/**
 * VAT deducted from the payout of sales while the creator was not VAT
 * registered, as issued (template version 6, ADR-064): the commission of those
 * sales incl. VAT, the VAT deducted and what was paid out. Decimal strings
 * with four decimals; net = gross − deduction.
 */
export interface SettlementVatDeduction {
	readonly gross: string;
	readonly deduction: string;
	readonly net: string;
}

/**
 * Master data of a settlement document as issued (ADR-062 K09). An empty
 * string or list means it was not registered when the document was issued.
 */
export interface SettlementMasterData {
	readonly creatorLegalName: string;
	readonly creatorAddress: readonly string[];
	readonly creatorCvr: string;
	readonly companyCvr: string;
}

/** Amounts of a correction document; decimal strings with four decimals. */
export interface SettlementCorrectionValues {
	readonly commission: string;
	readonly excl: string;
	readonly vat: string;
	readonly incl: string;
	readonly untaxed: string;
}

/**
 * The amounts of a correction document (rettelsesbilag, template version 5,
 * ADR-062 K10 and K15): before (in force), corrected and the difference
 * (corrected − before). It changes neither the original nor the payout, and
 * the difference is not settled automatically.
 */
export interface SettlementCorrectionAmounts {
	/** Suffix of its number and PDF, e.g. "K1". */
	readonly suffix: string;
	/** Number of the original settlement document. */
	readonly original: string;
	/** Number of the document it corrects (the original or a correction). */
	readonly corrects: string;
	/** VAT rate in basis points, e.g. 2500 for 25 %. */
	readonly rateBps: number;
	readonly before: SettlementCorrectionValues;
	readonly after: SettlementCorrectionValues;
	readonly difference: SettlementCorrectionValues;
	/** Paid out with the original, unchanged. */
	readonly payout: string;
}

/**
 * The master data of both parties on a correction document, from its own
 * snapshot as issued (ADR-062 K09 and K15). An empty string or list means
 * not registered. `changed` is whether they differ from the document it
 * corrects (the master data were corrected).
 */
export interface SettlementCorrectionMasterData {
	readonly creatorLegalName: string;
	readonly creatorAddress: readonly string[];
	readonly creatorCvr: string;
	readonly companyName: string;
	readonly companyAddress: readonly string[];
	readonly companyCvr: string;
	readonly changed: boolean;
}

/**
 * A settlement document (afregningsbilag) as issued, from
 * `GET /me/payouts/{id}/settlement-document` (ADR-060). Not approved by an
 * auditor or the Danish Tax Agency. Never recalculated by the dashboard.
 */
export interface SettlementDocument {
	readonly number: string;
	readonly type: 'settlement' | 'correction';
	/**
	 * True for documents of template version 1, marked preliminary (060-9);
	 * later documents carry the note on tax and reporting instead.
	 */
	readonly preliminary: boolean;
	/** ISO 8601 UTC. */
	readonly issuedAt: string;
	/** ISO 8601 UTC, null for the whole history. */
	readonly periodFrom: string | null;
	readonly periodTo: string;
	readonly currency: string;
	readonly commission: string;
	/**
	 * VAT on the commission (template version 3, ADR-062 K05 and K12), null
	 * when none was calculated: earlier documents, and commission of sales
	 * while the creator was not VAT registered. Separate from the VAT on the
	 * sale in the rows.
	 */
	readonly commissionVat: SettlementCommissionVat | null;
	/**
	 * VAT deducted from the payout (template version 6, ADR-064), null when
	 * none was deducted: earlier documents, and documents with only sales while
	 * the creator was VAT registered.
	 */
	readonly vatDeduction: SettlementVatDeduction | null;
	/**
	 * Payout adjustments the document settled (ADR-063), "0.0000" when none.
	 */
	readonly adjustment: string;
	/** References of the recalculations of those adjustments, e.g. R2-000001. */
	readonly adjustmentReferences: readonly string[];
	readonly openingBalance: string;
	readonly payout: string;
	readonly closingBalance: string;
	/** The creator's VAT status at issue, null when unknown. */
	readonly vatRegistered: boolean | null;
	readonly creatorName: string;
	readonly companyName: string;
	readonly companyAddress: readonly string[];
	/**
	 * Master data of both parties as issued (template version 4, ADR-062
	 * K09), null for earlier documents. Empty values were not registered.
	 */
	readonly masterData: SettlementMasterData | null;
	readonly method: string;
	readonly destination: string;
	readonly rows: readonly SettlementDocumentRow[];
	readonly corrections: readonly {
		readonly number: string;
		readonly issuedAt: string;
		readonly reason: string;
		/** Null for correction documents without amounts (issued before K10). */
		readonly amounts: SettlementCorrectionAmounts | null;
		/** Its master data as issued; null when it has no amounts. */
		readonly masterData: SettlementCorrectionMasterData | null;
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
