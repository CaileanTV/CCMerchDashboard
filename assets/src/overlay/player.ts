/**
 * Shows alerts one after the other (ADR-065 pkt. 3), as MerchAlert does:
 * fade in, stay for the display time (which includes the fade-in), fade out
 * for the animation time, then the next alert. The alert's sound starts
 * with the fade-in. Texts are inserted as text, never as HTML. With text
 * animations (step 25.6.9), the texts animate in and out instead of fading,
 * and the next alert waits for the out-animation.
 */
import { leaveText, renderText, resetText } from './animations';
import type { TextAnimation } from './animations';
import type { OverlayAlert } from './feed';

export interface OverlayElements {
	image: HTMLElement;
	text: HTMLElement;
	secondary: HTMLElement;
}

export interface Timing {
	displayDuration: number;
	animDuration: number;
	/** Whether an alert with a sound is shown as long as the sound plays. */
	displayFromSound?: boolean;
	/** Text animations of the main and the secondary text. */
	animations?: {
		readonly main: TextAnimation;
		readonly secondary: TextAnimation;
	};
}

/** Shortest display time when it comes from a sound, in milliseconds. */
export const MIN_SOUND_DISPLAY = 1000;

/** Longest queue; older alerts are dropped when a flood arrives. */
export const MAX_QUEUE = 100;

const HIDDEN = 'merchboard-overlay__el--hidden';
const VISIBLE = 'merchboard-overlay__el--visible';
const HIDING = 'merchboard-overlay__el--hiding';

export class AlertPlayer {
	private readonly queue: OverlayAlert[] = [];

	private playing = false;

	/** How long the current alert's out-animation takes (ms). */
	private outTime = 0;

	constructor(
		private readonly elements: OverlayElements,
		private readonly timing: Timing,
		private readonly isShown: ( element: HTMLElement ) => boolean = (
			element
		) => window.getComputedStyle( element ).display !== 'none',
		private readonly schedule: (
			callback: () => void,
			delay: number
		) => void = ( callback, delay ) => {
			window.setTimeout( callback, delay );
		},
		private readonly play: (
			url: string
		) => Promise< number | null > | void = () => undefined,
		private readonly fit: ( element: HTMLElement ) => void = () =>
			undefined,
		private readonly arrange: ( elements: OverlayElements ) => void = () =>
			undefined
	) {}

	/** Number of alerts waiting. */
	get waiting(): number {
		return this.queue.length;
	}

	/**
	 * Queues alerts and starts showing them.
	 *
	 * @param alerts Alerts, oldest first.
	 */
	enqueue( alerts: OverlayAlert[] ): void {
		this.queue.push( ...alerts );

		if ( this.queue.length > MAX_QUEUE ) {
			this.queue.splice( 0, this.queue.length - MAX_QUEUE );
		}

		this.next();
	}

	private next(): void {
		if ( this.playing ) {
			return;
		}

		const alert = this.queue.shift();

		if ( alert === undefined ) {
			return;
		}

		this.playing = true;
		this.fill( alert );

		const { image, text, secondary } = this.elements;
		const animated = [
			alert.image !== '' && this.isShown( image ) ? image : null,
			this.isShown( text ) ? text : null,
			alert.secondary !== '' && this.isShown( secondary )
				? secondary
				: null,
		].filter( ( element ): element is HTMLElement => element !== null );

		if ( animated.length === 0 ) {
			this.finish();
			return;
		}

		const length =
			alert.sound === '' ? undefined : this.play( alert.sound );

		for ( const element of animated ) {
			element.classList.remove( HIDDEN, HIDING );
			element.classList.add( VISIBLE );
		}

		// With "as long as the sound plays", the sound's length is the display time.
		if (
			this.timing.displayFromSound === true &&
			length instanceof Promise
		) {
			void length.then( ( ms ) =>
				this.hide(
					animated,
					ms === null
						? this.timing.displayDuration
						: Math.max( MIN_SOUND_DISPLAY, ms )
				)
			);
			return;
		}

		this.hide( animated, this.timing.displayDuration );
	}

	/**
	 * Fades the shown elements out after a delay, then shows the next alert.
	 *
	 * @param animated The shown elements.
	 * @param delay    Display time in milliseconds.
	 */
	private hide( animated: HTMLElement[], delay: number ): void {
		this.schedule( () => {
			for ( const element of animated ) {
				element.classList.replace( VISIBLE, HIDING );
				leaveText( element );
			}

			this.schedule(
				() => {
					for ( const element of animated ) {
						element.classList.replace( HIDING, HIDDEN );
					}

					this.finish();
				},
				Math.max( this.timing.animDuration, this.outTime )
			);
		}, delay );
	}

	private fill( alert: OverlayAlert ): void {
		const { image, text, secondary } = this.elements;

		image.replaceChildren();

		if ( alert.image !== '' ) {
			const img = document.createElement( 'img' );
			img.src = alert.image;
			img.alt = '';
			img.className = 'merchboard-overlay__thumb';
			image.append( img );
		}

		this.outTime = 0;

		if ( this.timing.animations === undefined ) {
			text.textContent = alert.text;
			secondary.textContent = alert.secondary;
		} else {
			const duration = this.timing.animDuration;

			this.outTime = Math.max(
				renderText(
					text,
					alert.text,
					this.timing.animations.main,
					duration
				).outTotal,
				renderText(
					secondary,
					alert.secondary,
					this.timing.animations.secondary,
					duration
				).outTotal
			);
		}

		this.fit( text );
		this.fit( secondary );
		this.arrange( this.elements );
	}

	private finish(): void {
		this.elements.image.replaceChildren();
		resetText( this.elements.text );
		resetText( this.elements.secondary );
		this.elements.text.textContent = '';
		this.elements.secondary.textContent = '';
		this.playing = false;
		this.next();
	}
}
