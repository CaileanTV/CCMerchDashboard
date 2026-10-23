/**
 * `/me/alerts/api` (steps 25.12.1 and 25.12.2, ADR-073): the creator's API
 * keys and webhooks for their own bots. A new key or signing secret comes
 * back once, in the answer that made it; the lists only have names, the
 * start of each key and each webhook's host. Every answer is validated.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

const PATH = '/merchboard/v1/me/alerts/api';

export interface AlertApiKeyItem {
	readonly id: number;
	readonly name: string;
	/** The start of the key, e.g. "mbk_0123456789ab_…". */
	readonly hint: string;
	readonly createdAt: string;
	readonly lastUsedAt: string | null;
}

export interface AlertApiState {
	readonly enabled: boolean;
	/** The bots' URL. */
	readonly endpoint: string;
	/** Requests per key per minute. */
	readonly limit: number;
	readonly maxKeys: number;
	readonly keys: readonly AlertApiKeyItem[];
	/** A key just made; shown once. */
	readonly key?: string;
	readonly maxWebhooks: number;
	readonly webhooks: readonly WebhookItem[];
	/** The newest deliveries, newest first. */
	readonly deliveries: readonly WebhookDeliveryItem[];
	/** A signing secret just made; shown once. */
	readonly secret?: string;
	/** The delivery of a test just sent. */
	readonly delivery?: WebhookDeliveryItem;
}

export type WebhookFormat = 'json' | 'discord';

export interface WebhookItem {
	readonly id: number;
	readonly name: string;
	readonly format: WebhookFormat;
	readonly host: string;
	/** The host and the end of the address. */
	readonly hint: string;
	/** Discord's message template ('' for the default). */
	readonly template: string;
	readonly enabled: boolean;
	readonly paused: boolean;
	readonly failures: number;
	readonly createdAt: string;
	readonly lastDeliveryAt: string | null;
}

export interface WebhookDeliveryItem {
	readonly id: number;
	readonly webhookId: number;
	readonly eventId: string;
	readonly kind: 'purchase' | 'test' | 'resend';
	readonly status: 'pending' | 'sent' | 'failed' | 'skipped';
	readonly httpStatus: number | null;
	readonly error: string | null;
	readonly createdAt: string;
}

/** What a new webhook needs. */
export interface NewWebhook {
	readonly name: string;
	readonly format: WebhookFormat;
	readonly url: string;
	/** The receiver's secret; '' to get one (JSON only). */
	readonly secret: string;
	readonly template: string;
}

function isTime( value: unknown ): value is string {
	return typeof value === 'string' && ! Number.isNaN( Date.parse( value ) );
}

function isCount( value: unknown ): value is number {
	return Number.isSafeInteger( value ) && ( value as number ) >= 1;
}

function isNatural( value: unknown ): value is number {
	return Number.isSafeInteger( value ) && ( value as number ) >= 0;
}

/**
 * Validates one webhook.
 *
 * @param item List item.
 */
function parseWebhook( item: unknown ): WebhookItem | null {
	if (
		! isRecord( item ) ||
		! isCount( item.id ) ||
		typeof item.name !== 'string' ||
		( item.format !== 'json' && item.format !== 'discord' ) ||
		typeof item.host !== 'string' ||
		typeof item.hint !== 'string' ||
		typeof item.template !== 'string' ||
		typeof item.enabled !== 'boolean' ||
		typeof item.paused !== 'boolean' ||
		! isNatural( item.failures ) ||
		! isTime( item.created_at ) ||
		( item.last_delivery_at !== null && ! isTime( item.last_delivery_at ) )
	) {
		return null;
	}

	return {
		id: item.id,
		name: item.name,
		format: item.format,
		host: item.host,
		hint: item.hint,
		template: item.template,
		enabled: item.enabled,
		paused: item.paused,
		failures: item.failures,
		createdAt: item.created_at,
		lastDeliveryAt: item.last_delivery_at as string | null,
	};
}

/**
 * Validates one delivery.
 *
 * @param item List item.
 */
function parseDelivery( item: unknown ): WebhookDeliveryItem | null {
	if (
		! isRecord( item ) ||
		! isCount( item.id ) ||
		! isCount( item.webhook_id ) ||
		typeof item.event_id !== 'string' ||
		( item.kind !== 'purchase' &&
			item.kind !== 'test' &&
			item.kind !== 'resend' ) ||
		( item.status !== 'pending' &&
			item.status !== 'sent' &&
			item.status !== 'failed' &&
			item.status !== 'skipped' ) ||
		( item.http_status !== null && ! isNatural( item.http_status ) ) ||
		( item.error !== null && typeof item.error !== 'string' ) ||
		! isTime( item.created_at )
	) {
		return null;
	}

	return {
		id: item.id,
		webhookId: item.webhook_id,
		eventId: item.event_id,
		kind: item.kind,
		status: item.status,
		httpStatus: item.http_status as number | null,
		error: item.error as string | null,
		createdAt: item.created_at,
	};
}

/**
 * Validates every item of a list; null when one is invalid.
 *
 * @param items Items.
 * @param parse Validator.
 */
function parseList< T >(
	items: unknown,
	parse: ( item: unknown ) => T | null
): T[] | null {
	if ( ! Array.isArray( items ) ) {
		return null;
	}

	const result: T[] = [];

	for ( const item of items as unknown[] ) {
		const parsed = parse( item );

		if ( parsed === null ) {
			return null;
		}

		result.push( parsed );
	}

	return result;
}

/**
 * Validates the API page.
 *
 * @param body Response body.
 */
export function parseAlertApi( body: unknown ): AlertApiState | null {
	if (
		! isRecord( body ) ||
		typeof body.enabled !== 'boolean' ||
		typeof body.endpoint !== 'string' ||
		! /^https?:\/\//.test( body.endpoint ) ||
		! isCount( body.limit ) ||
		! isCount( body.max_keys ) ||
		! Array.isArray( body.keys ) ||
		( body.key !== undefined &&
			( typeof body.key !== 'string' ||
				! /^mbk_[0-9a-f]{12}_[0-9a-f]{48}$/.test( body.key ) ) )
	) {
		return null;
	}

	const keys: AlertApiKeyItem[] = [];

	for ( const item of body.keys as unknown[] ) {
		if (
			! isRecord( item ) ||
			! isCount( item.id ) ||
			typeof item.name !== 'string' ||
			typeof item.hint !== 'string' ||
			! isTime( item.created_at ) ||
			( item.last_used_at !== null && ! isTime( item.last_used_at ) )
		) {
			return null;
		}

		keys.push( {
			id: item.id,
			name: item.name,
			hint: item.hint,
			createdAt: item.created_at,
			lastUsedAt: item.last_used_at as string | null,
		} );
	}

	const webhooks = parseList( body.webhooks, parseWebhook );
	const deliveries = parseList( body.deliveries, parseDelivery );
	const delivery =
		body.delivery === undefined
			? undefined
			: parseDelivery( body.delivery );

	if (
		webhooks === null ||
		deliveries === null ||
		delivery === null ||
		! isCount( body.max_webhooks ) ||
		( body.secret !== undefined &&
			( typeof body.secret !== 'string' ||
				! /^whsec_[0-9a-f]{64}$/.test( body.secret ) ) )
	) {
		return null;
	}

	return {
		enabled: body.enabled,
		endpoint: body.endpoint,
		limit: body.limit,
		maxKeys: body.max_keys,
		keys,
		...( typeof body.key === 'string' ? { key: body.key } : {} ),
		maxWebhooks: body.max_webhooks,
		webhooks,
		deliveries,
		...( typeof body.secret === 'string' ? { secret: body.secret } : {} ),
		...( delivery === undefined ? {} : { delivery } ),
	};
}

/**
 * Adds a webhook; the answer has a made secret once.
 *
 * @param webhook The new webhook.
 */
export function createWebhook(
	webhook: NewWebhook
): Promise< ApiResult< AlertApiState > > {
	return request(
		{
			path: `${ PATH }/webhooks`,
			method: 'POST',
			data: { ...webhook },
		},
		parseAlertApi
	);
}

/**
 * Changes a webhook: switch, resume, name or Discord template.
 *
 * @param id               Webhook.
 * @param changes          Changes.
 * @param changes.enabled  Switched on.
 * @param changes.resume   Resume after a pause.
 * @param changes.name     New name.
 * @param changes.template New Discord template.
 */
export function updateWebhook(
	id: number,
	changes: {
		readonly enabled?: boolean;
		readonly resume?: boolean;
		readonly name?: string;
		readonly template?: string;
	}
): Promise< ApiResult< AlertApiState > > {
	return request(
		{
			path: `${ PATH }/webhooks/${ id }`,
			method: 'PATCH',
			data: { ...changes },
		},
		parseAlertApi
	);
}

/**
 * Deletes a webhook and its deliveries.
 *
 * @param id Webhook.
 */
export function deleteWebhook(
	id: number
): Promise< ApiResult< AlertApiState > > {
	return request(
		{ path: `${ PATH }/webhooks/${ id }`, method: 'DELETE' },
		parseAlertApi
	);
}

/**
 * Sends a test purchase to a webhook at once; the answer has its delivery.
 *
 * @param id Webhook.
 */
export function testWebhook(
	id: number
): Promise< ApiResult< AlertApiState > > {
	return request(
		{ path: `${ PATH }/webhooks/${ id }/test`, method: 'POST' },
		parseAlertApi
	);
}

/**
 * Gives a JSON webhook a new signing secret.
 *
 * @param id     Webhook.
 * @param secret The receiver's secret; '' to get one.
 */
export function newWebhookSecret(
	id: number,
	secret: string
): Promise< ApiResult< AlertApiState > > {
	return request(
		{
			path: `${ PATH }/webhooks/${ id }/secret`,
			method: 'POST',
			data: { secret },
		},
		parseAlertApi
	);
}

/**
 * The creator's API page.
 *
 * @param signal Abort signal.
 */
export function getAlertApi(
	signal?: AbortSignal
): Promise< ApiResult< AlertApiState > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parseAlertApi
	);
}

/**
 * Makes a key; the answer has the whole key once.
 *
 * @param name The creator's name for it.
 */
export function createAlertApiKey(
	name: string
): Promise< ApiResult< AlertApiState > > {
	return request(
		{ path: `${ PATH }/keys`, method: 'POST', data: { name } },
		parseAlertApi
	);
}

/**
 * Revokes a key.
 *
 * @param id Key.
 */
export function revokeAlertApiKey(
	id: number
): Promise< ApiResult< AlertApiState > > {
	return request(
		{ path: `${ PATH }/keys/${ id }`, method: 'DELETE' },
		parseAlertApi
	);
}

/**
 * Sends a test event to the creator's bots.
 */
export function testAlertApi(): Promise< ApiResult< { sent: number } > > {
	return request( { path: `${ PATH }/test`, method: 'POST' }, ( body ) =>
		isRecord( body ) &&
		Number.isSafeInteger( body.sent ) &&
		( body.sent as number ) >= 0
			? { sent: body.sent as number }
			: null
	);
}
