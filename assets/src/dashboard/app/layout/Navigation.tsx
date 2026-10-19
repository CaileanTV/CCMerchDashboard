import { useEffect, useId, useState } from '@wordpress/element';

import type { DashboardSection } from '../../types/config';
import { Link, useRouter } from '../router';

export interface NavigationProps {
	readonly sections: readonly DashboardSection[];
	readonly onNavigate?: () => void;
}

/** A menu entry: a section, or a group of sections under one item. */
export type NavEntry =
	| { readonly kind: 'section'; readonly section: DashboardSection }
	| {
			readonly kind: 'group';
			readonly id: string;
			readonly label: string;
			readonly sections: readonly DashboardSection[];
	  };

/**
 * The menu entries in the server's order; a group takes the place of its
 * first section and collects the others.
 *
 * @param sections Sections from the server.
 */
export function navEntries(
	sections: readonly DashboardSection[]
): NavEntry[] {
	const entries: NavEntry[] = [];
	const groups = new Map< string, DashboardSection[] >();

	for ( const section of sections ) {
		if ( section.group === undefined ) {
			entries.push( { kind: 'section', section } );
			continue;
		}

		const members = groups.get( section.group.id );

		if ( members !== undefined ) {
			members.push( section );
			continue;
		}

		const created = [ section ];
		groups.set( section.group.id, created );
		entries.push( {
			kind: 'group',
			id: section.group.id,
			label: section.group.label,
			sections: created,
		} );
	}

	return entries;
}

function SectionLink( {
	section,
	onNavigate,
}: {
	readonly section: DashboardSection;
	readonly onNavigate: ( () => void ) | undefined;
} ) {
	return (
		<Link
			to={ section.path }
			className="merchboard-nav__link"
			{ ...( onNavigate === undefined ? {} : { onNavigate } ) }
		>
			{ section.label }
		</Link>
	);
}

/**
 * A group: a button that opens and closes its sections. It is open while
 * one of its sections is the current page.
 *
 * @param props            Props.
 * @param props.label      Group label.
 * @param props.sections   The group's sections.
 * @param props.onNavigate Called after navigating.
 */
function NavGroup( {
	label,
	sections,
	onNavigate,
}: {
	readonly label: string;
	readonly sections: readonly DashboardSection[];
	readonly onNavigate: ( () => void ) | undefined;
} ) {
	const listId = useId();
	const { path } = useRouter();
	const active = sections.some( ( section ) => section.path === path );
	const [ open, setOpen ] = useState( active );

	useEffect( () => {
		if ( active ) {
			setOpen( true );
		}
	}, [ active ] );

	return (
		<li className="merchboard-nav__item">
			<button
				type="button"
				className="merchboard-nav__link merchboard-nav__toggle"
				aria-expanded={ open }
				aria-controls={ listId }
				data-active={ active ? 'true' : undefined }
				onClick={ () => setOpen( ! open ) }
			>
				<span>{ label }</span>
				<span className="merchboard-nav__chevron" aria-hidden="true" />
			</button>
			<ul
				id={ listId }
				className="merchboard-nav__list merchboard-nav__sublist"
				hidden={ ! open }
			>
				{ sections.map( ( section ) => (
					<li key={ section.id } className="merchboard-nav__item">
						<SectionLink
							section={ section }
							onNavigate={ onNavigate }
						/>
					</li>
				) ) }
			</ul>
		</li>
	);
}

/**
 * The section list shared by the sidebar and the mobile navigation. Sections
 * of a group (e.g. "Alerts") sit under one item that opens and closes.
 *
 * @param props            Props.
 * @param props.sections   Sections from the server.
 * @param props.onNavigate Called after navigating (closes the mobile menu).
 */
export function Navigation( { sections, onNavigate }: NavigationProps ) {
	return (
		<ul className="merchboard-nav__list">
			{ navEntries( sections ).map( ( entry ) =>
				entry.kind === 'group' ? (
					<NavGroup
						key={ `group-${ entry.id }` }
						label={ entry.label }
						sections={ entry.sections }
						onNavigate={ onNavigate }
					/>
				) : (
					<li
						key={ entry.section.id }
						className="merchboard-nav__item"
					>
						<SectionLink
							section={ entry.section }
							onNavigate={ onNavigate }
						/>
					</li>
				)
			) }
		</ul>
	);
}
