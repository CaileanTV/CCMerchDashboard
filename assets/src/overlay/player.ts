/**
 * Shows alerts one after the other (ADR-065 pkt. 3). Each alert is drawn
 * with its look (the default or a variation, ADR-088) by render.ts: the box
 * comes in with its in-animation, stays for the display time (which
 * includes the in-animation), goes with its out-animation, then the next
 * alert follows. The alert's sound starts with the box. Texts are inserted
 * as text, never as HTML.
 *
 * - **Texts** animate in and out with their own animations (step 25.6.9);
 *   they can come later and go earlier than the box (text timing).
 * - **Highlight:** the inserted values' letters are marked.
 * - **Fills** (step 25.6.10) are laid out once the text has its final size;
 *   **around the image** (step 25.6.11) the main text runs along the frame
 *   instead of filling its box.
 */
import { applyFrame, clearFrame } from './frame';
import { leaveText, renderText, resetText } from './animations';
import { applyFill, clearFill, layoutFill } from './textfill';
import { arrange, highlight } from './render';
import type { AlertElements, AlertLook } from './render';
import type { OverlayAlert } from './feed';
import type { TextPart } from './look';

/** Shortest display time when it comes from a sound, in milliseconds. */
export const MIN_SOUND_DISPLAY = 1000;

/** Longest display time (ms), also with a long read-out. */
export const MAX_DISPLAY = 90000;

/** Pause after a read-out before the alert goes (ms). */
export const READ_OUT_PAUSE = 300;

/** Longest queue; older alerts are dropped when a flood arrives. */
export const MAX_QUEUE = 100;

export const ALERT_HIDDEN = 'merchboard-alert--hidden';
export const ALERT_ENTERING = 'merchboard-alert--entering';
export const ALERT_LEAVING = 'merchboard-alert--leaving';

/** What the player needs from the page; defaults do nothing. */
export interface PlayerHooks {
	/** Runs a callback after a delay (ms). */
	readonly schedule?: ( callback: () => void, delay: number ) => void;
	/** Plays a sound at a volume (0–1); resolves its length (ms) when known. */
	readonly play?: (
		url: string,
		volume: number
	) => Promise< number | null > | void;
	/** Shrinks a text to its box ("shrink"). */
	readonly fit?: ( element: HTMLElement ) => void;
	/** Moves the image up as far as the main text grew up. */
	readonly follow?: ( image: HTMLElement, text: HTMLElement ) => void;
	/** Called when the last alert has gone (the designer's preview). */
	readonly idle?: () => void;
}

export class AlertPlayer {
	private readonly queue: OverlayAlert[] = [];

	private playing = false;

	/** How long the current alert's texts take to go (ms). */
	private textsOut = 0;

	/** Whether the current alert's texts are gone already. */
	private textsLeft = false;

	/**
	 * The current alert's number: a timer of an earlier alert sees another
	 * number and does nothing (30.15 review).
	 */
	private generation = 0;

	private readonly schedule: ( callback: () => void, delay: number ) => void;

	/**
	 * Constructor.
	 *
	 * @param elements The alert's elements.
	 * @param looks    The look of a variation id ('' for the default).
	 * @param hooks    Timers, sound, fitting.
	 */
	constructor(
		private readonly elements: AlertElements,
		private readonly looks: ( id: string ) => AlertLook,
		private readonly hooks: PlayerHooks = {}
	) {
		this.schedule =
			hooks.schedule ??
			( ( callback, delay ) => {
				window.setTimeout( callback, delay );
			} );
	}

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

		const look = this.looks( alert.look );
		const shows = [
			look.elements.product_image.visible && alert.image !== '',
			look.elements.main_text.visible && alert.text !== '',
			look.show_secondary && alert.secondary !== '',
		];

		if ( ! shows.some( Boolean ) ) {
			this.next();
			return;
		}

		this.playing = true;
		this.generation += 1;
		const generation = this.generation;
		const current = () => generation === this.generation;
		arrange( this.elements, look );
		this.fillImage( alert.image );

		const timing = look.text_timing;

		if ( timing.show_after === 0 ) {
			this.showTexts( alert, look, false );
		} else {
			this.schedule( () => {
				if ( current() && ! this.textsLeft ) {
					this.showTexts( alert, look, true );
				}
			}, timing.show_after );
		}

		const { alert: box } = this.elements;
		box.classList.remove( ALERT_HIDDEN, ALERT_LEAVING );
		box.classList.add( ALERT_ENTERING );

		const length =
			alert.sound === ''
				? undefined
				: this.hooks.play?.( alert.sound, look.audio_volume / 100 );
		const waits: Array< Promise< number > > = [];

		// With "as long as the sound plays", the sound's length is the display time.
		if ( look.display_from_sound && length instanceof Promise ) {
			waits.push(
				length.then( ( ms ) =>
					ms === null
						? look.display_duration
						: Math.max( MIN_SOUND_DISPLAY, ms )
				)
			);
		}

		// The read-out starts after its delay; the alert stays until it has ended (ADR-089).
		if ( alert.tts !== '' && look.tts.enabled ) {
			waits.push( this.readOut( alert.tts, look, alert.text ) );
		}

		if ( waits.length === 0 ) {
			this.hide( look, look.display_duration );
			return;
		}

		const floor =
			look.display_from_sound && length instanceof Promise
				? 0
				: look.display_duration;

		void Promise.all( waits ).then( ( times ) =>
			this.hide(
				look,
				Math.min( MAX_DISPLAY, Math.max( floor, ...times ) )
			)
		);
	}

	/**
	 * Plays the read-out after its delay; resolves when it has ended (ms
	 * after the alert came in), or after the delay when its length is unknown.
	 *
	 * @param url  The read-out's URL.
	 * @param look Look.
	 * @param text The text read out (its length estimates an unknown length).
	 */
	private readOut(
		url: string,
		look: AlertLook,
		text: string
	): Promise< number > {
		const delay = look.tts.delay;
		// About 70 ms a character when the file's length is not known in time.
		const estimate = Array.from( text ).length * 70;

		return new Promise( ( resolve ) => {
			this.schedule( () => {
				const length = this.hooks.play?.( url, look.tts.volume / 100 );

				if ( length instanceof Promise ) {
					void length.then( ( ms ) =>
						resolve( delay + ( ms ?? estimate ) + READ_OUT_PAUSE )
					);
				} else {
					resolve( delay + estimate );
				}
			}, delay );
		} );
	}

	/**
	 * Puts the texts in with their animations, highlight, fill and frame.
	 *
	 * @param alert  Alert.
	 * @param look   Look.
	 * @param always Animate the default animation too (texts that come later).
	 */
	private showTexts(
		alert: OverlayAlert,
		look: AlertLook,
		always: boolean
	): void {
		const { image, text, secondary } = this.elements;

		this.textsLeft = false;
		this.textsOut = Math.max(
			this.putText( text, alert.text, alert.parts, look, 'main', always ),
			this.putText(
				secondary,
				look.show_secondary ? alert.secondary : '',
				alert.secondaryParts,
				look,
				'secondary',
				always
			)
		);

		applyFill( text, look.fills.main );
		applyFill( secondary, look.fills.secondary );

		// Around the image, the frame sizes the text; it is not fitted to a box.
		if ( look.frame ) {
			applyFrame( text, look.frame );
		} else if ( look.text_fit === 'shrink' ) {
			this.hooks.fit?.( text );
		}

		if ( look.text_fit === 'shrink' ) {
			this.hooks.fit?.( secondary );
		}

		if ( text.dataset.growsUp === '1' ) {
			this.hooks.follow?.( image, text );
		}

		// The letters' places are final only now.
		layoutFill( text );
		layoutFill( secondary );
	}

	/**
	 * Puts one text in; returns how long it takes to go (ms).
	 *
	 * @param element Text box.
	 * @param value   Text.
	 * @param parts   The text in pieces.
	 * @param look    Look.
	 * @param which   Main or secondary text.
	 * @param always  Animate the default animation too.
	 */
	private putText(
		element: HTMLElement,
		value: string,
		parts: readonly TextPart[],
		look: AlertLook,
		which: 'main' | 'secondary',
		always: boolean
	): number {
		const animation = look.animations[ which ];
		const times = renderText(
			element,
			value,
			animation,
			animation.in_ms,
			always
		);

		element.dataset.outTime = String( animation.out_ms );
		highlight( element, parts, look );

		return times.outTotal - animation.in_ms + animation.out_ms;
	}

	/**
	 * Lets the texts go with their out-animations.
	 */
	private leaveTexts(): void {
		if ( this.textsLeft ) {
			return;
		}

		this.textsLeft = true;

		for ( const element of [
			this.elements.text,
			this.elements.secondary,
		] ) {
			const out = element.dataset.outTime;

			if ( out !== undefined ) {
				element.style.setProperty(
					'--merchboard-anim-time',
					`${ out }ms`
				);
			}

			leaveText( element );
		}
	}

	/**
	 * Lets the texts go before the end (text timing), the box at the end of
	 * the display time, then shows the next alert.
	 *
	 * @param look  Look.
	 * @param delay Display time in milliseconds.
	 */
	private hide( look: AlertLook, delay: number ): void {
		const early = look.text_timing.hide_before;
		const generation = this.generation;

		if ( early > 0 && delay - early > look.text_timing.show_after ) {
			this.schedule( () => {
				if ( generation === this.generation ) {
					this.leaveTexts();
				}
			}, delay - early );
		}

		this.schedule( () => {
			if ( generation !== this.generation ) {
				return;
			}

			const { alert: box } = this.elements;

			box.classList.replace( ALERT_ENTERING, ALERT_LEAVING );

			const textsOut = this.textsLeft ? 0 : this.textsOut;
			this.leaveTexts();

			this.schedule(
				() => this.finish(),
				Math.max( look.animations.alert.out_ms, textsOut )
			);
		}, delay );
	}

	/**
	 * Shows an image, or none.
	 *
	 * @param url Image URL; '' for none.
	 */
	private fillImage( url: string ): void {
		const { image } = this.elements;

		image.replaceChildren();

		if ( url !== '' ) {
			const img = document.createElement( 'img' );
			img.src = url;
			img.alt = '';
			image.append( img );
		}
	}

	private finish(): void {
		const { alert, image, text, secondary } = this.elements;

		alert.classList.remove( ALERT_ENTERING, ALERT_LEAVING );
		alert.classList.add( ALERT_HIDDEN );
		image.replaceChildren();

		for ( const element of [ text, secondary ] ) {
			resetText( element );
			clearFill( element );
			clearFrame( element );
			element.style.removeProperty( 'font-size' );
			element.textContent = '';
			delete element.dataset.outTime;
		}

		image.style.removeProperty( 'margin-top' );
		this.textsOut = 0;
		this.playing = false;
		this.next();

		if ( ! this.playing ) {
			this.hooks.idle?.();
		}
	}
}
