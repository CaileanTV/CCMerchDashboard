/**
 * The box with a side's layers: one compact row per element, front first.
 * Its small buttons carry icons, and each has a name for screen readers and
 * a tooltip.
 */
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import type { DesignObject } from './design-model';

const stroke = {
	fill: 'none',
	stroke: 'currentColor',
	strokeWidth: 1.5,
	strokeLinecap: 'round',
	strokeLinejoin: 'round',
} as const;

const ICONS = {
	text: <path { ...stroke } d="M3 4V3h10v1M8 3v10M6 13h4" />,
	image: (
		<>
			<rect { ...stroke } x="2" y="3" width="12" height="10" rx="1.5" />
			<path { ...stroke } d="m2.5 11.5 3.5-3.5 2.5 2.5 2-2 3 3" />
		</>
	),
	rect: <rect { ...stroke } x="2.5" y="3.5" width="11" height="9" rx="1" />,
	ellipse: <circle { ...stroke } cx="8" cy="8" r="5.5" />,
	triangle: <path { ...stroke } d="M8 3 14 13H2Z" />,
	up: <path { ...stroke } d="M8 13V3M4 7l4-4 4 4" />,
	down: <path { ...stroke } d="M8 3v10M4 9l4 4 4-4" />,
	shown: (
		<>
			<path
				{ ...stroke }
				d="M1.5 8S4 3.5 8 3.5 14.5 8 14.5 8 12 12.5 8 12.5 1.5 8 1.5 8Z"
			/>
			<circle { ...stroke } cx="8" cy="8" r="2" />
		</>
	),
	hidden: (
		<path
			{ ...stroke }
			d="M2 2l12 12M6.5 3.8A6 6 0 0 1 8 3.5C12 3.5 14.5 8 14.5 8a12 12 0 0 1-2 2.6M9.5 12.3a6 6 0 0 1-1.5.2C4 12.5 1.5 8 1.5 8a12 12 0 0 1 2-2.6"
		/>
	),
	locked: (
		<>
			<rect { ...stroke } x="3" y="7" width="10" height="7" rx="1.5" />
			<path { ...stroke } d="M5 7V5a3 3 0 0 1 6 0v2" />
		</>
	),
	unlocked: (
		<>
			<rect { ...stroke } x="3" y="7" width="10" height="7" rx="1.5" />
			<path { ...stroke } d="M5 7V5a3 3 0 0 1 5.8-1" />
		</>
	),
	remove: (
		<path
			{ ...stroke }
			d="M2.5 4.5h11M6 4.5V3h4v1.5M4 4.5l.7 9h6.6l.7-9M6.7 7v4M9.3 7v4"
		/>
	),
} satisfies Record< string, ReactNode >;

/**
 * An icon.
 *
 * @param props      Props.
 * @param props.name The icon.
 */
function Icon( props: { readonly name: keyof typeof ICONS } ) {
	return (
		<svg
			className="merchboard-product-designer__icon"
			viewBox="0 0 16 16"
			aria-hidden="true"
			focusable="false"
		>
			{ ICONS[ props.name ] }
		</svg>
	);
}

/**
 * A small button with an icon.
 *
 * @param props          Props.
 * @param props.icon     The icon.
 * @param props.label    Its name and tooltip.
 * @param props.pressed  Whether it is on, for a toggle.
 * @param props.disabled Whether it cannot be used.
 * @param props.onClick  Called on a click.
 */
function IconButton( props: {
	readonly icon: keyof typeof ICONS;
	readonly label: string;
	readonly pressed?: boolean;
	readonly disabled?: boolean;
	readonly onClick: () => void;
} ) {
	return (
		<button
			type="button"
			className="merchboard-product-designer__icon-button"
			title={ props.label }
			aria-label={ props.label }
			{ ...( props.pressed === undefined
				? {}
				: { 'aria-pressed': props.pressed } ) }
			disabled={ props.disabled === true }
			onClick={ props.onClick }
		>
			<Icon name={ props.icon } />
		</button>
	);
}

/**
 * An object's name in the list.
 *
 * @param object The object.
 */
function layerName( object: DesignObject ): string {
	switch ( object.type ) {
		case 'text':
			return object.text.slice( 0, 30 );
		case 'image':
			return __( 'Image', 'merchboard' );
		case 'rect':
			return __( 'Rectangle', 'merchboard' );
		case 'ellipse':
			return __( 'Circle', 'merchboard' );
		default:
			return __( 'Triangle', 'merchboard' );
	}
}

/**
 * The layers of a side, front first.
 *
 * @param props          Props.
 * @param props.objects  Objects, bottom first.
 * @param props.selected Selected id.
 * @param props.onSelect Selects.
 * @param props.onChange Changes an object.
 * @param props.onMove   Moves an object.
 * @param props.onRemove Removes an object.
 */
export function LayerList( props: {
	readonly objects: readonly DesignObject[];
	readonly selected: string | null;
	readonly onSelect: ( id: string ) => void;
	readonly onChange: ( id: string, change: Partial< DesignObject > ) => void;
	readonly onMove: ( id: string, direction: 1 | -1 ) => void;
	readonly onRemove: ( id: string ) => void;
} ) {
	const front = [ ...props.objects ].reverse();

	return (
		<section className="merchboard-product-designer__card">
			<h3>{ __( 'Layers', 'merchboard' ) }</h3>
			{ front.length === 0 ? (
				<p className="merchboard-product-designer__hint">
					{ __( 'This side has no elements yet.', 'merchboard' ) }
				</p>
			) : (
				<ol className="merchboard-product-designer__layers">
					{ front.map( ( object, index ) => (
						<li
							key={ object.id }
							className={
								object.id === props.selected
									? 'is-selected'
									: undefined
							}
						>
							<button
								type="button"
								className={ `merchboard-product-designer__layer-name${
									object.hidden ? ' is-hidden' : ''
								}` }
								onClick={ () => props.onSelect( object.id ) }
							>
								<Icon name={ object.type } />
								<span>{ layerName( object ) }</span>
							</button>
							<IconButton
								icon="up"
								label={ __( 'Forward', 'merchboard' ) }
								disabled={ index === 0 }
								onClick={ () => props.onMove( object.id, 1 ) }
							/>
							<IconButton
								icon="down"
								label={ __( 'Backward', 'merchboard' ) }
								disabled={ index === front.length - 1 }
								onClick={ () => props.onMove( object.id, -1 ) }
							/>
							<IconButton
								icon={ object.hidden ? 'hidden' : 'shown' }
								label={
									object.hidden
										? __( 'Show', 'merchboard' )
										: __( 'Hide', 'merchboard' )
								}
								pressed={ object.hidden }
								onClick={ () =>
									props.onChange( object.id, {
										hidden: ! object.hidden,
									} )
								}
							/>
							<IconButton
								icon={ object.locked ? 'locked' : 'unlocked' }
								label={
									object.locked
										? __( 'Unlock', 'merchboard' )
										: __( 'Lock', 'merchboard' )
								}
								pressed={ object.locked }
								onClick={ () =>
									props.onChange( object.id, {
										locked: ! object.locked,
									} )
								}
							/>
							<IconButton
								icon="remove"
								label={ __( 'Delete', 'merchboard' ) }
								onClick={ () => props.onRemove( object.id ) }
							/>
						</li>
					) ) }
				</ol>
			) }
		</section>
	);
}
