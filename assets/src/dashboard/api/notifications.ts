/**
 * `/me/notifications` and `/me/notification-preferences` (phase 29,
 * ADR-079): the creator's bell and e-mail choices. The creator is always the
 * one the server resolves; every answer is validated. Notification texts
 * are plain text built by the server and are only ever rendered as text.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

const PATH = '/merchboard/v1/me/notifications';
const PREFERENCES_PATH = '/merchboard/v1/me/notification-preferences';

/** Longest notification text accepted. */
const MAX_TEXT = 500;

/** A dashboard path ('' = start page), as the router accepts it. */
const LINK = /^(?:[a-z0-9-]+(?:\/[a-z0-9-]+)*)?$/;

export interface NotificationItem {
	readonly id: number;
	readonly type: string;
	readonly text: string;
	/** Dashboard path the notification leads to ('' = start page). */
	readonly link: string;
	readonly createdAt: string;
	readonly read: boolean;
}

export interface NotificationPage {
	readonly items: readonly NotificationItem[];
	readonly unread: number;
	readonly total: number;
	readonly pages: number;
}

export interface EmailPreference {
	readonly key: string;
	readonly group: string;
	readonly label: string;
	readonly description: string;
	readonly enabled: boolean;
}

export interface EmailPreferences {
	readonly items: readonly EmailPreference[];
	/** Whether the creator has a WordPress user (and so gets e-mails). */
	readonly hasUser: boolean;
}

function count( value: unknown ): number | null {
	return typeof value === 'number' && Number.isInteger( value ) && value >= 0
		? value
		: null;
}

/**
 * Validates one page of the bell.
 *
 * @param body Response body.
 */
export function parseNotifications( body: unknown ): NotificationPage | null {
	if ( ! isRecord( body ) || ! Array.isArray( body.items ) ) {
		return null;
	}

	const unread = count( body.unread );
	const total = count( body.total );
	const pages = count( body.pages );

	if ( unread === null || total === null || pages === null ) {
		return null;
	}

	const items: NotificationItem[] = [];

	for ( const item of body.items ) {
		if (
			! isRecord( item ) ||
			typeof item.id !== 'number' ||
			! Number.isInteger( item.id ) ||
			item.id < 1 ||
			typeof item.type !== 'string' ||
			! /^[a-z_]{1,64}$/.test( item.type ) ||
			typeof item.text !== 'string' ||
			item.text === '' ||
			item.text.length > MAX_TEXT ||
			typeof item.link !== 'string' ||
			! LINK.test( item.link ) ||
			typeof item.created_at !== 'string' ||
			Number.isNaN( Date.parse( item.created_at ) ) ||
			typeof item.read !== 'boolean'
		) {
			return null;
		}

		items.push( {
			id: item.id,
			type: item.type,
			text: item.text,
			link: item.link,
			createdAt: item.created_at,
			read: item.read,
		} );
	}

	return { items, unread, total, pages };
}

/**
 * Validates `{ unread }`.
 *
 * @param body Response body.
 */
export function parseUnread( body: unknown ): number | null {
	return isRecord( body ) ? count( body.unread ) : null;
}

/**
 * Validates the e-mail choices.
 *
 * @param body Response body.
 */
export function parsePreferences( body: unknown ): EmailPreferences | null {
	if (
		! isRecord( body ) ||
		! Array.isArray( body.items ) ||
		typeof body.has_user !== 'boolean'
	) {
		return null;
	}

	const items: EmailPreference[] = [];

	for ( const item of body.items ) {
		if (
			! isRecord( item ) ||
			typeof item.key !== 'string' ||
			! /^[a-z_]{1,64}$/.test( item.key ) ||
			typeof item.group !== 'string' ||
			typeof item.label !== 'string' ||
			typeof item.description !== 'string' ||
			typeof item.enabled !== 'boolean'
		) {
			return null;
		}

		items.push( {
			key: item.key,
			group: item.group,
			label: item.label,
			description: item.description,
			enabled: item.enabled,
		} );
	}

	return { items, hasUser: body.has_user };
}

/**
 * One page of the creator's notifications, newest first.
 *
 * @param page    Page (1-based).
 * @param perPage Page size.
 * @param signal  Abort signal.
 */
export function getNotifications(
	page = 1,
	perPage = 10,
	signal?: AbortSignal
): Promise< ApiResult< NotificationPage > > {
	return request(
		{
			path: `${ PATH }?page=${ page }&per_page=${ perPage }`,
			...( signal === undefined ? {} : { signal } ),
		},
		parseNotifications
	);
}

/**
 * Marks notifications as read; answers the unread count.
 *
 * @param ids The ids, or 'all'.
 */
export function markNotificationsRead(
	ids: readonly number[] | 'all'
): Promise< ApiResult< number > > {
	return request(
		{
			path: `${ PATH }/read`,
			method: 'POST',
			data: ids === 'all' ? { all: true } : { ids: [ ...ids ] },
		},
		parseUnread
	);
}

/**
 * The e-mails the creator may turn off.
 */
export function getEmailPreferences(): Promise<
	ApiResult< EmailPreferences >
> {
	return request( { path: PREFERENCES_PATH }, parsePreferences );
}

/**
 * Saves which e-mails are off.
 *
 * @param off Keys of the e-mails turned off.
 */
export function updateEmailPreferences(
	off: readonly string[]
): Promise< ApiResult< EmailPreferences > > {
	return request(
		{ path: PREFERENCES_PATH, method: 'PATCH', data: { off: [ ...off ] } },
		parsePreferences
	);
}
