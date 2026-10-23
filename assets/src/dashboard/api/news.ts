/**
 * `/me/news` (step 25.11, ADR-072): the published messages the creator sees,
 * newest first, with when the creator saw and closed each. The creator is
 * always the one the server resolves; every answer is validated.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

const PATH = '/merchboard/v1/me/news';

export interface NewsItem {
	readonly id: number;
	readonly title: string;
	/** Cleaned HTML; rendered only through NewsContent. */
	readonly body: string;
	readonly publishedAt: string;
	readonly seenAt: string | null;
	readonly closedAt: string | null;
}

function time( value: unknown ): string | null | undefined {
	if ( value === null ) {
		return null;
	}

	return typeof value === 'string' && ! Number.isNaN( Date.parse( value ) )
		? value
		: undefined;
}

/**
 * Validates `{ items: [ … ] }`.
 *
 * @param body Response body.
 */
export function parseNews( body: unknown ): readonly NewsItem[] | null {
	if ( ! isRecord( body ) || ! Array.isArray( body.items ) ) {
		return null;
	}

	const items: NewsItem[] = [];

	for ( const item of body.items ) {
		if ( ! isRecord( item ) ) {
			return null;
		}

		const publishedAt = time( item.published_at );
		const seenAt = time( item.seen_at );
		const closedAt = time( item.closed_at );

		if (
			typeof item.id !== 'number' ||
			! Number.isInteger( item.id ) ||
			item.id < 1 ||
			typeof item.title !== 'string' ||
			typeof item.body !== 'string' ||
			typeof publishedAt !== 'string' ||
			seenAt === undefined ||
			closedAt === undefined
		) {
			return null;
		}

		items.push( {
			id: item.id,
			title: item.title,
			body: item.body,
			publishedAt,
			seenAt,
			closedAt,
		} );
	}

	return items;
}

/**
 * The creator's news.
 *
 * @param signal Abort signal.
 */
export function getNews(
	signal?: AbortSignal
): Promise< ApiResult< readonly NewsItem[] > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parseNews
	);
}

/**
 * Notes that the creator saw or closed a message.
 *
 * @param id   Message.
 * @param what Seen or closed.
 */
export function markNews(
	id: number,
	what: 'seen' | 'closed'
): Promise< ApiResult< readonly NewsItem[] > > {
	return request(
		{ path: `${ PATH }/${ id }/${ what }`, method: 'POST' },
		parseNews
	);
}
