import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import '../../../overlay/animations.css';
import {
	EFFECTS,
	IN_ANIMATIONS,
	OUT_ANIMATIONS,
	SPEEDS,
	leaveText,
	renderText,
	resetText,
} from '../../../overlay/animations';
import type {
	Effect,
	InAnimation,
	OutAnimation,
	Speed,
	TextAnimation,
} from '../../../overlay/animations';
import '../../../overlay/textfill.css';
import {
	FILL_MOTIONS,
	FILL_PRESETS,
	FILL_TYPES,
	applyFill,
	clearFill,
	fillLook,
} from '../../../overlay/textfill';
import type {
	FillLook,
	FillMotion,
	FillPreset,
	FillType,
	TextFill,
} from '../../../overlay/textfill';
import { Button } from '../../components/Button';
import { Field } from '../../components/Field';
import type { AlertsImages } from '../../types/alerts';

/** In-animations and effects that use the colour. */
const USES_COLOR: ReadonlySet< string > = new Set( [
	'shadow-paint',
	'neon',
	'glow-pulse',
	'glitch',
	'dancing-shadow',
	'depth-pulse',
] );

/**
 * The name of an in-animation.
 *
 * @param value In-animation.
 */
export function inLabel( value: InAnimation ): string {
	switch ( value ) {
		case 'slide-up':
			return __( 'Slide up', 'merchboard' );
		case 'pop':
			return __( 'Pop', 'merchboard' );
		case 'typewriter':
			return __( 'Typewriter', 'merchboard' );
		case 'split':
			return __( 'Split (from both sides)', 'merchboard' );
		case 'wave':
			return __( 'Wave (letter by letter)', 'merchboard' );
		case 'drop':
			return __( 'Drop (letter by letter)', 'merchboard' );
		case 'spin-3d':
			return __( '3D spin', 'merchboard' );
		case 'roll-3d':
			return __( '3D roll', 'merchboard' );
		case 'blur':
			return __( 'Blur in', 'merchboard' );
		case 'wipe':
			return __( 'Wipe', 'merchboard' );
		case 'shadow-paint':
			return __( 'Shadow paint', 'merchboard' );
		default:
			return __( 'Fade', 'merchboard' );
	}
}

/**
 * The name of an effect.
 *
 * @param value Effect.
 */
export function effectLabel( value: Effect ): string {
	switch ( value ) {
		case 'neon':
			return __( 'Neon glow', 'merchboard' );
		case 'glow-pulse':
			return __( 'Glow pulse', 'merchboard' );
		case 'heartbeat':
			return __( 'Heartbeat', 'merchboard' );
		case 'glitch':
			return __( 'Glitch', 'merchboard' );
		case 'dancing-shadow':
			return __( 'Dancing shadow', 'merchboard' );
		case 'melt':
			return __( 'Melting', 'merchboard' );
		case 'wave':
			return __( 'Wave', 'merchboard' );
		case 'float-3d':
			return __( '3D float', 'merchboard' );
		case 'depth-pulse':
			return __( 'Depth pulse', 'merchboard' );
		case 'breathe':
			return __( 'Breathe (needs a variable font)', 'merchboard' );
		default:
			return __( 'No effect', 'merchboard' );
	}
}

/**
 * The name of an out-animation.
 *
 * @param value Out-animation.
 */
export function outLabel( value: OutAnimation ): string {
	switch ( value ) {
		case 'slide-down':
			return __( 'Slide down', 'merchboard' );
		case 'pop':
			return __( 'Pop', 'merchboard' );
		case 'typewriter':
			return __( 'Typewriter (delete)', 'merchboard' );
		case 'split':
			return __( 'Split (to both sides)', 'merchboard' );
		case 'wave':
			return __( 'Wave (letter by letter)', 'merchboard' );
		case 'spin-3d':
			return __( '3D spin', 'merchboard' );
		case 'roll-3d':
			return __( '3D roll', 'merchboard' );
		case 'blur':
			return __( 'Blur out', 'merchboard' );
		case 'wipe':
			return __( 'Wipe', 'merchboard' );
		default:
			return __( 'Fade', 'merchboard' );
	}
}

function speedLabel( value: Speed ): string {
	switch ( value ) {
		case 'slow':
			return __( 'Slow', 'merchboard' );
		case 'fast':
			return __( 'Fast', 'merchboard' );
		default:
			return __( 'Normal', 'merchboard' );
	}
}

function fillTypeLabel( value: FillType ): string {
	switch ( value ) {
		case 'preset':
			return __( 'Gradient or pattern', 'merchboard' );
		case 'image':
			return __( 'Image', 'merchboard' );
		default:
			return __( 'Text colour (no fill)', 'merchboard' );
	}
}

/**
 * The name of a ready-made fill.
 *
 * @param value Preset.
 */
export function presetLabel( value: FillPreset ): string {
	switch ( value ) {
		case 'aurora':
			return __( 'Aurora', 'merchboard' );
		case 'sunset':
			return __( 'Sunset', 'merchboard' );
		case 'ocean':
			return __( 'Ocean', 'merchboard' );
		case 'fire':
			return __( 'Fire', 'merchboard' );
		case 'gold':
			return __( 'Gold', 'merchboard' );
		case 'candy':
			return __( 'Candy stripes', 'merchboard' );
		case 'matrix':
			return __( 'Matrix', 'merchboard' );
		default:
			return __( 'Rainbow', 'merchboard' );
	}
}

function motionLabel( value: FillMotion ): string {
	switch ( value ) {
		case 'pan':
			return __( 'Glides sideways', 'merchboard' );
		case 'fall':
			return __( 'Falls down', 'merchboard' );
		default:
			return __( 'Still', 'merchboard' );
	}
}

/** How long the sample shows the effect before it leaves (ms). */
const SAMPLE_SHOWN = 2500;

/** The pause before the sample starts again (ms). */
const SAMPLE_PAUSE = 600;

/**
 * A small sample that plays the chosen animation in a loop (in, effect, out),
 * so a choice is seen at once. It starts again on every change and can be
 * paused.
 *
 * @param props           Props.
 * @param props.animation The animation.
 * @param props.duration  In/out time (ms).
 * @param props.fill      The text fill; null for none.
 */
export function AnimationSample( {
	animation,
	duration,
	fill = null,
}: {
	readonly animation: TextAnimation;
	readonly duration: number;
	readonly fill?: FillLook | null;
} ) {
	const ref = useRef< HTMLSpanElement >( null );
	// With reduced motion the sample waits until it is played on request.
	const [ playing, setPlaying ] = useState(
		() =>
			typeof window.matchMedia !== 'function' ||
			! window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
	);
	const text = __( 'Thanks for the merch!', 'merchboard' );
	const choice = JSON.stringify( [ animation, fill ] );

	useEffect( () => {
		const element = ref.current;

		if ( element === null ) {
			return undefined;
		}

		let timer = 0;

		const play = () => {
			const times = renderText(
				element,
				text,
				animation,
				duration,
				true
			);

			applyFill( element, fill );

			timer = window.setTimeout( () => {
				leaveText( element );
				timer = window.setTimeout(
					play,
					times.outTotal + SAMPLE_PAUSE
				);
			}, times.inTotal + SAMPLE_SHOWN );
		};

		if ( playing ) {
			play();
		} else {
			resetText( element );
			element.textContent = text;
			applyFill( element, fill );
		}

		return () => {
			window.clearTimeout( timer );
			resetText( element );
			clearFill( element );
			element.textContent = text;
		};
		// The animation and the fill are compared by value (choice).
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ choice, duration, playing, text ] );

	return (
		<div className="merchboard-animation__sample">
			<div className="merchboard-animation__stage" aria-hidden="true">
				<span ref={ ref } className="merchboard-animation__text" />
			</div>
			<Button
				variant="secondary"
				aria-pressed={ ! playing }
				onClick={ () => setPlaying( ! playing ) }
			>
				{ playing
					? __( 'Pause example', 'merchboard' )
					: __( 'Play example', 'merchboard' ) }
			</Button>
		</div>
	);
}

interface Props {
	readonly legend: string;
	readonly value: TextAnimation;
	readonly onChange: ( value: TextAnimation ) => void;
	/** In/out time (ms) for the sample. */
	readonly duration: number;
	/** The text fill (step 25.6.10). */
	readonly fill: TextFill;
	readonly onFillChange: ( fill: TextFill ) => void;
	/** Images the text can be filled with; null when not available. */
	readonly images: AlertsImages | null;
}

/**
 * The animation of one text (step 25.6.9): in, effect while shown, out,
 * colour (when the choice uses one) and speed; and its fill (step 25.6.10):
 * a ready-made gradient or pattern or an image, still or moving.
 *
 * @param props              Props.
 * @param props.legend       The text's name.
 * @param props.value        The animation.
 * @param props.onChange     Called with the changed animation.
 * @param props.duration     In/out time (ms) for the sample.
 * @param props.fill         The text fill.
 * @param props.onFillChange Called with the changed fill.
 * @param props.images       Images the text can be filled with.
 */
export function AnimationFields( {
	legend,
	value,
	onChange,
	duration,
	fill,
	onFillChange,
	images,
}: Props ) {
	const set = ( change: Partial< TextAnimation > ) =>
		onChange( { ...value, ...change } );
	const setFill = ( change: Partial< TextFill > ) =>
		onFillChange( { ...fill, ...change } );
	const own = images?.own ?? [];
	const library = images?.library ?? [];
	const types = FILL_TYPES.filter(
		( type ) => type !== 'image' || images !== null
	);

	const select = < T extends string >(
		label: string,
		current: T,
		options: readonly T[],
		name: ( option: T ) => string,
		change: ( option: T ) => void
	) => (
		<Field label={ label }>
			{ ( control ) => (
				<select
					{ ...control }
					className="merchboard-input"
					value={ current }
					onChange={ ( event ) => {
						const chosen = options.find(
							( option ) => option === event.target.value
						);

						if ( chosen !== undefined ) {
							change( chosen );
						}
					} }
				>
					{ options.map( ( option ) => (
						<option key={ option } value={ option }>
							{ name( option ) }
						</option>
					) ) }
				</select>
			) }
		</Field>
	);

	return (
		<fieldset className="merchboard-animation">
			<legend className="merchboard-field__label">{ legend }</legend>
			<div className="merchboard-alerts__grid">
				{ select(
					__( 'In', 'merchboard' ),
					value.in,
					IN_ANIMATIONS,
					inLabel,
					( option ) => set( { in: option } )
				) }
				{ select(
					__( 'Effect while shown', 'merchboard' ),
					value.effect,
					EFFECTS,
					effectLabel,
					( option ) => set( { effect: option } )
				) }
				{ select(
					__( 'Out', 'merchboard' ),
					value.out,
					OUT_ANIMATIONS,
					outLabel,
					( option ) => set( { out: option } )
				) }
				{ select(
					__( 'Speed', 'merchboard' ),
					value.speed,
					SPEEDS,
					speedLabel,
					( option ) => set( { speed: option } )
				) }
				{ ( USES_COLOR.has( value.in ) ||
					USES_COLOR.has( value.effect ) ) && (
					<Field label={ __( 'Effect colour', 'merchboard' ) }>
						{ ( control ) => (
							<input
								{ ...control }
								type="color"
								className="merchboard-input merchboard-alerts__color"
								value={ value.color }
								onChange={ ( event ) =>
									set( { color: event.target.value } )
								}
							/>
						) }
					</Field>
				) }
			</div>
			<div className="merchboard-alerts__grid">
				{ select(
					__( 'Text fill', 'merchboard' ),
					fill.type,
					types,
					fillTypeLabel,
					( option ) => setFill( { type: option } )
				) }
				{ fill.type === 'preset' &&
					select(
						__( 'Gradient or pattern', 'merchboard' ),
						fill.preset,
						FILL_PRESETS,
						presetLabel,
						( option ) => setFill( { preset: option } )
					) }
				{ fill.type === 'image' && (
					<Field label={ __( 'Image', 'merchboard' ) }>
						{ ( control ) => (
							<select
								{ ...control }
								className="merchboard-input"
								value={ String( fill.image ) }
								onChange={ ( event ) =>
									setFill( {
										image: Number( event.target.value ),
									} )
								}
							>
								<option value="0" disabled>
									{ own.length + library.length === 0
										? __(
												'Upload an image first',
												'merchboard'
											)
										: __(
												'Choose an image…',
												'merchboard'
											) }
								</option>
								{ own.length > 0 && (
									<optgroup
										label={ __(
											'Your images',
											'merchboard'
										) }
									>
										{ own.map( ( image ) => (
											<option
												key={ image.id }
												value={ String( image.id ) }
											>
												{ image.name }
											</option>
										) ) }
									</optgroup>
								) }
								{ library.length > 0 && (
									<optgroup
										label={ __(
											'Image library',
											'merchboard'
										) }
									>
										{ library.map( ( image ) => (
											<option
												key={ image.id }
												value={ String( image.id ) }
											>
												{ image.name }
											</option>
										) ) }
									</optgroup>
								) }
							</select>
						) }
					</Field>
				) }
				{ fill.type !== 'none' &&
					select(
						__( 'Fill motion', 'merchboard' ),
						fill.motion,
						FILL_MOTIONS,
						motionLabel,
						( option ) => setFill( { motion: option } )
					) }
			</div>
			<AnimationSample
				animation={ value }
				duration={ duration }
				fill={ fillLook( fill, [ ...own, ...library ] ) }
			/>
		</fieldset>
	);
}
