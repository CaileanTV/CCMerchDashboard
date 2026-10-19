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
import { Button } from '../../components/Button';
import { Field } from '../../components/Field';

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
 */
export function AnimationSample( {
	animation,
	duration,
}: {
	readonly animation: TextAnimation;
	readonly duration: number;
} ) {
	const ref = useRef< HTMLSpanElement >( null );
	// With reduced motion the sample waits until it is played on request.
	const [ playing, setPlaying ] = useState(
		() =>
			typeof window.matchMedia !== 'function' ||
			! window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
	);
	const text = __( 'Thanks for the merch!', 'merchboard' );
	const choice = JSON.stringify( animation );

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
		}

		return () => {
			window.clearTimeout( timer );
			resetText( element );
			element.textContent = text;
		};
		// The animation is compared by value (choice).
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
}

/**
 * The animation of one text (step 25.6.9): in, effect while shown, out,
 * colour (when the choice uses one) and speed.
 *
 * @param props          Props.
 * @param props.legend   The text's name.
 * @param props.value    The animation.
 * @param props.onChange Called with the changed animation.
 * @param props.duration In/out time (ms) for the sample.
 */
export function AnimationFields( {
	legend,
	value,
	onChange,
	duration,
}: Props ) {
	const set = ( change: Partial< TextAnimation > ) =>
		onChange( { ...value, ...change } );

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
			<AnimationSample animation={ value } duration={ duration } />
		</fieldset>
	);
}
