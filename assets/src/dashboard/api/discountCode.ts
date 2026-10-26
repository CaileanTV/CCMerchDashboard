/**
 * `GET /me/discount-code` (ADR-068): the creator's own active discount code,
 * or null when there is none. Requested only when the creator chooses to
 * show it, so the code is never part of another response or of the page.
 * The creator is always the one the server resolves; nothing here sends an id.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

/** Longest code the server stores. */
const MAX_LENGTH = 191;

/**
 * Validates `{ code: string | null }`.
 *
 * @param body Response body.
 */
export function parseDiscountCode(
	body: unknown
): { code: string | null } | null {
	if ( ! isRecord( body ) || ! ( 'code' in body ) ) {
		return null;
	}

	const { code } = body;

	if ( code === null ) {
		return { code: null };
	}

	return typeof code === 'string' && code !== '' && code.length <= MAX_LENGTH
		? { code }
		: null;
}

/**
 * The creator's active discount code.
 */
export function getDiscountCode(): Promise<
	ApiResult< { code: string | null } >
> {
	return request(
		{ path: '/merchboard/v1/me/discount-code' },
		parseDiscountCode
	);
}

/**
 * Validates the reset response `{ code: string }`.
 *
 * @param body Response body.
 */
export function parseResetDiscountCode(
	body: unknown
): { code: string } | null {
	const parsed = parseDiscountCode( body );

	return parsed === null || parsed.code === null
		? null
		: { code: parsed.code };
}

/**
 * `POST /me/discount-code/reset` (ADR-078): replaces the creator's code with
 * a new one; the old one stops working at once. A refusal comes back as an
 * `invalid_request` error with its `reason` (e.g. `reset_limit`).
 */
export function resetDiscountCode(): Promise< ApiResult< { code: string } > > {
	return request(
		{ path: '/merchboard/v1/me/discount-code/reset', method: 'POST' },
		parseResetDiscountCode
	);
}
