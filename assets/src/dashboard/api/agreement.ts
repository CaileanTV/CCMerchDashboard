/**
 * `GET /me/agreement` and `POST /me/agreement` (ADR-092): where the creator
 * stands with the agreement, the current text, and accepting it. The
 * creator is always the one the server resolves; nothing here sends an id.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

export interface AgreementState {
	/** Whether the creator must accept the agreement at all. */
	readonly required: boolean;
	/** Whether the current version waits for the creator's acceptance. */
	readonly mustAccept: boolean;
	/** Whether going live and payouts are allowed as far as the agreement goes. */
	readonly satisfied: boolean;
	/** The newest version the creator accepted (0 for none). */
	readonly accepted: number;
	/** The current version (0 before the first is published). */
	readonly version: number;
	readonly publishedAt: string | null;
	/** The text as HTML from the server (sanitized there), or null. */
	readonly text: string | null;
}

/**
 * Validates the agreement response.
 *
 * @param body Response body.
 */
export function parseAgreement( body: unknown ): AgreementState | null {
	if ( ! isRecord( body ) ) {
		return null;
	}

	const {
		required,
		must_accept: mustAccept,
		satisfied,
		accepted,
		version,
		published_at: publishedAt,
		text,
	} = body;

	if (
		typeof required !== 'boolean' ||
		typeof mustAccept !== 'boolean' ||
		typeof satisfied !== 'boolean' ||
		typeof accepted !== 'number' ||
		typeof version !== 'number' ||
		( publishedAt !== null && typeof publishedAt !== 'string' ) ||
		( text !== null && typeof text !== 'string' )
	) {
		return null;
	}

	return {
		required,
		mustAccept,
		satisfied,
		accepted,
		version,
		publishedAt,
		text,
	};
}

/**
 * The creator's agreement.
 *
 * @param signal Cancels the request.
 */
export function getAgreement(
	signal?: AbortSignal
): Promise< ApiResult< AgreementState > > {
	return request(
		{
			path: '/merchboard/v1/me/agreement',
			...( signal === undefined ? {} : { signal } ),
		},
		parseAgreement
	);
}

/**
 * Accepts the version the creator read. A refusal comes back with its
 * `reason` (e.g. `agreement_outdated` when a newer version was published).
 *
 * @param version The version the creator read.
 */
export function acceptAgreement(
	version: number
): Promise< ApiResult< AgreementState > > {
	return request(
		{
			path: '/merchboard/v1/me/agreement',
			method: 'POST',
			data: { version, adult: true },
		},
		parseAgreement
	);
}
