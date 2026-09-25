import type { ReactNode } from 'react';

export interface EmptyStateProps {
	readonly title: string;
	readonly description?: string;
	readonly action?: ReactNode;
}

/**
 * "Nothing here (yet)": explanation and an optional next step.
 */
export function EmptyState( { title, description, action }: EmptyStateProps ) {
	return (
		<div className="merchboard-empty-state">
			<p className="merchboard-empty-state__title">{ title }</p>
			{ description !== undefined && (
				<p className="merchboard-empty-state__description">
					{ description }
				</p>
			) }
			{ action !== undefined && (
				<div className="merchboard-empty-state__action">{ action }</div>
			) }
		</div>
	);
}
