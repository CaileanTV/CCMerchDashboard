import { useEffect } from '@wordpress/element';

import type { DashboardConfig } from '../types/config';
import { ConfigContext, useConfig } from './config-context';
import { ErrorBoundary } from './ErrorBoundary';
import { Layout } from './layout/Layout';
import { resolvePage } from './pages';
import { RouterProvider, useRouter } from './router';

function CurrentPage() {
	const { path } = useRouter();
	const { sections, siteName } = useConfig();
	const page = resolvePage( path, sections );

	useEffect( () => {
		document.title = `${ page.title } · ${ siteName }`;
	}, [ page.title, siteName ] );

	return (
		<Layout title={ page.title }>
			<ErrorBoundary>{ page.content }</ErrorBoundary>
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
				<RouterProvider>
					<CurrentPage />
				</RouterProvider>
			</ErrorBoundary>
		</ConfigContext.Provider>
	);
}
