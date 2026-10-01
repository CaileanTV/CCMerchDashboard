/**
 * Loading/success/error state for one page of a list route, with the current
 * page in component state (not in the URL). Built on useApi: changing the
 * page loads it, the previous request is aborted.
 */
import { useCallback, useState } from '@wordpress/element';

import type { ApiResult, Paginated } from '../api/client';
import { DEFAULT_PER_PAGE } from '../api/client';
import { useApi } from './useApi';
import type { ApiState } from './useApi';

export type PageLoader< T > = (
	page: number,
	perPage: number,
	signal: AbortSignal
) => Promise< ApiResult< Paginated< T > > >;

export interface UsePagedApi< T > {
	readonly state: ApiState< Paginated< T > >;
	readonly page: number;
	readonly setPage: ( page: number ) => void;
	readonly retry: () => void;
}

/**
 * The requested page of a list route.
 *
 * @param load    Endpoint call; must be stable (module-level or useCallback).
 * @param perPage Page size.
 */
export function usePagedApi< T >(
	load: PageLoader< T >,
	perPage: number = DEFAULT_PER_PAGE
): UsePagedApi< T > {
	const [ page, setPage ] = useState( 1 );
	const loadPage = useCallback(
		( signal: AbortSignal ) => load( page, perPage, signal ),
		[ load, page, perPage ]
	);
	const { state, retry } = useApi( loadPage );

	return { state, page, setPage, retry };
}
