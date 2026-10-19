/**
 * Alert sounds of the overlay (ADR-065). OBS' browser source plays sound
 * without a click. A normal browser blocks sound on a page nobody has
 * clicked yet (autoplay policy); then the page shows a button, and the
 * click turns the sound on for the rest of the visit and plays the blocked
 * sound.
 */

/** Something that can play a sound once. */
export interface Playable {
	play: () => Promise< void >;
}

/**
 * Whether a failed play() was blocked by the autoplay policy.
 *
 * @param error Rejection reason.
 */
export function isAutoplayBlocked( error: unknown ): boolean {
	return (
		typeof error === 'object' &&
		error !== null &&
		( error as { name?: unknown } ).name === 'NotAllowedError'
	);
}

export class SoundGate {
	private pending: string | null = null;

	/**
	 * @param button The "turn on sound" button (hidden until needed), or null.
	 * @param create Creates a sound; defaults to an Audio element.
	 */
	constructor(
		private readonly button: HTMLButtonElement | null,
		private readonly create: ( url: string ) => Playable = ( url ) =>
			new Audio( url )
	) {
		this.button?.addEventListener( 'click', () => this.unlock() );
	}

	/**
	 * Plays a sound; shows the button when the browser blocks it.
	 *
	 * @param url Sound URL of this site.
	 */
	play( url: string ): void {
		let sound: Playable;

		try {
			sound = this.create( url );
		} catch {
			// No audio support: the alert is still shown.
			return;
		}

		sound.play().catch( ( error: unknown ) => {
			if ( isAutoplayBlocked( error ) && this.button !== null ) {
				this.pending = url;
				this.button.hidden = false;
			}
		} );
	}

	private unlock(): void {
		const url = this.pending;

		this.pending = null;

		if ( this.button !== null ) {
			this.button.hidden = true;
		}

		if ( url !== null ) {
			this.play( url );
		}
	}
}
