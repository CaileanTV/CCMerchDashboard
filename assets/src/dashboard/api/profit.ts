/**
 * Validation of the profit objects of the creator endpoints (ADR-050).
 */
import type { Profit, ProfitStatus } from '../types/profit';
import { isRecord } from './validate';

const AMOUNT = /^-?\d{1,15}\.\d{4}$/;
const CURRENCY = /^[A-Z]{3}$/;
const STATUSES: readonly ProfitStatus[] = [
	'complete',
	'incomplete',
	'unknown',
];

/**
 * A valid profit object, or null when malformed. A complete profit must have
 * an amount and the other statuses must not, so an unknown profit can never
 * turn into a number on the way.
 *
 * @param value Candidate.
 */
export function parseProfit( value: unknown ): Profit | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const { currency, status, amount } = value;

	if (
		typeof currency !== 'string' ||
		! CURRENCY.test( currency ) ||
		! STATUSES.includes( status as ProfitStatus )
	) {
		return null;
	}

	if ( status === 'complete' ) {
		return typeof amount === 'string' && AMOUNT.test( amount )
			? { currency, status, amount }
			: null;
	}

	return amount === null
		? { currency, status: status as ProfitStatus, amount: null }
		: null;
}

/**
 * A list of profit objects (one per currency), null for "no sales", or
 * undefined when malformed.
 *
 * @param value Candidate.
 */
export function parseProfitList(
	value: unknown
): readonly Profit[] | null | undefined {
	if ( value === null ) {
		return null;
	}

	if ( ! Array.isArray( value ) || value.length === 0 ) {
		return undefined;
	}

	const list: Profit[] = [];

	for ( const item of value ) {
		const profit = parseProfit( item );

		if ( profit === null ) {
			return undefined;
		}

		list.push( profit );
	}

	return list;
}
