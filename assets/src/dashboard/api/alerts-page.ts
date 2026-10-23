/**
 * The setup of the creator's overlay settings page for the admin page
 * (`/admin/alerts/creator-page`, step 25.9.6, `merchboard_manage_alerts`).
 * Every answer is the complete setup, normalized again before use.
 */
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';
import {
	pageLayoutBody,
	parsePageLayout,
} from '../sections/alerts/page-layout';
import type { PageLayout } from '../sections/alerts/page-layout';

const PATH = '/merchboard/v1/admin/alerts/creator-page';

/**
 * Validates the answer: an object with `cards` and `fields`.
 *
 * @param body Response body.
 */
export function parsePageAnswer( body: unknown ): PageLayout | null {
	return isRecord( body ) && isRecord( body.cards ) && isRecord( body.fields )
		? parsePageLayout( body )
		: null;
}

/**
 * The setup.
 *
 * @param signal Abort signal.
 */
export function getPageLayout(
	signal?: AbortSignal
): Promise< ApiResult< PageLayout > > {
	return request(
		{ path: PATH, ...( signal === undefined ? {} : { signal } ) },
		parsePageAnswer
	);
}

/**
 * Saves a setup.
 *
 * @param layout The setup.
 */
export function savePageLayout(
	layout: PageLayout
): Promise< ApiResult< PageLayout > > {
	return request(
		{ path: PATH, method: 'POST', data: pageLayoutBody( layout ) },
		parsePageAnswer
	);
}

/**
 * Goes back to the default setup.
 */
export function resetPageLayout(): Promise< ApiResult< PageLayout > > {
	return request( { path: PATH, method: 'DELETE' }, parsePageAnswer );
}
