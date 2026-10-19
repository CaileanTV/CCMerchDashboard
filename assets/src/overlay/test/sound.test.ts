/**
 * Tests for the overlay's alert sounds (ADR-065).
 */
import { describe, expect, it } from '@jest/globals';
import { playSound } from '../sound';

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
} );
