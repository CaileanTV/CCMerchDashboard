/**
 * A small History API router limited to the dashboard (docs/FRONTEND.md).
 * Paths are validated by normalizePath(), so navigation can only ever go to
 * a dashboard URL on the same site; the server renders the same shell for
 * every deep link, so refresh works.
 */
import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import type { MouseEvent, ReactNode } from 'react';

import { useConfig } from './config-context';
import { normalizePath, pathFromLocation, urlForPath } from './routes';

interface Router {
	/** Current normalized path ('' = start page). */
	readonly path: string;
	/** Navigates to a dashboard path; invalid paths are ignored. */
	readonly navigate: ( path: string ) => void;
}

const RouterContext = createContext< Router | null >( null );

export function RouterProvider( {
	children,
}: {
	readonly children: ReactNode;
} ) {
	const config = useConfig();
	const [ path, setPath ] = useState( () =>
		pathFromLocation( window.location, config )
	);

	useEffect( () => {
		const onPopState = () =>
			setPath( pathFromLocation( window.location, config ) );
		window.addEventListener( 'popstate', onPopState );

		return () => window.removeEventListener( 'popstate', onPopState );
	}, [ config ] );

	const navigate = useCallback(
		( target: string ) => {
			const next = normalizePath( target );

			if ( next === null ) {
				return;
			}

			window.history.pushState( null, '', urlForPath( next, config ) );
			setPath( next );
		},
		[ config ]
	);

	const router = useMemo( () => ( { path, navigate } ), [ path, navigate ] );

	return (
		<RouterContext.Provider value={ router }>
			{ children }
		</RouterContext.Provider>
	);
}

export function useRouter(): Router {
	const router = useContext( RouterContext );

	if ( router === null ) {
		throw new Error( 'useRouter() must be used inside <RouterProvider>.' );
	}

	return router;
}

export interface LinkProps {
	/** Dashboard path ('' = start page). */
	readonly to: string;
	readonly className?: string;
	readonly onNavigate?: () => void;
	readonly children: ReactNode;
}

/**
 * A real `<a href>` (works without JS, in new tabs and for assistive
 * technology) that navigates client-side on a plain left click.
 */
export function Link( { to, className, onNavigate, children }: LinkProps ) {
	const config = useConfig();
	const { path, navigate } = useRouter();
	const target = normalizePath( to ) ?? '';

	const onClick = ( event: MouseEvent< HTMLAnchorElement > ) => {
		if (
			event.defaultPrevented ||
			event.button !== 0 ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey
		) {
			return;
		}

		event.preventDefault();
		navigate( target );
		onNavigate?.();
	};

	return (
		<a
			href={ urlForPath( target, config ) }
			className={ className }
			aria-current={ target === path ? 'page' : undefined }
			onClick={ onClick }
		>
			{ children }
		</a>
	);
}
