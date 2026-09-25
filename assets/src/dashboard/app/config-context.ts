import { createContext, useContext } from '@wordpress/element';

import type { DashboardConfig } from '../types/config';

export const ConfigContext = createContext< DashboardConfig | null >( null );

/** The validated dashboard configuration; only inside <App>. */
export function useConfig(): DashboardConfig {
	const config = useContext( ConfigContext );

	if ( config === null ) {
		throw new Error( 'useConfig() must be used inside the dashboard app.' );
	}

	return config;
}
