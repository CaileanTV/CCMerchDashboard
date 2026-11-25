/**
 * A form field that changes while it is used: a colour dragged in a picker,
 * an angle typed or stepped (steps 57.20 and 60.2). One undo step per use,
 * and at most one change per frame.
 */
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Waits for the next frame; a timer where the browser has no frames.
 *
 * @param run What to run.
 * @return Cancels it.
 */
function nextFrame( run: () => void ): () => void {
	if ( typeof window.requestAnimationFrame === 'function' ) {
		const frame = window.requestAnimationFrame( run );

		return () => window.cancelAnimationFrame( frame );
	}

	const timer = window.setTimeout( run, 16 );

	return () => window.clearTimeout( timer );
}

/**
 * A field that changes while it is used (a colour being dragged, an angle
 * being typed).
 *
 * - One undo step: it starts at the first change, not at focus, which some
 *   browsers never give a clicked field, and ends when the field is left,
 *   its value is committed or it goes away.
 * - At most one change per frame (step 57.20): the first goes at once, and
 *   the newest of those that follow within a frame at the next one. A
 *   picture that takes longer to draw than the changes come never falls
 *   behind; the last value always goes, before the step ends.
 *
 * The field shows `draft`, its newest value, until the step ends, so a value
 * waiting for its frame never jumps back.
 *
 * The ref is a callback: a field that comes later (the angle, once a part
 * becomes a gradient) gets its listeners too, and one that goes ends its
 * step.
 *
 * @param onBegin A change that goes on starts.
 * @param onEnd   It ends.
 * @param send    A live change's value.
 * @return The field's ref, its draft (null outside a step) and what to call
 *         with each live change's value.
 */
export function useLiveField< T extends HTMLInputElement, V >(
	onBegin: () => void,
	onEnd: () => void,
	send: ( value: V ) => void
): {
	readonly ref: ( field: T | null ) => void;
	readonly draft: V | null;
	readonly change: ( value: V ) => void;
} {
	const [ field, setField ] = useState< T | null >( null );
	const [ draft, setDraft ] = useState< V | null >( null );
	const open = useRef( false );
	const calls = useRef( { onBegin, onEnd, send } );
	calls.current = { onBegin, onEnd, send };
	// The value waiting for the next frame, and the frame's cancel.
	const waiting = useRef< { readonly value: V } | null >( null );
	const frame = useRef< ( () => void ) | null >( null );

	/** Sends what waits; the next frame then waits for what comes. */
	const tick = (): void => {
		const next = waiting.current;

		waiting.current = null;
		frame.current = null;

		if ( next !== null ) {
			frame.current = nextFrame( tick );
			calls.current.send( next.value );
		}
	};

	useEffect( () => {
		const end = (): void => {
			frame.current?.();
			frame.current = null;

			const last = waiting.current;

			waiting.current = null;

			if ( last !== null ) {
				calls.current.send( last.value );
			}

			if ( open.current ) {
				open.current = false;
				setDraft( null );
				calls.current.onEnd();
			}
		};

		// The native change event: the picker closed or the value was committed.
		field?.addEventListener( 'change', end );
		field?.addEventListener( 'blur', end );

		return () => {
			field?.removeEventListener( 'change', end );
			field?.removeEventListener( 'blur', end );
			end();
		};
	}, [ field ] );

	return {
		ref: setField,
		draft,
		change: ( value ) => {
			if ( ! open.current ) {
				open.current = true;
				calls.current.onBegin();
			}

			setDraft( value );

			if ( frame.current !== null ) {
				waiting.current = { value };

				return;
			}

			frame.current = nextFrame( tick );
			calls.current.send( value );
		},
	};
}
