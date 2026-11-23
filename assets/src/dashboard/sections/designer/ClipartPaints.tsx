/**
 * The paints of a clipart's parts (phase 56, step 56.11, ADR-121): per part
 * one colour or a gradient of two with an angle, from the clipart's palette
 * when it has one. Its stroke never changes, so it is not offered. Dragging
 * in a colour field or changing the angle is one undo step.
 */
import { useEffect, useId, useRef } from '@wordpress/element';
import type { RefObject } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import type {
	ClipartArt,
	ClipartPaint,
	ClipartPart,
	GradientPaint,
} from './clipart-model';

export interface ClipartPaintsProps {
	readonly art: ClipartArt;
	/** The colours to choose between; null for any colour. */
	readonly palette: readonly string[] | null;
	/** The paints, one per part. */
	readonly paints: readonly ClipartPaint[];
	/** Whether a gradient may be chosen (not on embroidery). */
	readonly gradients: boolean;
	/** A swatch's name (a thread's code and name); its colour without. */
	readonly swatchName?: ( ( hex: string ) => string ) | undefined;
	/** New paints; `live` while a change goes on (between onBegin and onEnd). */
	readonly onChange: (
		paints: readonly ClipartPaint[],
		live?: boolean
	) => void;
	readonly onBegin: () => void;
	readonly onEnd: () => void;
}

/**
 * A part's name.
 *
 * @param part  The part.
 * @param index Its index.
 */
export function partName( part: ClipartPart, index: number ): string {
	return part.label !== ''
		? part.label
		: sprintf(
				/* translators: %d: the number of a part of a clipart. */ __(
					'Part %d',
					'merchboard'
				),
				index + 1
			);
}

/**
 * The part's paint as one colour.
 *
 * @param paint The paint.
 * @param part  The part.
 */
export function asSolid(
	paint: ClipartPaint,
	part: ClipartPart
): ClipartPaint {
	if ( paint.type === 'solid' ) {
		return paint;
	}

	return {
		type: 'solid',
		color: part.paint.type === 'solid' ? part.paint.color : paint.from,
	};
}

/**
 * The part's paint as a gradient: the file's to start with.
 *
 * @param paint The paint.
 * @param part  The part.
 */
export function asGradient(
	paint: ClipartPaint,
	part: ClipartPart
): GradientPaint {
	return paint.type === 'gradient' ? paint : part.gradient;
}

/**
 * One undo step for a field that changes while it is used (a colour being
 * dragged, an angle being typed): it starts at the first change, not at
 * focus, which some browsers never give a clicked field, and ends when the
 * field is left, its value is committed or it goes away.
 *
 * @param onBegin A change that goes on starts.
 * @param onEnd   It ends.
 * @return The field's ref and what to call on each live change.
 */
function useLiveStep< T extends HTMLInputElement >(
	onBegin: () => void,
	onEnd: () => void
): { readonly ref: RefObject< T | null >; readonly changing: () => void } {
	const ref = useRef< T | null >( null );
	const open = useRef( false );
	const calls = useRef( { onBegin, onEnd } );
	calls.current = { onBegin, onEnd };

	useEffect( () => {
		const field = ref.current;
		const end = (): void => {
			if ( open.current ) {
				open.current = false;
				calls.current.onEnd();
			}
		};

		// The native change event: the picker closed or the value was committed.
		field?.addEventListener( 'change', end );
		field?.addEventListener( 'blur', end );

		return () => {
			field?.removeEventListener( 'change', end );
			field?.removeEventListener( 'blur', end );
			end();
		};
	}, [] );

	return {
		ref,
		changing: () => {
			if ( ! open.current ) {
				open.current = true;
				calls.current.onBegin();
			}
		},
	};
}

/**
 * One colour: the palette's swatches, or a free colour field.
 *
 * @param props            Props.
 * @param props.label      What the colour is.
 * @param props.value      `#rrggbb`.
 * @param props.palette    The colours to choose between; null for any.
 * @param props.swatchName A swatch's name.
 * @param props.onChange   A new colour; `live` while dragging.
 * @param props.onBegin    Dragging starts.
 * @param props.onEnd      It ends.
 */
function ColorChoice( props: {
	readonly label: string;
	readonly value: string;
	readonly palette: readonly string[] | null;
	readonly swatchName?: ( ( hex: string ) => string ) | undefined;
	readonly onChange: ( color: string, live?: boolean ) => void;
	readonly onBegin: () => void;
	readonly onEnd: () => void;
} ) {
	const id = useId();
	const step = useLiveStep< HTMLInputElement >( props.onBegin, props.onEnd );

	if ( props.palette !== null ) {
		return (
			<div
				className="merchboard-product-designer__option"
				role="group"
				aria-label={ props.label }
			>
				<span>{ props.label }</span>
				<span className="merchboard-product-designer__threads">
					{ props.palette.map( ( color ) => (
						<button
							key={ color }
							type="button"
							className="merchboard-product-designer__thread"
							style={ { backgroundColor: color } }
							title={ props.swatchName?.( color ) ?? color }
							aria-label={ props.swatchName?.( color ) ?? color }
							aria-pressed={ props.value === color }
							onClick={ () => props.onChange( color ) }
						/>
					) ) }
				</span>
			</div>
		);
	}

	return (
		<div className="merchboard-product-designer__option">
			<label htmlFor={ id }>{ props.label }</label>
			<input
				id={ id }
				type="color"
				ref={ step.ref }
				value={ props.value }
				onChange={ ( event ) => {
					step.changing();
					props.onChange( event.target.value.toLowerCase(), true );
				} }
			/>
		</div>
	);
}

/**
 * The paint of one part.
 *
 * @param props            Props.
 * @param props.part       The part.
 * @param props.index      Its index.
 * @param props.paint      Its paint.
 * @param props.palette    The colours to choose between; null for any.
 * @param props.swatchName A swatch's name.
 * @param props.gradients  Whether a gradient may be chosen.
 * @param props.onChange   A new paint; `live` while a change goes on.
 * @param props.onBegin    A change that goes on starts.
 * @param props.onEnd      It ends.
 */
function PartPaint( props: {
	readonly part: ClipartPart;
	readonly index: number;
	readonly paint: ClipartPaint;
	readonly palette: readonly string[] | null;
	readonly swatchName?: ( ( hex: string ) => string ) | undefined;
	readonly gradients: boolean;
	readonly onChange: ( paint: ClipartPaint, live?: boolean ) => void;
	readonly onBegin: () => void;
	readonly onEnd: () => void;
} ) {
	const { part, paint, palette } = props;
	const kindId = useId();
	const angleId = useId();
	const angleStep = useLiveStep< HTMLInputElement >(
		props.onBegin,
		props.onEnd
	);
	const name = partName( part, props.index );
	const shown = props.gradients ? paint : asSolid( paint, part );

	return (
		<fieldset className="merchboard-product-designer__part">
			<legend>{ name }</legend>
			{ props.gradients ? (
				<div className="merchboard-product-designer__option">
					<label htmlFor={ kindId }>
						{ __( 'Paint', 'merchboard' ) }
					</label>
					<select
						id={ kindId }
						value={ shown.type }
						onChange={ ( event ) =>
							props.onChange(
								event.target.value === 'gradient'
									? asGradient( paint, part )
									: asSolid( paint, part )
							)
						}
					>
						<option value="solid">
							{ __( 'One colour', 'merchboard' ) }
						</option>
						<option value="gradient">
							{ __( 'Gradient', 'merchboard' ) }
						</option>
					</select>
				</div>
			) : null }
			{ shown.type === 'solid' ? (
				<ColorChoice
					label={ __( 'Colour', 'merchboard' ) }
					value={ shown.color }
					palette={ palette }
					swatchName={ props.swatchName }
					onChange={ ( color, live ) =>
						props.onChange( { type: 'solid', color }, live )
					}
					onBegin={ props.onBegin }
					onEnd={ props.onEnd }
				/>
			) : (
				<>
					<ColorChoice
						label={ __( 'From', 'merchboard' ) }
						value={ shown.from }
						palette={ palette }
						swatchName={ props.swatchName }
						onChange={ ( from, live ) =>
							props.onChange( { ...shown, from }, live )
						}
						onBegin={ props.onBegin }
						onEnd={ props.onEnd }
					/>
					<ColorChoice
						label={ __( 'To', 'merchboard' ) }
						value={ shown.to }
						palette={ palette }
						swatchName={ props.swatchName }
						onChange={ ( to, live ) =>
							props.onChange( { ...shown, to }, live )
						}
						onBegin={ props.onBegin }
						onEnd={ props.onEnd }
					/>
					<div className="merchboard-product-designer__option">
						<label htmlFor={ angleId }>
							{ __( 'Angle (°)', 'merchboard' ) }
						</label>
						<input
							id={ angleId }
							type="number"
							min={ 0 }
							max={ 359 }
							step={ 1 }
							ref={ angleStep.ref }
							value={ shown.angle }
							onChange={ ( event ) => {
								const angle = Math.round(
									Number( event.target.value )
								);

								if ( Number.isFinite( angle ) ) {
									angleStep.changing();
									props.onChange(
										{
											...shown,
											angle:
												( ( angle % 360 ) + 360 ) % 360,
										},
										true
									);
								}
							} }
						/>
					</div>
				</>
			) }
		</fieldset>
	);
}

/**
 * The paints of a clipart's parts.
 *
 * @param props Props.
 */
export function ClipartPaints( props: ClipartPaintsProps ) {
	const { art, paints } = props;

	return (
		<div className="merchboard-product-designer__parts">
			{ art.parts.map( ( part, index ) => (
				<PartPaint
					key={ index }
					part={ part }
					index={ index }
					paint={ paints[ index ] ?? part.paint }
					palette={ props.palette }
					swatchName={ props.swatchName }
					gradients={ props.gradients }
					onChange={ ( paint, live ) =>
						props.onChange(
							art.parts.map( ( other, at ) =>
								at === index
									? paint
									: ( paints[ at ] ?? other.paint )
							),
							live
						)
					}
					onBegin={ props.onBegin }
					onEnd={ props.onEnd }
				/>
			) ) }
		</div>
	);
}
