/**
 * The "Background" tab of the designer on a base product that offers a
 * background colour (ADR-095): standard colours, Hex, a colour picker, RGB
 * and CMYK, for the side shown or all sides. Built on the owner's AOP
 * background module, natively in the designer.
 */
import { useEffect, useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { Button } from '../../components/Button';
import {
	cmykToRgb,
	hexToRgb,
	normHex,
	rgbToCmyk,
	rgbToHex,
} from './color-convert';

/**
 * The standard colours: name and colour.
 */
function standardColors(): readonly ( readonly [ string, string ] )[] {
	return [
		[ __( 'White', 'merchboard' ), '#ffffff' ],
		[ __( 'Black', 'merchboard' ), '#000000' ],
		[ __( 'Navy', 'merchboard' ), '#1f2a44' ],
		[ __( 'Royal blue', 'merchboard' ), '#1e50a2' ],
		[ __( 'Sky blue', 'merchboard' ), '#7fb2e5' ],
		[ __( 'Petrol', 'merchboard' ), '#05707a' ],
		[ __( 'Green', 'merchboard' ), '#2e7d32' ],
		[ __( 'Mint', 'merchboard' ), '#a8e6cf' ],
		[ __( 'Yellow', 'merchboard' ), '#ffd100' ],
		[ __( 'Orange', 'merchboard' ), '#f28c28' ],
		[ __( 'Red', 'merchboard' ), '#c8102e' ],
		[ __( 'Burgundy', 'merchboard' ), '#6d1a36' ],
		[ __( 'Pink', 'merchboard' ), '#f4a6c1' ],
		[ __( 'Purple', 'merchboard' ), '#6a3d9a' ],
		[ __( 'Grey', 'merchboard' ), '#9e9e9e' ],
		[ __( 'Dark grey', 'merchboard' ), '#3c3c3c' ],
	];
}

const START = '#05707a';

/** What the fields show. */
interface Fields {
	readonly hex: string;
	readonly rgb: readonly string[];
	readonly cmyk: readonly string[];
}

/**
 * The fields for a colour; the field being typed in keeps what was typed.
 *
 * @param hex  Colour.
 * @param keep The fields as they are.
 * @param from The field typed in.
 */
function fieldsOf(
	hex: string,
	keep: Fields | null = null,
	from: 'hex' | 'rgb' | 'cmyk' | null = null
): Fields {
	const rgb = hexToRgb( hex ) ?? [ 0, 0, 0 ];
	const cmyk = rgbToCmyk( ...rgb );

	return {
		hex: from === 'hex' && keep !== null ? keep.hex : hex,
		rgb: from === 'rgb' && keep !== null ? keep.rgb : rgb.map( String ),
		cmyk: from === 'cmyk' && keep !== null ? keep.cmyk : cmyk.map( String ),
	};
}

export interface BackgroundPanelProps {
	/** The colour of the side shown, or null. */
	readonly current: string | null;
	/** The name of the side shown. */
	readonly side: string;
	/** Whether the side shown can have a background (an embroidered side cannot). */
	readonly allowed: boolean;
	/** Whether other sides can have one too. */
	readonly others: boolean;
	readonly onApply: ( hex: string, allSides: boolean ) => void;
	readonly onRemove: ( allSides: boolean ) => void;
}

/**
 * The panel.
 *
 * @param props Props.
 */
export function BackgroundPanel( props: BackgroundPanelProps ) {
	const id = useId();
	const [ value, setValue ] = useState( props.current ?? START );
	const [ fields, setFields ] = useState< Fields >( () =>
		fieldsOf( props.current ?? START )
	);
	const [ all, setAll ] = useState( true );
	const [ invalid, setInvalid ] = useState( false );
	const current = props.current;

	// Another side, or a change from undo: the fields show that side's colour.
	useEffect( () => {
		if ( current !== null ) {
			setValue( current );
			setFields( fieldsOf( current ) );
		}
	}, [ current ] );

	const set = ( hex: string, from: 'hex' | 'rgb' | 'cmyk' | null ) => {
		setValue( hex );
		setFields( ( keep ) => fieldsOf( hex, keep, from ) );
		setInvalid( false );
	};

	const allSides = props.others && all;

	if ( ! props.allowed ) {
		return (
			<p className="merchboard-product-designer__hint">
				{ __(
					'Embroidered sides have no background. Choose a printed side.',
					'merchboard'
				) }
			</p>
		);
	}

	return (
		<div className="merchboard-product-designer__stack merchboard-product-designer__background">
			<p className="merchboard-product-designer__hint">
				{ __(
					'The colour covers the whole print area and is always behind the design.',
					'merchboard'
				) }
			</p>
			<p className="merchboard-product-designer__background-current">
				<span
					className="merchboard-product-designer__swatch merchboard-product-designer__swatch--small"
					style={
						props.current === null
							? undefined
							: { backgroundColor: props.current }
					}
				/>
				{ props.current === null
					? sprintf(
							/* translators: %s: side name. */
							__( 'No background on %s', 'merchboard' ),
							props.side
						)
					: sprintf(
							/* translators: 1: side name, 2: colour, e.g. #05707a. */
							__( 'Background on %1$s: %2$s', 'merchboard' ),
							props.side,
							props.current
						) }
			</p>
			<div
				className="merchboard-product-designer__background-swatches"
				role="group"
				aria-label={ __( 'Standard colours', 'merchboard' ) }
			>
				{ standardColors().map( ( [ name, hex ] ) => (
					<button
						key={ hex }
						type="button"
						className="merchboard-product-designer__swatch"
						style={ { backgroundColor: hex } }
						title={ `${ name } ${ hex }` }
						aria-label={ name }
						aria-pressed={ props.current === hex }
						onClick={ () => {
							set( hex, null );
							props.onApply( hex, allSides );
						} }
					/>
				) ) }
			</div>
			<div className="merchboard-product-designer__background-row">
				<label htmlFor={ `${ id }-hex` }>Hex</label>
				<input
					id={ `${ id }-hex` }
					type="text"
					maxLength={ 7 }
					value={ fields.hex }
					onChange={ ( event ) => {
						const typed = event.target.value;
						const hex = normHex( typed );

						setFields( ( keep ) => ( { ...keep, hex: typed } ) );

						if ( hex !== null ) {
							set( hex, 'hex' );
						}
					} }
				/>
				<input
					type="color"
					aria-label={ __( 'Colour picker', 'merchboard' ) }
					value={ value }
					onChange={ ( event ) => set( event.target.value, null ) }
				/>
			</div>
			<div className="merchboard-product-designer__background-row">
				<span>RGB</span>
				{ [ 'R', 'G', 'B' ].map( ( letter, index ) => (
					<input
						key={ letter }
						type="number"
						min={ 0 }
						max={ 255 }
						step={ 1 }
						aria-label={ letter }
						title={ letter }
						value={ fields.rgb[ index ] ?? '' }
						onChange={ ( event ) => {
							const rgb = fields.rgb.map( ( part, at ) =>
								at === index ? event.target.value : part
							);

							setFields( ( keep ) => ( { ...keep, rgb } ) );
							set(
								rgbToHex(
									rgb[ 0 ] ?? 0,
									rgb[ 1 ] ?? 0,
									rgb[ 2 ] ?? 0
								),
								'rgb'
							);
						} }
					/>
				) ) }
			</div>
			<div className="merchboard-product-designer__background-row">
				<span>CMYK</span>
				{ [ 'C', 'M', 'Y', 'K' ].map( ( letter, index ) => (
					<input
						key={ letter }
						type="number"
						min={ 0 }
						max={ 100 }
						step={ 1 }
						aria-label={ letter }
						title={ letter }
						value={ fields.cmyk[ index ] ?? '' }
						onChange={ ( event ) => {
							const cmyk = fields.cmyk.map( ( part, at ) =>
								at === index ? event.target.value : part
							);

							setFields( ( keep ) => ( { ...keep, cmyk } ) );
							set(
								rgbToHex(
									...cmykToRgb(
										cmyk[ 0 ] ?? 0,
										cmyk[ 1 ] ?? 0,
										cmyk[ 2 ] ?? 0,
										cmyk[ 3 ] ?? 0
									)
								),
								'cmyk'
							);
						} }
					/>
				) ) }
			</div>
			<p className="merchboard-product-designer__hint">
				{ __(
					'CMYK is converted to RGB, so the printed colour can differ a little.',
					'merchboard'
				) }
			</p>
			{ props.others ? (
				<label
					className="merchboard-product-designer__background-all"
					htmlFor={ `${ id }-all` }
				>
					<input
						id={ `${ id }-all` }
						type="checkbox"
						checked={ all }
						onChange={ ( event ) => setAll( event.target.checked ) }
					/>
					{ __( 'All sides', 'merchboard' ) }
				</label>
			) : null }
			<div className="merchboard-product-designer__background-actions">
				<Button
					onClick={ () => {
						const hex = normHex( fields.hex );

						if ( hex === null ) {
							setInvalid( true );

							return;
						}

						set( hex, null );
						props.onApply( hex, allSides );
					} }
				>
					{ __( 'Use the colour', 'merchboard' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ () => props.onRemove( allSides ) }
				>
					{ __( 'Remove background', 'merchboard' ) }
				</Button>
			</div>
			{ invalid ? (
				<p
					className="merchboard-product-designer__background-error"
					role="alert"
				>
					{ __( 'Invalid colour', 'merchboard' ) }
				</p>
			) : null }
		</div>
	);
}
