import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { ErrorState } from '../components/ErrorState';

interface Props {
	readonly children: ReactNode;
}

interface State {
	readonly failed: boolean;
}

/**
 * Keeps a rendering error from blanking the whole page. The error itself is
 * not shown (it may contain internal details).
 */
export class ErrorBoundary extends Component< Props, State > {
	override state: State = { failed: false };

	static getDerivedStateFromError(): State {
		return { failed: true };
	}

	override render() {
		if ( this.state.failed ) {
			return (
				<ErrorState
					title={ __(
						'This page could not be displayed.',
						'merchboard'
					) }
					description={ __(
						'Please reload the page.',
						'merchboard'
					) }
				/>
			);
		}

		return this.props.children;
	}
}
