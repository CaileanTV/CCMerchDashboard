/**
 * The dashboard themes (ADR-043) and the `data-theme` attribute on <html>.
 *
 * The server renders `data-theme` only for the user's explicit choice
 * (ADR-044); without it CSS follows the system's `prefers-color-scheme`. The
 * attribute is therefore the current choice: no bootstrap field, no browser
 * storage. The server validates every value again; this list only drives the
 * selector and the attribute.
 */
import { __ } from '@wordpress/i18n';

/** The six themes, in display order (ThemePreference::THEMES). */
export const THEMES = [
	'light',
	'dark-black',
	'dark-green',
	'dark-blue',
	'dark-red',
	'dark-pink',
] as const;

export type Theme = ( typeof THEMES )[ number ];

/** An explicit theme, or null for "follow the system". */
export type ThemeChoice = Theme | null;

export function isTheme( value: unknown ): value is Theme {
	return (
		typeof value === 'string' &&
		( THEMES as readonly string[] ).includes( value )
	);
}

/**
 * The translated name of a theme.
 *
 * @param theme Theme.
 */
export function themeLabel( theme: Theme ): string {
	switch ( theme ) {
		case 'light':
			return __( 'Light', 'merchboard' );
		case 'dark-black':
			return __( 'Dark black', 'merchboard' );
		case 'dark-green':
			return __( 'Dark green', 'merchboard' );
		case 'dark-blue':
			return __( 'Dark blue', 'merchboard' );
		case 'dark-red':
			return __( 'Dark red', 'merchboard' );
		case 'dark-pink':
			return __( 'Dark pink', 'merchboard' );
	}
}

/**
 * The applied choice: the valid `data-theme` on <html>, or null.
 *
 * @param root Document element.
 */
export function readTheme(
	root: HTMLElement = document.documentElement
): ThemeChoice {
	const value = root.getAttribute( 'data-theme' );

	return isTheme( value ) ? value : null;
}

/**
 * Applies a choice at once: sets `data-theme`, or removes it so CSS follows
 * the system preference again.
 *
 * @param choice Theme or null.
 * @param root   Document element.
 */
export function applyTheme(
	choice: ThemeChoice,
	root: HTMLElement = document.documentElement
): void {
	if ( choice === null ) {
		root.removeAttribute( 'data-theme' );
	} else {
		root.setAttribute( 'data-theme', choice );
	}
}
