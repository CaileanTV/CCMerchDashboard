/**
 * Loading/success/error state for one API call, with retry and abort on
 * unmount. Plain React state; no global store (docs/FRONTEND.md).
 */
import { useCallback, useEffect, useState } from '@wordpress/element';

import type { ApiError, ApiResult } from '../api/client';

export type ApiState< T > =
	| { readonly status: 'loading' }
	| { readonly status: 'success'; readonly data: T }
	| { readonly status: 'error'; readonly error: ApiError };

export interface UseApi< T > {
	readonly state: ApiState< T >;
	readonly retry: () => void;
}

/**
 * Runs `load` on mount, whenever `load` changes and on retry().
 *
 * @param load Endpoint call; must be stable (e.g. module-level or useCallback).
 */
export function useApi< T >(
	load: ( signal: AbortSignal ) => Promise< ApiResult< T > >
): UseApi< T > {
	const [ state, setState ] = useState< ApiState< T > >( {
		status: 'loading',
	} );
	const [ attempt, setAttempt ] = useState( 0 );

	useEffect( () => {
		const controller = new AbortController();
		setState( { status: 'loading' } );

		load( controller.signal ).then(
			( result ) => {
				if ( ! controller.signal.aborted ) {
					setState(
						result.ok
							? { status: 'success', data: result.data }
							: { status: 'error', error: result.error }
					);
				}
			},
			() => {
				// A programming error in the endpoint function; show a generic error.
				if ( ! controller.signal.aborted ) {
					setState( {
						status: 'error',
						error: { kind: 'server', status: 0, code: null },
					} );
				}
			}
		);

		return () => controller.abort();
	}, [ load, attempt ] );

	const retry = useCallback(
		() => setAttempt( ( current ) => current + 1 ),
		[]
	);

	return { state, retry };
}
