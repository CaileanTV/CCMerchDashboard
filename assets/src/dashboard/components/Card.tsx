import { useId } from '@wordpress/element';
import type { ReactNode } from 'react';

export interface CardProps {
	readonly title?: string;
	readonly headingLevel?: 2 | 3;
	/** Shown at the right of the title, e.g. a button (only with a title). */
	readonly actions?: ReactNode;
	readonly children: ReactNode;
}

/**
 * A content region. With a title it is a labelled `<section>`; actions, if
 * any, sit at the right of the title.
 */
export function Card( {
	title,
	headingLevel = 2,
	actions,
	children,
}: CardProps ) {
	const headingId = useId();
	const Heading = headingLevel === 2 ? 'h2' : 'h3';

	if ( title === undefined ) {
		return <div className="merchboard-card">{ children }</div>;
	}

	const heading = (
		<Heading id={ headingId } className="merchboard-card__title">
			{ title }
		</Heading>
	);

	return (
		<section className="merchboard-card" aria-labelledby={ headingId }>
			{ actions === undefined ? (
				heading
			) : (
				<div className="merchboard-card__header">
					{ heading }
					<div className="merchboard-card__actions">{ actions }</div>
				</div>
			) }
			{ children }
		</section>
	);
}
