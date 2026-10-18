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
	SettlementCommissionVat,
	SettlementCorrectionAmounts,
	SettlementCorrectionMasterData,
	SettlementCorrectionValues,
	SettlementDocument,
	SettlementDocumentRow,
	SettlementMasterData,
	PayoutCurrencyBalance,
	PayoutRequestInput,
	PayoutStatus,
} from '../types/payouts';
import { request, requestFile, requestList } from './client';
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
 * Validates the VAT on the commission of a settlement document: null, or
 * the rate in basis points and four amounts with incl = excl + vat (in
 * units, so no floating point is involved).
 *
 * @param value Value of `commission_vat`.
 */
function parseCommissionVat(
	value: unknown
): SettlementCommissionVat | null | undefined {
	if ( value === null ) {
		return null;
	}

	if ( ! isRecord( value ) ) {
		return undefined;
	}

	const { rate_bps: rateBps, excl, vat, incl, untaxed } = value;

	if (
		typeof rateBps !== 'number' ||
		! Number.isInteger( rateBps ) ||
		rateBps < 1 ||
		rateBps > 10000 ||
		! isAmount( excl ) ||
		! isAmount( vat ) ||
		! isAmount( incl ) ||
		! isAmount( untaxed )
	) {
		return undefined;
	}

	const units = ( amount: string ) => BigInt( amount.replace( '.', '' ) );

	if ( units( excl ) + units( vat ) !== units( incl ) ) {
		return undefined;
	}

	return { rateBps, excl, vat, incl, untaxed };
}

/**
 * Validates the master data of a settlement document: null (documents before
 * template version 4), or the creator's legal name, address lines and CVR/SE
 * number and the issuer's CVR number as strings ('' = not registered).
 *
 * @param value Value of `master_data`.
 */
function parseMasterData(
	value: unknown
): SettlementMasterData | null | undefined {
	if ( value === null ) {
		return null;
	}

	if ( ! isRecord( value ) || ! Array.isArray( value.creator_address ) ) {
		return undefined;
	}

	const {
		creator_legal_name: creatorLegalName,
		creator_cvr: creatorCvr,
		company_cvr: companyCvr,
	} = value;
	const address = value.creator_address as unknown[];

	if (
		typeof creatorLegalName !== 'string' ||
		typeof creatorCvr !== 'string' ||
		typeof companyCvr !== 'string' ||
		! address.every( ( line ) => typeof line === 'string' )
	) {
		return undefined;
	}

	return {
		creatorLegalName,
		creatorAddress: address as string[],
		creatorCvr,
		companyCvr,
	};
}

/**
 * Validates the amounts of a correction document: null (correction
 * documents without amounts), or the suffix, the references, the rate and
 * the amounts before, after and the difference, which must add up (in
 * units, so no floating point is involved).
 *
 * @param value Value of `amounts`.
 */
function parseCorrectionAmounts(
	value: unknown
): SettlementCorrectionAmounts | null | undefined {
	if ( value === null ) {
		return null;
	}

	if ( ! isRecord( value ) ) {
		return undefined;
	}

	const values = ( entry: unknown ): SettlementCorrectionValues | null => {
		if ( ! isRecord( entry ) ) {
			return null;
		}

		const { commission, excl, vat, incl, untaxed } = entry;

		return isAmount( commission ) &&
			isAmount( excl ) &&
			isAmount( vat ) &&
			isAmount( incl ) &&
			isAmount( untaxed )
			? { commission, excl, vat, incl, untaxed }
			: null;
	};
	const { suffix, original, corrects, rate_bps: rateBps, payout } = value;
	const before = values( value.before );
	const after = values( value.after );
	const difference = values( value.difference );

	if (
		typeof suffix !== 'string' ||
		! /^K[1-9]\d{0,2}$/.test( suffix ) ||
		typeof original !== 'string' ||
		typeof corrects !== 'string' ||
		typeof rateBps !== 'number' ||
		! Number.isInteger( rateBps ) ||
		rateBps < 1 ||
		rateBps > 10000 ||
		! isAmount( payout ) ||
		before === null ||
		after === null ||
		difference === null
	) {
		return undefined;
	}

	const units = ( amount: string ) => BigInt( amount.replace( '.', '' ) );
	const keys = [ 'commission', 'excl', 'vat', 'incl', 'untaxed' ] as const;
	const addsUp = ( entry: SettlementCorrectionValues ) =>
		units( entry.incl ) + units( entry.untaxed ) ===
			units( entry.commission ) &&
		units( entry.excl ) + units( entry.vat ) === units( entry.incl );

	if (
		! addsUp( before ) ||
		! addsUp( after ) ||
		keys.some(
			( key ) =>
				units( after[ key ] ) - units( before[ key ] ) !==
				units( difference[ key ] )
		)
	) {
		return undefined;
	}

	return {
		suffix,
		original,
		corrects,
		rateBps,
		before,
		after,
		difference,
		payout,
	};
}

/**
 * Validates the master data of a correction document: null, or the strings
 * and address lines of both parties and whether they were corrected.
 *
 * @param value Value of `master_data` of a correction.
 */
function parseCorrectionMasterData(
	value: unknown
): SettlementCorrectionMasterData | null | undefined {
	if ( value === null ) {
		return null;
	}

	if (
		! isRecord( value ) ||
		! Array.isArray( value.creator_address ) ||
		! Array.isArray( value.company_address )
	) {
		return undefined;
	}

	const {
		creator_legal_name: creatorLegalName,
		creator_cvr: creatorCvr,
		company_name: companyName,
		company_cvr: companyCvr,
		changed,
	} = value;
	const creatorAddress = value.creator_address as unknown[];
	const companyAddress = value.company_address as unknown[];
	const lines = ( entries: unknown[] ) =>
		entries.every( ( line ) => typeof line === 'string' );

	if (
		typeof creatorLegalName !== 'string' ||
		typeof creatorCvr !== 'string' ||
		typeof companyName !== 'string' ||
		typeof companyCvr !== 'string' ||
		typeof changed !== 'boolean' ||
		! lines( creatorAddress ) ||
		! lines( companyAddress )
	) {
		return undefined;
	}

	return {
		creatorLegalName,
		creatorAddress: creatorAddress as string[],
		creatorCvr,
		companyName,
		companyAddress: companyAddress as string[],
		companyCvr,
		changed,
	};
}

/**
 * Validates `GET /me/payouts/{id}/settlement-document`. Exactly the
 * documented fields are read. `preliminary` is true for documents of
 * template version 1 (060-9) and false for later ones, which carry the note
 * on tax and reporting instead. `commission_vat` is null or the VAT on the
 * commission (template version 3).
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
		! Array.isArray( body.company_address ) ||
		! Array.isArray( body.adjustment_refs )
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
		adjustment,
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
		const amounts = isRecord( entry )
			? parseCorrectionAmounts( entry.amounts )
			: undefined;
		const correctionMaster = isRecord( entry )
			? parseCorrectionMasterData( entry.master_data )
			: undefined;

		if (
			! isRecord( entry ) ||
			amounts === undefined ||
			correctionMaster === undefined ||
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
			amounts,
			masterData: correctionMaster,
		} );
	}

	const address = body.company_address as unknown[];
	const references = body.adjustment_refs as unknown[];
	const commissionVat = parseCommissionVat( body.commission_vat );
	const masterData = parseMasterData( body.master_data );

	if (
		commissionVat === undefined ||
		masterData === undefined ||
		typeof number !== 'string' ||
		! DOCUMENT_NUMBER.test( number ) ||
		( type !== 'settlement' && type !== 'correction' ) ||
		typeof preliminary !== 'boolean' ||
		! isTime( issuedAt ) ||
		( periodFrom !== null && ! isTime( periodFrom ) ) ||
		! isTime( periodTo ) ||
		typeof currency !== 'string' ||
		! CURRENCY.test( currency ) ||
		! isAmount( commission ) ||
		! isAmount( adjustment ) ||
		! references.every( ( reference ) => typeof reference === 'string' ) ||
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
		commissionVat,
		adjustment,
		adjustmentReferences: references as string[],
		openingBalance,
		payout,
		closingBalance,
		vatRegistered,
		creatorName,
		companyName,
		companyAddress: address as string[],
		masterData,
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
 * The settlement document of the creator's own completed payout as a PDF,
 * made by the server from the stored document; with a suffix (e.g. "K1")
 * one of its correction documents with amounts.
 *
 * @param id         Payout id.
 * @param correction Suffix of a correction document, '' for the settlement document.
 */
export function downloadSettlementPdf(
	id: number,
	correction = ''
): Promise< ApiResult< Blob > > {
	if ( ! Number.isInteger( id ) || id < 1 ) {
		throw new Error( 'A payout id is a positive integer.' );
	}

	if ( correction !== '' && ! /^K[1-9]\d{0,2}$/.test( correction ) ) {
		throw new Error( 'A correction suffix is K followed by a number.' );
	}

	const query = correction === '' ? '' : `?correction=${ correction }`;

	return requestFile(
		{
			path: `/merchboard/v1/me/payouts/${ id }/settlement-document/pdf${ query }`,
		},
		'application/pdf'
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
