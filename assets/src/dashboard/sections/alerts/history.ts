/**
 * Undo and redo for the overlay designer (phase 25, step 6.6): every
 * change is a step; a drag is one step (a checkpoint when it starts, live
 * updates that are not recorded, and the checkpoint dropped again when
 * nothing changed).
 */
import { useCallback, useMemo, useState } from '@wordpress/element';

/** Most steps kept. */
export const HISTORY_LIMIT = 100;

export interface History< T > {
	readonly past: readonly T[];
	readonly present: T;
	readonly future: readonly T[];
}

/**
 * A new history.
 *
 * @param present The current value.
 */
export function startHistory< T >( present: T ): History< T > {
	return { past: [], present, future: [] };
}

/**
 * Records a change as a step (an unchanged value records nothing).
 *
 * @param history History.
 * @param next    New value.
 */
export function record< T >( history: History< T >, next: T ): History< T > {
	if ( next === history.present ) {
		return history;
	}

	return {
		past: [ ...history.past, history.present ].slice( -HISTORY_LIMIT ),
		present: next,
		future: [],
	};
}

/**
 * Replaces the value without a step (live updates while dragging).
 *
 * @param history History.
 * @param next    New value.
 */
export function replace< T >( history: History< T >, next: T ): History< T > {
	return next === history.present ? history : { ...history, present: next };
}

/**
 * A step back to the current value, before live updates.
 *
 * @param history History.
 */
export function checkpoint< T >( history: History< T > ): History< T > {
	return {
		past: [ ...history.past, history.present ].slice( -HISTORY_LIMIT ),
		present: history.present,
		future: [],
	};
}

/**
 * Drops the last checkpoint when nothing changed after it.
 *
 * @param history History.
 */
export function settle< T >( history: History< T > ): History< T > {
	return history.past[ history.past.length - 1 ] === history.present
		? { ...history, past: history.past.slice( 0, -1 ) }
		: history;
}

/**
 * One step back.
 *
 * @param history History.
 */
export function undo< T >( history: History< T > ): History< T > {
	const previous = history.past[ history.past.length - 1 ];

	return previous === undefined
		? history
		: {
				past: history.past.slice( 0, -1 ),
				present: previous,
				future: [ history.present, ...history.future ],
			};
}

/**
 * One step forward again.
 *
 * @param history History.
 */
export function redo< T >( history: History< T > ): History< T > {
	const next = history.future[ 0 ];

	return next === undefined
		? history
		: {
				past: [ ...history.past, history.present ],
				present: next,
				future: history.future.slice( 1 ),
			};
}

type Update< T > = T | ( ( current: T ) => T );

function resolve< T >( update: Update< T >, current: T ): T {
	return typeof update === 'function'
		? ( update as ( value: T ) => T )( current )
		: update;
}

/**
 * State with undo and redo.
 *
 * @param initial Initial value.
 */
export function useHistory< T >( initial: T ) {
	const [ history, setHistory ] = useState( () => startHistory( initial ) );

	const set = useCallback(
		( update: Update< T > ) =>
			setHistory( ( current ) =>
				record( current, resolve( update, current.present ) )
			),
		[]
	);
	const live = useCallback(
		( update: Update< T > ) =>
			setHistory( ( current ) =>
				replace( current, resolve( update, current.present ) )
			),
		[]
	);

	return useMemo(
		() => ( {
			value: history.present,
			canUndo: history.past.length > 0,
			canRedo: history.future.length > 0,
			set,
			live,
			checkpoint: () => setHistory( checkpoint ),
			settle: () => setHistory( settle ),
			undo: () => setHistory( undo ),
			redo: () => setHistory( redo ),
		} ),
		[ history, set, live ]
	);
}
