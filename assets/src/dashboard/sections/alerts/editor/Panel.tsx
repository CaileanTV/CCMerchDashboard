/**
 * The side panel's building blocks (alert designer 2.0, ADR-088): a panel
 * that folds open and shut, a section inside it, and tabs. Each is a button
 * with aria-expanded or a tab list, so they work with the keyboard and a
 * screen reader.
 */
import { useId, useState } from '@wordpress/element';
import type { KeyboardEvent, ReactNode } from 'react';

/**
 * A panel of the side bar.
 *
 * @param props             Props.
 * @param props.title       Heading.
 * @param props.defaultOpen Whether it starts open.
 * @param props.level       "panel" (the side bar's own) or "section" (inside a panel).
 * @param props.children    Content.
 */
export function Panel( {
	title,
	defaultOpen = false,
	level = 'panel',
	children,
}: {
	readonly title: string;
	readonly defaultOpen?: boolean;
	readonly level?: 'panel' | 'section';
	readonly children: ReactNode;
} ) {
	const id = useId();
	const [ open, setOpen ] = useState( defaultOpen );
	const Heading = level === 'panel' ? 'h2' : 'h3';

	return (
		<div
			className={ `merchboard-alert-panel merchboard-alert-panel--${ level }${
				open ? ' is-open' : ''
			}` }
		>
			<Heading className="merchboard-alert-panel__heading">
				<button
					type="button"
					className="merchboard-alert-panel__toggle"
					aria-expanded={ open }
					aria-controls={ `${ id }-body` }
					onClick={ () => setOpen( ! open ) }
				>
					<span>{ title }</span>
					<span
						className="merchboard-alert-panel__chevron"
						aria-hidden="true"
					/>
				</button>
			</Heading>
			<div
				id={ `${ id }-body` }
				className="merchboard-alert-panel__body"
				hidden={ ! open }
			>
				{ children }
			</div>
		</div>
	);
}

/**
 * Tabs inside a panel.
 *
 * @param props       Props.
 * @param props.label Name of the tab list.
 * @param props.tabs  Tab key → label and content.
 */
export function Tabs< K extends string >( {
	label,
	tabs,
}: {
	readonly label: string;
	readonly tabs: ReadonlyArray< {
		readonly key: K;
		readonly label: string;
		readonly content: ReactNode;
	} >;
} ) {
	const id = useId();
	const [ active, setActive ] = useState< K | undefined >( tabs[ 0 ]?.key );

	const move = (
		event: KeyboardEvent< HTMLButtonElement >,
		index: number
	) => {
		const step =
			{ ArrowRight: 1, ArrowLeft: -1 }[ event.key as 'ArrowRight' ] ?? 0;

		if ( step === 0 || tabs.length === 0 ) {
			return;
		}

		event.preventDefault();
		const next = tabs[ ( index + step + tabs.length ) % tabs.length ];

		if ( next !== undefined ) {
			setActive( next.key );
			document.getElementById( `${ id }-tab-${ next.key }` )?.focus();
		}
	};

	return (
		<div className="merchboard-alert-tabs">
			<div
				className="merchboard-alert-tabs__list"
				role="tablist"
				aria-label={ label }
			>
				{ tabs.map( ( tab, index ) => (
					<button
						key={ tab.key }
						id={ `${ id }-tab-${ tab.key }` }
						type="button"
						role="tab"
						className="merchboard-alert-tabs__tab"
						aria-selected={ tab.key === active }
						aria-controls={ `${ id }-panel-${ tab.key }` }
						tabIndex={ tab.key === active ? 0 : -1 }
						onClick={ () => setActive( tab.key ) }
						onKeyDown={ ( event ) => move( event, index ) }
					>
						{ tab.label }
					</button>
				) ) }
			</div>
			{ tabs.map( ( tab ) => (
				<div
					key={ tab.key }
					id={ `${ id }-panel-${ tab.key }` }
					role="tabpanel"
					aria-labelledby={ `${ id }-tab-${ tab.key }` }
					hidden={ tab.key !== active }
					className="merchboard-alert-tabs__panel"
				>
					{ tab.content }
				</div>
			) ) }
		</div>
	);
}
