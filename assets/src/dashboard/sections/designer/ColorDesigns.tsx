/**
 * Colour designs in the editor (ADR-096): which design each colour uses, the
 * choice of a colour's design, and how two designs are merged when a colour
 * joins another design.
 */
import { __, sprintf } from '@wordpress/i18n';

import { Button } from '../../components/Button';
import { Dialog } from '../../components/Dialog';
import { designNumber, designsOf, partOf, SHARED } from './design-model';
import type { DesignDocument, MergeMode } from './design-model';

/** The choice "+ New design" in a colour's design list. */
export const NEW_DESIGN = 'new';

/**
 * The name of a design: "Shared design" or "Design 2" ….
 *
 * @param design The document.
 * @param id     A colour design's id, or SHARED.
 */
export function designName( design: DesignDocument, id: string ): string {
	return id === SHARED
		? __( 'Shared design', 'merchboard' )
		: sprintf(
				/* translators: %d: number of the design (2 or more). */
				__( 'Design %d', 'merchboard' ),
				designNumber( design, id )
			);
}

export interface ColorDesignPickerProps {
	readonly design: DesignDocument;
	readonly color: string;
	readonly colorName: string;
	/** Whether another colour design can be made. */
	readonly canAdd: boolean;
	/** The colour's new choice: a design's id, SHARED or NEW_DESIGN. */
	readonly onChoose: ( choice: string ) => void;
}

/**
 * The design a colour uses, as a list to choose from.
 *
 * @param props Props.
 */
export function ColorDesignPicker( props: ColorDesignPickerProps ) {
	const current = partOf( props.design, props.color );

	return (
		<select
			className="merchboard-input merchboard-product-designer__design-choice"
			aria-label={ sprintf(
				/* translators: %s: colour name. */
				__( 'Design of %s', 'merchboard' ),
				props.colorName
			) }
			value={ current }
			onChange={ ( event ) => props.onChoose( event.target.value ) }
		>
			{ [
				SHARED,
				...designsOf( props.design ).map( ( item ) => item.id ),
			].map( ( id ) => (
				<option key={ id } value={ id }>
					{ designName( props.design, id ) }
				</option>
			) ) }
			{ props.canAdd ? (
				<option value={ NEW_DESIGN }>
					{ __( '+ New design', 'merchboard' ) }
				</option>
			) : null }
		</select>
	);
}

export interface MergeDesignsProps {
	readonly colorName: string;
	/** The design the colour joins. */
	readonly target: string;
	readonly design: DesignDocument;
	readonly onMerge: ( mode: MergeMode ) => void;
	readonly onClose: () => void;
}

/**
 * How a colour's design joins the design it moves to.
 *
 * @param props Props.
 */
export function MergeDesigns( props: MergeDesignsProps ) {
	const targetName = designName( props.design, props.target );

	return (
		<Dialog
			title={ sprintf(
				/* translators: 1: colour name, 2: design name. */
				__( 'Move %1$s to %2$s', 'merchboard' ),
				props.colorName,
				targetName
			) }
			closeLabel={ __( 'Cancel', 'merchboard' ) }
			onClose={ props.onClose }
			actions={
				<>
					<Button onClick={ () => props.onMerge( 'target' ) }>
						{ sprintf(
							/* translators: %s: design name. */
							__( 'Use %s', 'merchboard' ),
							targetName
						) }
					</Button>
					<Button
						variant="secondary"
						onClick={ () => props.onMerge( 'mine' ) }
					>
						{ sprintf(
							/* translators: %s: colour name. */
							__( 'Use the design of %s', 'merchboard' ),
							props.colorName
						) }
					</Button>
					<Button
						variant="secondary"
						onClick={ () => props.onMerge( 'both' ) }
					>
						{ __( 'Put them together', 'merchboard' ) }
					</Button>
				</>
			}
		>
			<p>
				{ sprintf(
					/* translators: 1: colour name, 2: design name. */
					__(
						'%1$s has a design of its own. How should it join %2$s?',
						'merchboard'
					),
					props.colorName,
					targetName
				) }
			</p>
			<ul>
				<li>
					{ sprintf(
						/* translators: 1: design name, 2: colour name. */
						__(
							'Use %1$s: %2$s gets that design, and its own is kept only if other colours use it.',
							'merchboard'
						),
						targetName,
						props.colorName
					) }
				</li>
				<li>
					{ sprintf(
						/* translators: 1: colour name, 2: design name. */
						__(
							'Use the design of %1$s: it replaces %2$s for every colour that uses it.',
							'merchboard'
						),
						props.colorName,
						targetName
					) }
				</li>
				<li>
					{ sprintf(
						/* translators: 1: colour name, 2: design name. */
						__(
							'Put them together: the layers of %1$s are put on top of %2$s.',
							'merchboard'
						),
						props.colorName,
						targetName
					) }
				</li>
			</ul>
			<p>{ __( 'You can undo this.', 'merchboard' ) }</p>
		</Dialog>
	);
}
