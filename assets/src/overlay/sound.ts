/**
 * Alert sounds of the overlay (ADR-065). OBS' browser source starts its
 * browser with `autoplay-policy=no-user-gesture-required`, so sounds play
 * automatically, also after OBS is restarted. A normal browser tab may block
 * them (autoplay policy) unless autoplay is allowed for the site in the
 * browser's settings; the alert is still shown.
 */

/** Something that can play a sound once. */
export interface Playable {
	play: () => Promise< void >;
}

/**
 * Plays a sound once; a blocked or failed sound only loses the sound.
 *
 * @param url    Sound URL of this site.
 * @param create Creates a sound; defaults to an Audio element.
 */
export function playSound(
	url: string,
	create: ( url: string ) => Playable = ( source ) => new Audio( source )
): void {
	let sound: Playable;

	try {
		sound = create( url );
	} catch {
		// No audio support: the alert is still shown.
		return;
	}

	sound.play().catch( () => undefined );
}
