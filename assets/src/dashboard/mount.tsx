import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { App } from './app/App';
import { parseConfig } from './bootstrap';
import { ErrorState } from './components/ErrorState';

/**
 * Renders the dashboard into its root element. Invalid bootstrap data shows a
 * generic error instead of a half-working app.
 *
 * @param root   Root element printed by the shell.
 * @param raw    Raw bootstrap data (window.merchboardDashboard).
 * @param origin Origin of the current page.
 * @return Function that unmounts the app.
 */
export function mountDashboard(
	root: HTMLElement,
	raw: unknown,
	origin: string
): () => void {
	const config = parseConfig( raw, origin );
	const reactRoot = createRoot( root );

	reactRoot.render(
		config === null ? (
			<div className="merchboard-fatal">
				<ErrorState
					title={ __(
						'The dashboard could not be started.',
						'merchboard'
					) }
					description={ __(
						'Please reload the page.',
						'merchboard'
					) }
				/>
			</div>
		) : (
			<App config={ config } />
		)
	);

	return () => reactRoot.unmount();
}
