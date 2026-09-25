import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { ApiErrorState } from '../components/ApiErrorState';
import { Skeleton } from '../components/Skeleton';
import type { DashboardConfig } from '../types/config';
import { ConfigContext, useConfig } from './config-context';
import { CreatorProvider, useCreator } from './creator-context';
import { ErrorBoundary } from './ErrorBoundary';
import { Layout } from './layout/Layout';
import { resolvePage } from './pages';
import { RouterProvider, useRouter } from './router';

/**
 * The page for the current path. Page content is only rendered once
 * `GET /me` confirmed the creator; while it loads a skeleton is shown, and a
 * failure (401, 403, network …) replaces the content with its error state.
 */
function CurrentPage() {
	const { path } = useRouter();
	const { sections, siteName } = useConfig();
	const { state, retry } = useCreator();
	const page = resolvePage( path, sections );

	useEffect( () => {
		document.title =
			siteName === '' ? page.title : `${ page.title } · ${ siteName }`;
	}, [ page.title, siteName ] );

	let content = page.content;

	if ( state.status === 'loading' ) {
		content = (
			<Skeleton label={ __( 'Loading your dashboard…', 'merchboard' ) } />
		);
	} else if ( state.status === 'error' ) {
		content = <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	return (
		<Layout
			title={ page.title }
			{ ...( state.status === 'success'
				? { creatorName: state.data.displayName }
				: {} ) }
		>
			<ErrorBoundary>{ content }</ErrorBoundary>
		</Layout>
	);
}

export interface AppProps {
	readonly config: DashboardConfig;
}

/**
 * The creator dashboard application.
 */
export function App( { config }: AppProps ) {
	return (
		<ConfigContext.Provider value={ config }>
			<ErrorBoundary>
				<CreatorProvider>
					<RouterProvider>
						<CurrentPage />
					</RouterProvider>
				</CreatorProvider>
			</ErrorBoundary>
		</ConfigContext.Provider>
	);
}
