import { useId } from '@wordpress/element';
import type { ReactNode } from 'react';

export interface CardProps {
	readonly title?: string;
	readonly headingLevel?: 2 | 3;
	readonly children: ReactNode;
}

/**
 * A content region. With a title it is a labelled `<section>`.
 */
export function Card( { title, headingLevel = 2, children }: CardProps ) {
	const headingId = useId();
	const Heading = headingLevel === 2 ? 'h2' : 'h3';

	if ( title === undefined ) {
		return <div className="merchboard-card">{ children }</div>;
	}

	return (
		<section className="merchboard-card" aria-labelledby={ headingId }>
			<Heading id={ headingId } className="merchboard-card__title">
				{ title }
			</Heading>
			{ children }
		</section>
	);
}
