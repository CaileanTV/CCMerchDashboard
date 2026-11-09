/**
 * `GET /me/billing-details` and `POST /me/billing-details` (ADR-104): the
 * creator's own master data for settlement documents, where they stand, and
 * saving them (in force at once). A private person's CPR number is sent only
 * to add or change it and never comes back.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

/** The fields in display order. */
export const BILLING_FIELDS = [
	'legal_name',
	'address_1',
	'address_2',
	'postcode',
	'city',
	'country',
	'cvr',
] as const;

export type BillingField = ( typeof BILLING_FIELDS )[ number ];

/** Paid as a registered business or a private person (ADR-104); '' before phase 42. */
export type BillingType = 'business' | 'private' | '';

export type BillingValues = Readonly< Record< BillingField, string > > & {
	readonly type: BillingType;
};

export type BillingStatus = 'missing' | 'confirmed';

export interface BillingDetailsState {
	readonly status: BillingStatus;
	readonly confirmed: BillingValues | null;
	readonly confirmedAt: string | null;
	/** Whether the first payout still needs master data. */
	readonly requiredForPayout: boolean;
	/** Whether a requested first payout waits for them. */
	readonly payoutWaiting: boolean;
	/** Whether the creator is paid as a private person. */
	readonly cprRequired: boolean;
	/** Whether an encrypted CPR number is stored (never the number). */
	readonly cprSaved: boolean;
	/** Whether "Fill in from the CVR number" is available (ADR-105). */
	readonly cvrLookup: boolean;
}

/** Company data from the CVR register for the form (ADR-105). */
export type CvrValues = Readonly<
	Pick<
		Record< BillingField, string >,
		'legal_name' | 'address_1' | 'postcode' | 'city' | 'country'
	>
>;

/** Fired after master data were saved, so other parts reload. */
export const BILLING_DETAILS_CHANGED = 'merchboard:billing-details-changed';

/**
 * Validates master data values: every field a string and a known type.
 *
 * @param value Raw value.
 */
function parseValues( value: unknown ): BillingValues | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const values: Partial< Record< BillingField, string > > = {};

	for ( const field of BILLING_FIELDS ) {
		const raw = value[ field ];

		if ( typeof raw !== 'string' ) {
			return null;
		}

		values[ field ] = raw;
	}

	const type = value.type;

	if ( type !== 'business' && type !== 'private' && type !== '' ) {
		return null;
	}

	return { ...( values as Record< BillingField, string > ), type };
}

/**
 * Validates the response.
 *
 * @param body Response body.
 */
export function parseBillingDetails(
	body: unknown
): BillingDetailsState | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const {
		status,
		confirmed,
		confirmed_at: confirmedAt,
		required_for_payout: requiredForPayout,
		payout_waiting: payoutWaiting,
		cpr_required: cprRequired,
		cpr_saved: cprSaved,
		cvr_lookup: cvrLookup,
	} = body;

	if (
		( status !== 'missing' && status !== 'confirmed' ) ||
		typeof requiredForPayout !== 'boolean' ||
		typeof payoutWaiting !== 'boolean' ||
		typeof cprRequired !== 'boolean' ||
		typeof cprSaved !== 'boolean' ||
		( confirmedAt !== null && typeof confirmedAt !== 'string' )
	) {
		return null;
	}

	const confirmedValues =
		confirmed === null ? null : parseValues( confirmed );

	if ( confirmed !== null && confirmedValues === null ) {
		return null;
	}

	return {
		status,
		confirmed: confirmedValues,
		confirmedAt,
		requiredForPayout,
		payoutWaiting,
		cprRequired,
		cprSaved,
		cvrLookup: cvrLookup === true,
	};
}

/**
 * The creator's master data and where they stand.
 *
 * @param signal Cancels the request.
 */
export function getBillingDetails(
	signal?: AbortSignal
): Promise< ApiResult< BillingDetailsState > > {
	return request(
		{
			path: '/merchboard/v1/me/billing-details',
			...( signal === undefined ? {} : { signal } ),
		},
		parseBillingDetails
	);
}

/**
 * Saves master data. Invalid fields come back as `fields` (`required`,
 * `too_long`, `invalid`, `invalid_cvr`, `invalid_cpr` or `unavailable`).
 *
 * @param values Every field.
 * @param cpr    A private person's CPR number to add or change ('' keeps the stored one).
 */
export function submitBillingDetails(
	values: BillingValues,
	cpr = ''
): Promise< ApiResult< BillingDetailsState > > {
	return request(
		{
			path: '/merchboard/v1/me/billing-details',
			method: 'POST',
			data: { ...values, ...( cpr === '' ? {} : { cpr } ) },
		},
		parseBillingDetails
	);
}

/**
 * Validates a CVR lookup answer.
 *
 * @param body Response body.
 */
export function parseCvrValues( body: unknown ): CvrValues | null {
	if ( ! isRecord( body ) || ! isRecord( body.values ) ) {
		return null;
	}

	const values = body.values;
	const keys = [
		'legal_name',
		'address_1',
		'postcode',
		'city',
		'country',
	] as const;

	if ( keys.some( ( key ) => typeof values[ key ] !== 'string' ) ) {
		return null;
	}

	return {
		legal_name: values.legal_name as string,
		address_1: values.address_1 as string,
		postcode: values.postcode as string,
		city: values.city as string,
		country: values.country as string,
	};
}

/**
 * Looks up a company in the CVR register (through the server). A refusal
 * comes back with its `reason`: `cvr_not_found`, `invalid_cvr`,
 * `cvr_lookup_limit` or `cvr_lookup_unavailable`.
 *
 * @param cvr Eight digits.
 */
export function lookupCvr( cvr: string ): Promise< ApiResult< CvrValues > > {
	return request(
		{
			path: `/merchboard/v1/me/billing-details/cvr/${ encodeURIComponent( cvr ) }`,
		},
		parseCvrValues
	);
}
