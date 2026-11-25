/**
 * `/me/thank-you-note` (phase 58): the creator's thank-you note, printed on
 * the packing slip of an order with the creator's products.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

export interface ThankYouNoteValues {
	readonly enabled: boolean;
	/** Plain text; line breaks are kept. */
	readonly text: string;
}

export interface ThankYouNoteState extends ThankYouNoteValues {
	readonly maxText: number;
	readonly maxLines: number;
}

const PATH = '/merchboard/v1/me/thank-you-note';

/**
 * Validates the response.
 *
 * @param body Response body.
 */
export function parseThankYouNote( body: unknown ): ThankYouNoteState | null {
	if ( ! isRecord( body ) || ! isRecord( body.limits ) ) {
		return null;
	}

	const { enabled, text } = body;
	const maxText = body.limits.max_text;
	const maxLines = body.limits.max_lines;

	if (
		typeof enabled !== 'boolean' ||
		typeof text !== 'string' ||
		typeof maxText !== 'number' ||
		typeof maxLines !== 'number'
	) {
		return null;
	}

	return { enabled, text, maxText, maxLines };
}

/**
 * The creator's note.
 *
 * @param signal Abort signal.
 */
export function getThankYouNote(
	signal?: AbortSignal
): Promise< ApiResult< ThankYouNoteState > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parseThankYouNote
	);
}

/**
 * Saves the note.
 *
 * @param values The form.
 */
export function saveThankYouNote(
	values: ThankYouNoteValues
): Promise< ApiResult< ThankYouNoteState > > {
	return request(
		{
			path: PATH,
			method: 'POST',
			data: { enabled: values.enabled, text: values.text },
		},
		parseThankYouNote
	);
}
