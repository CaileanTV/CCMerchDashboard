/**
 * Alert sounds of the overlay (ADR-065). OBS' browser source starts its
 * browser with `autoplay-policy=no-user-gesture-required`, so sounds play
 * automatically, also after OBS is restarted. A normal browser tab may block
 * them (autoplay policy) unless autoplay is allowed for the site in the
 * browser's settings; the alert is still shown.
 */

/** Longest wait for a sound's length before the display time is used. */
export const LENGTH_WAIT = 3000;

/** Something that can play a sound once and tell its length. */
export interface Playable {
	play: () => Promise< void >;
	/** Length in seconds, once known (NaN before). */
	readonly duration?: number;
	addEventListener?: (
		type: 'loadedmetadata' | 'error',
		listener: () => void,
		options?: { once?: boolean }
	) => void;
}

/**
 * Plays a sound once; a blocked or failed sound only loses the sound.
 * Resolves with the sound's length in milliseconds (step 25.6.8), or null
 * when it is unknown within LENGTH_WAIT.
 *
 * @param url    Sound URL of this site.
 * @param create Creates a sound; defaults to an Audio element.
 * @param wait   Schedules the fallback; defaults to setTimeout.
 */
export function playSound(
	url: string,
	create: ( url: string ) => Playable = ( source ) => new Audio( source ),
	wait: ( callback: () => void, delay: number ) => void = (
		callback,
		delay
	) => {
		window.setTimeout( callback, delay );
	}
): Promise< number | null > {
	let sound: Playable;

	try {
		sound = create( url );
	} catch {
		// No audio support: the alert is still shown.
		return Promise.resolve( null );
	}

	const length = new Promise< number | null >( ( resolve ) => {
		const known = () => {
			const seconds = sound.duration;

			resolve(
				typeof seconds === 'number' &&
					Number.isFinite( seconds ) &&
					seconds > 0
					? Math.round( seconds * 1000 )
					: null
			);
		};

		sound.addEventListener?.( 'loadedmetadata', known, { once: true } );
		sound.addEventListener?.( 'error', () => resolve( null ), {
			once: true,
		} );
		wait( () => resolve( null ), LENGTH_WAIT );

		if ( typeof sound.duration === 'number' && sound.duration > 0 ) {
			known();
		}
	} );

	sound.play().catch( () => undefined );

	return length;
}
