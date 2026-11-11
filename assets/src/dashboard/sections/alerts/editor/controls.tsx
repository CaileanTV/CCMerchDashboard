/**
 * Small controls of the side panel (alert designer 2.0, ADR-088): a number
 * with its unit, a colour with its opacity, a row of choices and a switch.
 * Each keeps a label for a screen reader.
 */
import { useId } from '@wordpress/element';
import type { ReactNode } from 'react';

import { Field } from '../../../components/Field';

/**
 * A whole number with its unit; an empty field keeps the last value.
 *
 * @param props          Props.
 * @param props.label    Label.
 * @param props.value    Value.
 * @param props.min      Least.
 * @param props.max      Most.
 * @param props.unit     Unit shown after the number.
 * @param props.step     Step.
 * @param props.help     Help text.
 * @param props.onChange Called with the new value.
 */
export function NumberControl( {
	label,
	value,
	min,
	max,
	unit = '',
	step = 1,
	help,
	onChange,
}: {
	readonly label: string;
	readonly value: number;
	readonly min: number;
	readonly max: number;
	readonly unit?: string;
	readonly step?: number;
	readonly help?: string;
	readonly onChange: ( value: number ) => void;
} ) {
	return (
		<Field label={ label } { ...( help === undefined ? {} : { help } ) }>
			{ ( control ) => (
				<span className="merchboard-alert-control__number">
					<input
						{ ...control }
						type="number"
						className="merchboard-input"
						inputMode="decimal"
						min={ min }
						max={ max }
						step={ step }
						value={ Number.isFinite( value ) ? value : '' }
						onChange={ ( event ) => {
							const next = event.target.valueAsNumber;

							if ( Number.isFinite( next ) ) {
								onChange(
									Math.min( max, Math.max( min, next ) )
								);
							}
						} }
					/>
					{ unit !== '' && (
						<span
							className="merchboard-alert-control__unit"
							aria-hidden="true"
						>
							{ unit }
						</span>
					) }
				</span>
			) }
		</Field>
	);
}

/**
 * A colour with an optional opacity in percent.
 *
 * @param props                 Props.
 * @param props.label           Label.
 * @param props.value           #rrggbb.
 * @param props.onChange        Called with the colour.
 * @param props.opacity         Opacity in percent; undefined for none.
 * @param props.onOpacityChange Called with the opacity.
 */
export function ColorControl( {
	label,
	value,
	onChange,
	opacity,
	onOpacityChange,
}: {
	readonly label: string;
	readonly value: string;
	readonly onChange: ( value: string ) => void;
	readonly opacity?: number;
	readonly onOpacityChange?: ( value: number ) => void;
} ) {
	const id = useId();

	return (
		<Field label={ label }>
			{ ( control ) => (
				<span className="merchboard-alert-control__color">
					<input
						{ ...control }
						type="color"
						className="merchboard-input merchboard-alerts__color"
						value={ value }
						onChange={ ( event ) => onChange( event.target.value ) }
					/>
					{ opacity !== undefined && onOpacityChange && (
						<span className="merchboard-alert-control__number">
							<label
								className="merchboard-visually-hidden"
								htmlFor={ `${ id }-opacity` }
							>
								{ `${ label }: %` }
							</label>
							<input
								id={ `${ id }-opacity` }
								type="number"
								className="merchboard-input"
								min={ 0 }
								max={ 100 }
								value={ opacity }
								onChange={ ( event ) => {
									const next = event.target.valueAsNumber;

									if ( Number.isFinite( next ) ) {
										onOpacityChange(
											Math.min(
												100,
												Math.max(
													0,
													Math.round( next )
												)
											)
										);
									}
								} }
							/>
							<span
								className="merchboard-alert-control__unit"
								aria-hidden="true"
							>
								%
							</span>
						</span>
					) }
				</span>
			) }
		</Field>
	);
}

/**
 * A row of choices, one pressed.
 *
 * @param props          Props.
 * @param props.label    Label of the group.
 * @param props.value    The chosen value.
 * @param props.choices  Value → label.
 * @param props.onChange Called with the chosen value.
 */
export function Segmented< T extends string >( {
	label,
	value,
	choices,
	onChange,
}: {
	readonly label: string;
	readonly value: T;
	readonly choices: ReadonlyArray< {
		readonly value: T;
		readonly label: ReactNode;
		readonly title?: string;
		/** Not available now (shown, but cannot be chosen). */
		readonly disabled?: boolean;
	} >;
	readonly onChange: ( value: T ) => void;
} ) {
	const id = useId();

	return (
		<div className="merchboard-field">
			<span id={ id } className="merchboard-field__label">
				{ label }
			</span>
			<div
				className="merchboard-alert-control__segmented"
				role="radiogroup"
				aria-labelledby={ id }
			>
				{ choices.map( ( choice ) => (
					<button
						key={ choice.value }
						type="button"
						role="radio"
						aria-checked={ choice.value === value }
						title={ choice.title }
						aria-label={ choice.title }
						disabled={ choice.disabled === true }
						onClick={ () => onChange( choice.value ) }
					>
						{ choice.label }
					</button>
				) ) }
			</div>
		</div>
	);
}

/**
 * A switch (a checkbox with the look of a switch).
 *
 * @param props          Props.
 * @param props.label    Label.
 * @param props.checked  Whether it is on.
 * @param props.onChange Called with the new state.
 * @param props.help     Help text.
 */
export function Switch( {
	label,
	checked,
	onChange,
	help,
}: {
	readonly label: string;
	readonly checked: boolean;
	readonly onChange: ( checked: boolean ) => void;
	readonly help?: string;
} ) {
	const id = useId();

	return (
		<div className="merchboard-alert-control__switch">
			<input
				id={ id }
				type="checkbox"
				role="switch"
				checked={ checked }
				aria-describedby={
					help === undefined ? undefined : `${ id }-help`
				}
				onChange={ ( event ) => onChange( event.target.checked ) }
			/>
			<label htmlFor={ id }>{ label }</label>
			{ help !== undefined && (
				<p id={ `${ id }-help` } className="merchboard-field__help">
					{ help }
				</p>
			) }
		</div>
	);
}
