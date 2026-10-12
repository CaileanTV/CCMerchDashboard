/**
 * `/me/payouts`: the creator's payouts, balance, requests and cancellations
 * (docs/API.md). The creator is the one the server resolves for the logged-in
 * user; nothing here takes or sends a creator id. Every rule (balance,
 * reservation, limits, idempotency, encryption) is the server's.
 */
import type { PageLoader } from '../hooks/usePagedApi';
import { PAYOUT_STATUSES } from '../types/payouts';
import type {
	Payout,
	PayoutBalance,
	PayoutGiftCard,
	SettlementDocument,
	SettlementDocumentRow,
	PayoutCurrencyBalance,
	PayoutRequestInput,
	PayoutStatus,
} from '../types/payouts';
import { request, requestList } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

const ISO_UTC = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
const AMOUNT = /^-?\d{1,15}\.\d{4}$/;
const CURRENCY = /^[A-Z]{3}$/;
const METHOD = /^[a-z_]{1,20}$/;
const DOCUMENT_NUMBER = /^[A-Z]{1,10}-\d{1,20}$/;

function isTime( value: unknown ): value is string {
	return (
		typeof value === 'string' &&
		ISO_UTC.test( value ) &&
		! Number.isNaN( Date.parse( value ) )
	);
}

function isAmount( value: unknown ): value is string {
	return typeof value === 'string' && AMOUNT.test( value );
}

function isStatus( value: unknown ): value is PayoutStatus {
	return (
		typeof value === 'string' &&
		( PAYOUT_STATUSES as readonly string[] ).includes( value )
	);
}

/**
 * Validates one payout. Exactly the documented fields are read.
 *
 * @param body Item of the response.
 */
export function parsePayout( body: unknown ): Payout | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const {
		id,
		status,
		currency,
		amount,
		method,
		destination_hint: destinationHint,
		requested_at: requestedAt,
		decided_at: decidedAt,
		rejection_reason: rejectionReason,
		gift_card: giftCard,
		settlement_document: settlementDocument,
	} = body;

	if (
		typeof id !== 'number' ||
		! Number.isInteger( id ) ||
		id < 1 ||
		! isStatus( status ) ||
		typeof currency !== 'string' ||
		! CURRENCY.test( currency ) ||
		! isAmount( amount ) ||
		typeof method !== 'string' ||
		! METHOD.test( method ) ||
		typeof destinationHint !== 'string' ||
		! isTime( requestedAt ) ||
		( decidedAt !== null && ! isTime( decidedAt ) ) ||
		( rejectionReason !== null && typeof rejectionReason !== 'string' ) ||
		typeof giftCard !== 'boolean' ||
		( settlementDocument !== null &&
			( typeof settlementDocument !== 'string' ||
				! DOCUMENT_NUMBER.test( settlementDocument ) ) )
	) {
		return null;
	}

	return {
		id,
		status,
		currency,
		amount,
		method,
		destinationHint,
		requestedAt,
		decidedAt,
		rejectionReason,
		giftCard,
		settlementDocument,
	};
}

function parseCurrencyBalance( body: unknown ): PayoutCurrencyBalance | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const {
		currency,
		status,
		earned,
		completed,
		reserved,
		available,
		outstanding,
		unknown_rows: unknownRows,
	} = body;
	const unknown = status === 'unknown';

	if (
		typeof currency !== 'string' ||
		! CURRENCY.test( currency ) ||
		( status !== 'complete' && status !== 'unknown' ) ||
		! isAmount( completed ) ||
		! isAmount( reserved ) ||
		typeof unknownRows !== 'number' ||
		! Number.isInteger( unknownRows ) ||
		unknownRows < 0
	) {
		return null;
	}

	// Unknown has no amounts; known has all of them. Anything else is invalid,
	// so an unknown balance can never be shown as 0.
	for ( const value of [ earned, available, outstanding ] ) {
		if ( unknown ? value !== null : ! isAmount( value ) ) {
			return null;
		}
	}

	return {
		currency,
		status,
		earned: earned as string | null,
		completed,
		reserved,
		available: available as string | null,
		outstanding: outstanding as string | null,
		unknownRows,
	};
}

/**
 * Validates `GET /me/payouts/balance`.
 *
 * @param body Response body.
 */
export function parsePayoutBalance( body: unknown ): PayoutBalance | null {
	if ( ! isRecord( body ) || ! Array.isArray( body.currencies ) ) {
		return null;
	}

	const {
		holding_days: holdingDays,
		payout_start: payoutStart,
		methods,
		methods_usable: methodsUsable,
		gift_card_usable: giftCardUsable,
	} = body;
	const currencies: PayoutCurrencyBalance[] = [];

	for ( const entry of body.currencies as unknown[] ) {
		const parsed = parseCurrencyBalance( entry );

		if ( parsed === null ) {
			return null;
		}

		currencies.push( parsed );
	}

	if (
		typeof holdingDays !== 'number' ||
		! Number.isInteger( holdingDays ) ||
		holdingDays < 0 ||
		( payoutStart !== null && ! isTime( payoutStart ) ) ||
		! Array.isArray( methods ) ||
		! methods.every(
			( method ) => typeof method === 'string' && METHOD.test( method )
		) ||
		typeof methodsUsable !== 'boolean' ||
		typeof giftCardUsable !== 'boolean'
	) {
		return null;
	}

	return {
		currencies,
		holdingDays,
		payoutStart,
		methods: methods as string[],
		methodsUsable,
		giftCardUsable,
	};
}

const GIFT_CARD_STATUSES = [
	'active',
	'disabled',
	'expired',
	'other',
] as const;

/**
 * Validates `GET /me/payouts/{id}/gift-card`. Exactly the documented fields
 * are read.
 *
 * @param body Response body.
 */
export function parsePayoutGiftCard( body: unknown ): PayoutGiftCard | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const {
		code,
		amount,
		balance,
		currency,
		status,
		expires_at: expiresAt,
	} = body;

	if (
		typeof code !== 'string' ||
		code === '' ||
		! isAmount( amount ) ||
		! isAmount( balance ) ||
		typeof currency !== 'string' ||
		! CURRENCY.test( currency ) ||
		typeof status !== 'string' ||
		! ( GIFT_CARD_STATUSES as readonly string[] ).includes( status ) ||
		( expiresAt !== null && ! isTime( expiresAt ) )
	) {
		return null;
	}

	return {
		code,
		amount,
		balance,
		currency,
		status: status as PayoutGiftCard[ 'status' ],
		expiresAt,
	};
}

/**
 * One page of the creator's payouts, newest first.
 *
 * @param page    Page, from 1.
 * @param perPage Page size.
 * @param signal  Abort signal.
 */
export const getPayouts: PageLoader< Payout > = ( page, perPage, signal ) =>
	requestList(
		{ path: '/merchboard/v1/me/payouts', page, perPage, signal },
		parsePayout
	);

/**
 * The creator's balances, holding period, settlement start and methods.
 *
 * @param signal Abort signal.
 */
export function getPayoutBalance(
	signal?: AbortSignal
): Promise< ApiResult< PayoutBalance > > {
	return request(
		{
			path: '/merchboard/v1/me/payouts/balance',
			...( signal === undefined ? {} : { signal } ),
		},
		parsePayoutBalance
	);
}

/**
 * Requests a payout. Sending the same input with the same key again returns
 * the same payout, so a retry after a network error is safe.
 *
 * @param input Request.
 */
export function createPayout(
	input: PayoutRequestInput
): Promise< ApiResult< Payout > > {
	const { method, ...details } = input.details;

	return request(
		{
			path: '/merchboard/v1/me/payouts',
			method: 'POST',
			data: {
				currency: input.currency,
				amount: input.amount,
				method,
				details,
				idempotency_key: input.idempotencyKey,
			},
		},
		parsePayout
	);
}

/**
 * The gift card of the creator's own completed gift card payout, with its
 * code. Never cached by the server or the browser (`no-store`).
 *
 * @param id Payout id.
 */
export function getPayoutGiftCard(
	id: number
): Promise< ApiResult< PayoutGiftCard > > {
	if ( ! Number.isInteger( id ) || id < 1 ) {
		throw new Error( 'A payout id is a positive integer.' );
	}

	return request(
		{ path: `/merchboard/v1/me/payouts/${ id }/gift-card` },
		parsePayoutGiftCard
	);
}

function parseDocumentRow( body: unknown ): SettlementDocumentRow | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const {
		order_id: orderId,
		item_name: itemName,
		type,
		quantity,
		net,
		tax,
		payout,
	} = body;

	if (
		typeof orderId !== 'number' ||
		! Number.isInteger( orderId ) ||
		typeof itemName !== 'string' ||
		typeof type !== 'string' ||
		typeof quantity !== 'number' ||
		! Number.isInteger( quantity ) ||
		! isAmount( net ) ||
		! isAmount( tax ) ||
		! isAmount( payout )
	) {
		return null;
	}

	return { orderId, itemName, type, quantity, net, tax, payout };
}

/**
 * Validates `GET /me/payouts/{id}/settlement-document`. Exactly the
 * documented fields are read; a document that is not marked preliminary is
 * rejected (ADR-060, 060-9).
 *
 * @param body Response body.
 */
export function parseSettlementDocument(
	body: unknown
): SettlementDocument | null {
	if (
		! isRecord( body ) ||
		! Array.isArray( body.rows ) ||
		! Array.isArray( body.corrections ) ||
		! Array.isArray( body.company_address )
	) {
		return null;
	}

	const {
		number,
		type,
		preliminary,
		issued_at: issuedAt,
		period_from: periodFrom,
		period_to: periodTo,
		currency,
		commission,
		opening_balance: openingBalance,
		payout,
		closing_balance: closingBalance,
		vat_registered: vatRegistered,
		creator_name: creatorName,
		company_name: companyName,
		method,
		destination,
	} = body;
	const rows: SettlementDocumentRow[] = [];
	const corrections: SettlementDocument[ 'corrections' ][ number ][] = [];

	for ( const entry of body.rows as unknown[] ) {
		const row = parseDocumentRow( entry );

		if ( row === null ) {
			return null;
		}

		rows.push( row );
	}

	for ( const entry of body.corrections as unknown[] ) {
		if (
			! isRecord( entry ) ||
			typeof entry.number !== 'string' ||
			! isTime( entry.issued_at ) ||
			typeof entry.reason !== 'string'
		) {
			return null;
		}

		corrections.push( {
			number: entry.number,
			issuedAt: entry.issued_at,
			reason: entry.reason,
		} );
	}

	const address = body.company_address as unknown[];

	if (
		typeof number !== 'string' ||
		! DOCUMENT_NUMBER.test( number ) ||
		( type !== 'settlement' && type !== 'correction' ) ||
		preliminary !== true ||
		! isTime( issuedAt ) ||
		( periodFrom !== null && ! isTime( periodFrom ) ) ||
		! isTime( periodTo ) ||
		typeof currency !== 'string' ||
		! CURRENCY.test( currency ) ||
		! isAmount( commission ) ||
		! isAmount( openingBalance ) ||
		! isAmount( payout ) ||
		! isAmount( closingBalance ) ||
		( vatRegistered !== null && typeof vatRegistered !== 'boolean' ) ||
		typeof creatorName !== 'string' ||
		typeof companyName !== 'string' ||
		! address.every( ( line ) => typeof line === 'string' ) ||
		typeof method !== 'string' ||
		typeof destination !== 'string'
	) {
		return null;
	}

	return {
		number,
		type,
		preliminary,
		issuedAt,
		periodFrom,
		periodTo,
		currency,
		commission,
		openingBalance,
		payout,
		closingBalance,
		vatRegistered,
		creatorName,
		companyName,
		companyAddress: address as string[],
		method,
		destination,
		rows,
		corrections,
	};
}

/**
 * The settlement document of the creator's own completed payout.
 *
 * @param id Payout id.
 */
export function getSettlementDocument(
	id: number
): Promise< ApiResult< SettlementDocument > > {
	if ( ! Number.isInteger( id ) || id < 1 ) {
		throw new Error( 'A payout id is a positive integer.' );
	}

	return request(
		{ path: `/merchboard/v1/me/payouts/${ id }/settlement-document` },
		parseSettlementDocument
	);
}

/**
 * Cancels the creator's own pending payout.
 *
 * @param id Payout id.
 */
export function cancelPayout( id: number ): Promise< ApiResult< Payout > > {
	if ( ! Number.isInteger( id ) || id < 1 ) {
		throw new Error( 'A payout id is a positive integer.' );
	}

	return request(
		{ path: `/merchboard/v1/me/payouts/${ id }/cancel`, method: 'POST' },
		parsePayout
	);
}
