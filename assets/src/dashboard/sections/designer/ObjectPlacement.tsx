/**
 * Placing and turning the selected element without dragging (phase 60):
 * buttons that put it in the middle or against an edge of the print file,
 * and number fields, e.g. its angle or a text's outline width. A field typed
 * or stepped in is one undo step.
 */
import { useId } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Button } from '../../components/Button';
import type { Placement } from './design-model';
import { useLiveField } from './live-field';

/**
 * A number field whose changes are one undo step (step 60.2).
 *
 * @param props          Props.
 * @param props.label    What the number is.
 * @param props.value    The number.
 * @param props.min      Smallest.
 * @param props.max      Largest.
 * @param props.step     Step of the arrows.
 * @param props.disabled Whether it cannot change.
 * @param props.onBegin  A change that goes on starts.
 * @param props.onEnd    It ends.
 * @param props.onChange A new number, within min and max; `live` while it goes on.
 */
export function NumberField( props: {
	readonly label: string;
	readonly value: number;
	readonly min: number;
	readonly max: number;
	readonly step?: number;
	readonly disabled?: boolean;
	readonly onBegin: () => void;
	readonly onEnd: () => void;
	readonly onChange: ( value: number, live: boolean ) => void;
} ) {
	const id = useId();
	const field = useLiveField< HTMLInputElement, number >(
		props.onBegin,
		props.onEnd,
		( value ) => props.onChange( value, true )
	);

	return (
		<div className="merchboard-product-designer__option">
			<label htmlFor={ id }>{ props.label }</label>
			<input
				id={ id }
				ref={ field.ref }
				className="merchboard-input merchboard-product-designer__number"
				type="number"
				min={ props.min }
				max={ props.max }
				step={ props.step ?? 1 }
				disabled={ props.disabled === true }
				value={ field.draft ?? Math.round( props.value * 10 ) / 10 }
				onChange={ ( event ) => {
					// A field emptied or half typed ("-") is no number yet.
					if ( event.target.value.trim() === '' ) {
						return;
					}

					const value = Number( event.target.value );

					if ( Number.isFinite( value ) ) {
						field.change(
							Math.min( props.max, Math.max( props.min, value ) )
						);
					}
				} }
			/>
		</div>
	);
}

/**
 * Buttons that put the element in the middle or against an edge of the
 * print file (step 60.3).
 *
 * @param props          Props.
 * @param props.disabled Whether it cannot move (locked).
 * @param props.sideways Whether it only moves sideways (a locked clipart).
 * @param props.onPlace  Places it.
 */
export function PlacementButtons( props: {
	readonly disabled: boolean;
	readonly sideways: boolean;
	readonly onPlace: ( where: Placement ) => void;
} ) {
	const buttons: readonly {
		readonly where: Placement;
		readonly label: string;
		readonly down: boolean;
	}[] = [
		{
			where: 'centre-x',
			label: __( 'Centre horizontally', 'merchboard' ),
			down: false,
		},
		{
			where: 'centre-y',
			label: __( 'Centre vertically', 'merchboard' ),
			down: true,
		},
		{ where: 'left', label: __( 'Left', 'merchboard' ), down: false },
		{ where: 'right', label: __( 'Right', 'merchboard' ), down: false },
		{ where: 'top', label: __( 'Top', 'merchboard' ), down: true },
		{ where: 'bottom', label: __( 'Bottom', 'merchboard' ), down: true },
	];

	return (
		<div
			className="merchboard-product-designer__placement"
			role="group"
			aria-label={ __( 'Placement', 'merchboard' ) }
		>
			{ buttons.map( ( button ) => (
				<Button
					key={ button.where }
					variant="secondary"
					size="sm"
					disabled={
						props.disabled || ( props.sideways && button.down )
					}
					onClick={ () => props.onPlace( button.where ) }
				>
					{ button.label }
				</Button>
			) ) }
		</div>
	);
}
