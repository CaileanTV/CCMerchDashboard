/**
 * The logged-in creator, loaded once from `GET /me`. The server decides who
 * that is and whether the user has access; the UI only reflects the answer.
 */
import {
	createContext,
	useCallback,
	useContext,
	useMemo,
	useState,
} from '@wordpress/element';
import type { ReactNode } from 'react';

import { getMe } from '../api/creator';
import { useApi } from '../hooks/useApi';
import type { ApiState } from '../hooks/useApi';
import type { Me } from '../types/creator';

interface CreatorState {
	readonly state: ApiState< Me >;
	readonly retry: () => void;
	/** Replaces the known creator data after a successful profile save. */
	readonly update: ( me: Me ) => void;
}

const CreatorContext = createContext< CreatorState | null >( null );

export function CreatorProvider( {
	children,
}: {
	readonly children: ReactNode;
} ) {
	const { state, retry } = useApi( getMe );
	const [ updated, setUpdated ] = useState< Me | null >( null );

	const update = useCallback( ( me: Me ) => setUpdated( me ), [] );

	const value = useMemo( (): CreatorState => {
		const current: ApiState< Me > =
			state.status === 'success' && updated !== null
				? { status: 'success', data: updated }
				: state;

		return { state: current, retry, update };
	}, [ state, updated, retry, update ] );

	return (
		<CreatorContext.Provider value={ value }>
			{ children }
		</CreatorContext.Provider>
	);
}

export function useCreator(): CreatorState {
	const creator = useContext( CreatorContext );

	if ( creator === null ) {
		throw new Error(
			'useCreator() must be used inside <CreatorProvider>.'
		);
	}

	return creator;
}
