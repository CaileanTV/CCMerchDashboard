/**
 * Entry point of the creator page tab (Merch Dashboard → Alerts → Creator
 * page, step 25.9.6; build/alerts-page-admin.js): a sketch of the creators'
 * overlay settings page where the administrator drags the cards into full
 * width or the left or right column and a card's fields into order, or uses
 * the buttons (keyboard and touch). Every change is saved at once.
 */
import { createRoot, useId, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { DragEvent } from 'react';

import {
	getPageLayout,
	resetPageLayout,
	savePageLayout,
} from '../dashboard/api/alerts-page';
import type { ApiError } from '../dashboard/api/client';
import { ApiErrorState } from '../dashboard/components/ApiErrorState';
import { Button } from '../dashboard/components/Button';
import { Card } from '../dashboard/components/Card';
import { Skeleton } from '../dashboard/components/Skeleton';
import { ToastStack, useToastStack } from '../dashboard/components/ToastStack';
import { useApi } from '../dashboard/hooks/useApi';
import {
	ZONES,
	cardLabel,
	fieldLabel,
	hasFields,
	zoneLabel,
} from '../dashboard/sections/alerts/page-layout';
import type {
	FieldCard,
	PageCard,
	PageField,
	PageLayout,
	Zone,
} from '../dashboard/sections/alerts/page-layout';
import '../dashboard/styles/index.scss';

/**
 * Moves a card to a place in a zone (at the end when the index is past it).
 *
 * @param layout The setup.
 * @param card   Card.
 * @param zone   Target zone.
 * @param index  Target index in the zone, counted without the card.
 */
export function moveCard(
	layout: PageLayout,
	card: PageCard,
	zone: Zone,
	index: number
): PageLayout {
	const cards = {} as Record< Zone, PageCard[] >;

	for ( const name of ZONES ) {
		cards[ name ] = layout.cards[ name ].filter(
			( item ) => item !== card
		);
	}

	cards[ zone ].splice( Math.min( index, cards[ zone ].length ), 0, card );

	return { ...layout, cards };
}

/**
 * Moves a field to a place in its card.
 *
 * @param layout The setup.
 * @param card   Card.
 * @param field  Field.
 * @param index  Target index, counted without the field.
 */
export function moveField(
	layout: PageLayout,
	card: FieldCard,
	field: PageField,
	index: number
): PageLayout {
	const order = layout.fields[ card ].filter( ( item ) => item !== field );
	order.splice( Math.min( index, order.length ), 0, field );

	return { ...layout, fields: { ...layout.fields, [ card ]: order } };
}

/** What is being dragged. */
type Drag =
	| { readonly kind: 'card'; readonly card: PageCard }
	| {
			readonly kind: 'field';
			readonly card: FieldCard;
			readonly field: PageField;
	  };

/**
 * The editor.
 *
 * @param props         Props.
 * @param props.initial The setup as loaded.
 */
export function PageSetupEditor( {
	initial,
}: {
	readonly initial: PageLayout;
} ) {
	const id = useId();
	const [ layout, setLayout ] = useState( initial );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ announcement, setAnnouncement ] = useState( '' );
	const [ open, setOpen ] = useState< PageCard | null >( null );
	const [ busy, setBusy ] = useState( false );
	const drag = useRef< Drag | null >( null );
	// One save at a time, in the order of the changes.
	const queue = useRef< Promise< void > >( Promise.resolve() );
	const { toasts, notify, dismiss } = useToastStack();

	const change = ( next: PageLayout, message: string ) => {
		setLayout( next );
		setAnnouncement( message );
		queue.current = queue.current.then( async () => {
			const result = await savePageLayout( next );

			if ( result.ok ) {
				setError( null );
				notify( __( 'Setup saved.', 'merchboard' ) );
			} else {
				setError( result.error );
			}
		} );
	};

	const placeCard = ( card: PageCard, zone: Zone, index: number ) => {
		const next = moveCard( layout, card, zone, index );
		change(
			next,
			sprintf(
				/* translators: 1: card, 2: column or full width, 3: position. */
				__( '%1$s: %2$s, position %3$d.', 'merchboard' ),
				cardLabel( card ),
				zoneLabel( zone ),
				next.cards[ zone ].indexOf( card ) + 1
			)
		);
	};

	const placeField = ( card: FieldCard, field: PageField, index: number ) => {
		const next = moveField( layout, card, field, index );
		change(
			next,
			sprintf(
				/* translators: 1: field, 2: position. */
				__( '%1$s: position %2$d.', 'merchboard' ),
				fieldLabel( field ),
				next.fields[ card ].indexOf( field ) + 1
			)
		);
	};

	const reset = async () => {
		if (
			// eslint-disable-next-line no-alert -- Throwing the setup away needs an explicit confirmation.
			! window.confirm(
				__(
					'Reset the creator page to the default setup?',
					'merchboard'
				)
			)
		) {
			return;
		}

		setBusy( true );
		await queue.current;
		const result = await resetPageLayout();
		setBusy( false );

		if ( result.ok ) {
			setError( null );
			setLayout( result.data );
			notify( __( 'The default setup is used again.', 'merchboard' ) );
		} else {
			setError( result.error );
		}
	};

	// Native drag and drop; the buttons do the same for keyboard and touch.
	const allowDrop = ( event: DragEvent, wanted: Drag[ 'kind' ] ) => {
		if ( drag.current?.kind === wanted ) {
			event.preventDefault();
		}
	};

	const dropCard = ( event: DragEvent, zone: Zone, index: number ) => {
		const dragged = drag.current;

		if ( dragged?.kind !== 'card' ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		drag.current = null;
		placeCard( dragged.card, zone, index );
	};

	const dropField = ( event: DragEvent, card: FieldCard, index: number ) => {
		const dragged = drag.current;

		if ( dragged?.kind !== 'field' || dragged.card !== card ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		drag.current = null;
		placeField( card, dragged.field, index );
	};

	const fields = ( card: FieldCard ) => {
		const order = layout.fields[ card ];

		return (
			<ol
				id={ `${ id }-${ card }-fields` }
				className="merchboard-page-setup__fields"
				aria-label={ sprintf(
					/* translators: %s: card. */
					__( 'Fields of %s', 'merchboard' ),
					cardLabel( card )
				) }
			>
				{ order.map( ( field, index ) => (
					<li
						key={ field }
						className="merchboard-page-setup__item"
						draggable
						onDragStart={ ( event ) => {
							event.stopPropagation();
							event.dataTransfer.setData( 'text/plain', field );
							drag.current = { kind: 'field', card, field };
						} }
						onDragOver={ ( event ) => allowDrop( event, 'field' ) }
						onDrop={ ( event ) => dropField( event, card, index ) }
					>
						<span className="merchboard-page-setup__name">
							{ fieldLabel( field ) }
						</span>
						<span className="merchboard-page-setup__buttons">
							<Button
								size="sm"
								variant="ghost"
								disabled={ index === 0 }
								onClick={ () =>
									placeField( card, field, index - 1 )
								}
							>
								{ __( 'Move up', 'merchboard' ) }
							</Button>
							<Button
								size="sm"
								variant="ghost"
								disabled={ index === order.length - 1 }
								onClick={ () =>
									placeField( card, field, index + 1 )
								}
							>
								{ __( 'Move down', 'merchboard' ) }
							</Button>
						</span>
					</li>
				) ) }
			</ol>
		);
	};

	const cardItem = ( card: PageCard, zone: Zone, index: number ) => {
		const count = layout.cards[ zone ].length;

		return (
			<li
				key={ card }
				className="merchboard-page-setup__card"
				draggable
				onDragStart={ ( event ) => {
					event.dataTransfer.setData( 'text/plain', card );
					drag.current = { kind: 'card', card };
				} }
				onDragOver={ ( event ) => allowDrop( event, 'card' ) }
				onDrop={ ( event ) => dropCard( event, zone, index ) }
			>
				<div className="merchboard-page-setup__item">
					<span className="merchboard-page-setup__name">
						{ cardLabel( card ) }
					</span>
					<span className="merchboard-page-setup__buttons">
						<Button
							size="sm"
							variant="ghost"
							disabled={ index === 0 }
							onClick={ () => placeCard( card, zone, index - 1 ) }
						>
							{ __( 'Move up', 'merchboard' ) }
						</Button>
						<Button
							size="sm"
							variant="ghost"
							disabled={ index === count - 1 }
							onClick={ () => placeCard( card, zone, index + 1 ) }
						>
							{ __( 'Move down', 'merchboard' ) }
						</Button>
						<label
							className="merchboard-visually-hidden"
							htmlFor={ `${ id }-${ card }-zone` }
						>
							{ sprintf(
								/* translators: %s: card. */
								__( 'Place of %s', 'merchboard' ),
								cardLabel( card )
							) }
						</label>
						<select
							id={ `${ id }-${ card }-zone` }
							className="merchboard-input merchboard-page-setup__zone"
							value={ zone }
							onChange={ ( event ) =>
								placeCard(
									card,
									event.target.value as Zone,
									Number.MAX_SAFE_INTEGER
								)
							}
						>
							{ ZONES.map( ( name ) => (
								<option key={ name } value={ name }>
									{ zoneLabel( name ) }
								</option>
							) ) }
						</select>
						{ hasFields( card ) && (
							<Button
								size="sm"
								variant="secondary"
								aria-expanded={ open === card }
								aria-controls={ `${ id }-${ card }-fields` }
								onClick={ () =>
									setOpen( open === card ? null : card )
								}
							>
								{ __( 'Fields', 'merchboard' ) }
							</Button>
						) }
					</span>
				</div>
				{ card === 'designs' && (
					<label
						className="merchboard-alerts__check"
						htmlFor={ `${ id }-compact` }
					>
						<input
							id={ `${ id }-compact` }
							type="checkbox"
							checked={ layout.designsCompact }
							onChange={ ( event ) =>
								change(
									{
										...layout,
										designsCompact: event.target.checked,
									},
									event.target.checked
										? __(
												'The preview of Designs is hidden until the creator opens it.',
												'merchboard'
											)
										: __(
												'The preview of Designs is always shown.',
												'merchboard'
											)
								)
							}
						/>{ ' ' }
						{ __(
							'Compact: hide the preview until the creator opens it',
							'merchboard'
						) }
					</label>
				) }
				{ card === 'animations' && (
					<p className="merchboard-field__help">
						{ __(
							'"Images for text" always follows this card.',
							'merchboard'
						) }
					</p>
				) }
				{ hasFields( card ) && open === card && fields( card ) }
			</li>
		);
	};

	const zone = ( name: Zone ) => (
		<section
			className="merchboard-page-setup__zone-box"
			aria-labelledby={ `${ id }-${ name }` }
		>
			<h3 id={ `${ id }-${ name }` }>{ zoneLabel( name ) }</h3>
			<ol
				className="merchboard-page-setup__cards"
				onDragOver={ ( event ) => allowDrop( event, 'card' ) }
				onDrop={ ( event ) =>
					dropCard( event, name, Number.MAX_SAFE_INTEGER )
				}
			>
				{ layout.cards[ name ].map( ( card, index ) =>
					cardItem( card, name, index )
				) }
			</ol>
			{ layout.cards[ name ].length === 0 && (
				<p className="merchboard-field__help">
					{ __( 'Drag a card here.', 'merchboard' ) }
				</p>
			) }
		</section>
	);

	return (
		<div className="merchboard-page-setup">
			<Card title={ __( 'Creator page', 'merchboard' ) }>
				{ error !== null && <ApiErrorState error={ error } /> }
				{ zone( 'full' ) }
				<div className="merchboard-alerts__columns">
					{ zone( 'left' ) }
					{ zone( 'right' ) }
				</div>
				<p className="merchboard-field__help">
					{ __(
						'The designer is always last, in full width.',
						'merchboard'
					) }
				</p>
				<div className="merchboard-alerts__actions">
					<Button
						variant="secondary"
						onClick={ reset }
						isBusy={ busy }
					>
						{ __( 'Reset to default', 'merchboard' ) }
					</Button>
				</div>
				<p
					className="merchboard-visually-hidden"
					role="status"
					aria-live="polite"
				>
					{ announcement }
				</p>
			</Card>
			<ToastStack toasts={ toasts } onDismiss={ dismiss } />
		</div>
	);
}

/**
 * The tab's content.
 */
export function PageSetupAdmin() {
	const { state, retry } = useApi( getPageLayout );

	if ( state.status === 'loading' ) {
		return (
			<Skeleton
				label={ __( 'Loading the creator page setup…', 'merchboard' ) }
				lines={ 4 }
			/>
		);
	}

	if ( state.status === 'error' ) {
		return <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	return <PageSetupEditor initial={ state.data } />;
}

const root = document.getElementById( 'merchboard-page-setup-root' );

if ( root !== null ) {
	createRoot( root ).render( <PageSetupAdmin /> );
}
