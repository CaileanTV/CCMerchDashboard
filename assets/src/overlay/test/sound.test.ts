/**
 * Tests for the overlay's alert sounds (ADR-065).
 */
import { describe, expect, it } from '@jest/globals';
import { LENGTH_WAIT, playSound } from '../sound';

async function settle(): Promise< void > {
	await Promise.resolve();
	await Promise.resolve();
}

describe( 'playSound', () => {
	it( 'plays the sound at once, without a click (OBS allows autoplay)', async () => {
		const played: string[] = [];

		playSound( '/ding.mp3', ( url ) => ( {
			play: () => {
				played.push( url );
				return Promise.resolve();
			},
		} ) );
		await settle();

		expect( played ).toEqual( [ '/ding.mp3' ] );
		expect( document.querySelector( 'button' ) ).toBeNull();
	} );

	it( 'ignores a blocked sound and missing audio support', async () => {
		expect( () =>
			playSound( '/ding.mp3', () => ( {
				play: () =>
					Promise.reject(
						new DOMException( 'Blocked', 'NotAllowedError' )
					),
			} ) )
		).not.toThrow();
		expect( () =>
			playSound( '/ding.mp3', () => {
				throw new Error( 'No Audio' );
			} )
		).not.toThrow();
		await settle();
	} );

	it( "tells the sound's length once it is known, else null", async () => {
		const listeners: Record< string, () => void > = {};
		const sound = {
			duration: Number.NaN,
			play: () => Promise.resolve(),
			addEventListener: ( type: string, listener: () => void ) => {
				listeners[ type ] = listener;
			},
		};
		const length = playSound(
			'/ding.mp3',
			() => sound,
			() => undefined
		);

		sound.duration = 2.3456;
		listeners.loadedmetadata?.();

		expect( await length ).toBe( 2346 );

		let fallback: ( () => void ) | null = null;
		const waited: number[] = [];
		const unknown = playSound(
			'/ding.mp3',
			() => ( { play: () => Promise.resolve() } ),
			( callback, delay ) => {
				fallback = callback;
				waited.push( delay );
			}
		);

		( fallback as unknown as () => void )();
		expect( await unknown ).toBeNull();
		expect( waited ).toEqual( [ LENGTH_WAIT ] );
	} );
} );
