import type { DashboardSection } from '../../types/config';
import { Link } from '../router';

export interface NavigationProps {
	readonly sections: readonly DashboardSection[];
	readonly onNavigate?: () => void;
}

/**
 * The section list shared by the sidebar and the mobile navigation.
 */
export function Navigation( { sections, onNavigate }: NavigationProps ) {
	return (
		<ul className="merchboard-nav__list">
			{ sections.map( ( section ) => (
				<li key={ section.id } className="merchboard-nav__item">
					<Link
						to={ section.path }
						className="merchboard-nav__link"
						{ ...( onNavigate === undefined
							? {}
							: { onNavigate } ) }
					>
						{ section.label }
					</Link>
				</li>
			) ) }
		</ul>
	);
}
