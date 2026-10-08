/**
 * The theme selector's state: apply at once, persist with
 * `PATCH /me/preferences`, and never end in an older value.
 *
 * - A choice is applied to <html> immediately (no reload).
 * - Only one request is in flight. A choice made meanwhile waits, and only
 *   the newest waiting choice is sent when the request returns. So the server
 *   always receives the choices in order, and its last write is the user's
 *   last choice, even when the network is slow.
 * - A response that has been overtaken by a newer choice changes nothing.
 * - When the last request fails, the last persisted theme is applied again,
 *   so the page never shows a theme that was not saved, and the error is
 *   offered with a retry of that choice.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import type { ApiError } from '../api/client';
import { updateTheme } from '../api/preferences';
import { applyTheme } from '../theme/themes';
import type { ThemeChoice } from '../theme/themes';

export interface ThemePreference {
	/** The choice shown and applied. */
	readonly selected: ThemeChoice;
	/** Whether a request is in flight. */
	readonly saving: boolean;
	/** The error of the last failed save, or null. */
	readonly error: ApiError | null;
	readonly choose: ( choice: ThemeChoice ) => void;
	/** Sends the failed choice again. */
	readonly retry: () => void;
}

/**
 * @param initial The persisted choice when the page was rendered.
 * @param onSaved Called after the user's last choice was saved.
 */
export function useThemePreference(
	initial: ThemeChoice,
	onSaved: () => void
): ThemePreference {
	const [ selected, setSelected ] = useState< ThemeChoice >( initial );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState< ApiError | null >( null );

	const persisted = useRef< ThemeChoice >( initial );
	const inFlight = useRef( false );
	const waiting = useRef< { readonly choice: ThemeChoice } | null >( null );
	const failed = useRef< ThemeChoice >( initial );
	const mounted = useRef( true );
	const saved = useRef( onSaved );
	saved.current = onSaved;

	useEffect(
		() => () => {
			mounted.current = false;
		},
		[]
	);

	const send = useCallback(
		async ( choice: ThemeChoice ): Promise< void > => {
			inFlight.current = true;
			if ( mounted.current ) {
				setSaving( true );
			}

			const result = await updateTheme( choice );
			inFlight.current = false;

			if ( result.ok ) {
				persisted.current = result.data.theme;
			}

			const next = waiting.current;
			waiting.current = null;

			// A newer choice overtook this request: send it, ignore this response.
			if (
				next !== null &&
				! ( result.ok && next.choice === persisted.current )
			) {
				await send( next.choice );
				return;
			}

			// The DOM follows the outcome even after unmount; React state does not.
			applyTheme( persisted.current );

			if ( ! mounted.current ) {
				return;
			}

			setSaving( false );
			setSelected( persisted.current );

			if ( result.ok ) {
				saved.current();
			} else {
				failed.current = choice;
				setError( result.error );
			}
		},
		[]
	);

	const choose = useCallback(
		( choice: ThemeChoice ) => {
			setError( null );
			setSelected( choice );
			applyTheme( choice );

			if ( inFlight.current ) {
				waiting.current = { choice };
				return;
			}

			void send( choice );
		},
		[ send ]
	);

	const retry = useCallback( () => choose( failed.current ), [ choose ] );

	return { selected, saving, error, choose, retry };
}
