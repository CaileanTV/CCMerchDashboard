import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

export interface ErrorStateProps {
	readonly title: string;
	readonly description?: string;
	readonly action?: ReactNode;
}

/**
 * Something could not be shown. Announced immediately (role "alert") and
 * labelled "Error" in text, not only by colour.
 */
export function ErrorState( { title, description, action }: ErrorStateProps ) {
	return (
		<div className="merchboard-error-state" role="alert">
			<p className="merchboard-error-state__title">
				<span className="merchboard-error-state__label">
					{ __( 'Error:', 'merchboard' ) }
				</span>{ ' ' }
				{ title }
			</p>
			{ description !== undefined && (
				<p className="merchboard-error-state__description">
					{ description }
				</p>
			) }
			{ action !== undefined && (
				<div className="merchboard-error-state__action">{ action }</div>
			) }
		</div>
	);
}
