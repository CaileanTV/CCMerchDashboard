/**
 * `PATCH /me/preferences` (ADR-044): the logged-in user's dashboard theme.
 * The user is always the one the server resolves; nothing here sends an id.
 */
import { isTheme } from '../theme/themes';
import type { ThemeChoice } from '../theme/themes';
import { request } from './client';
import type { ApiResult } from './client';
import { isRecord } from './validate';

export interface Preferences {
	readonly theme: ThemeChoice;
}

/**
 * Validates `{ theme: <theme> | null }`.
 *
 * @param body Response body.
 */
export function parsePreferences( body: unknown ): Preferences | null {
	if ( ! isRecord( body ) || ! ( 'theme' in body ) ) {
		return null;
	}

	const { theme } = body;

	if ( theme === null || isTheme( theme ) ) {
		return { theme };
	}

	return null;
}

/**
 * Saves the theme; null removes the explicit choice (follow the system).
 *
 * @param theme Theme or null.
 */
export function updateTheme(
	theme: ThemeChoice
): Promise< ApiResult< Preferences > > {
	return request(
		{
			path: '/merchboard/v1/me/preferences',
			method: 'PATCH',
			data: { theme },
		},
		parsePreferences
	);
}
