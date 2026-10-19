/**
 * Tests for the overlay's alert sounds (ADR-065).
 */
import { describe, expect, it } from '@jest/globals';
import { SoundGate, isAutoplayBlocked } from '../sound';

function blocked(): DOMException {
	return new DOMException( 'Blocked', 'NotAllowedError' );
}

async function settle(): Promise< void > {
	await Promise.resolve();
	await Promise.resolve();
}

describe( 'SoundGate', () => {
	it( 'plays at once when the browser allows it (OBS)', async () => {
		const button = document.createElement( 'button' );
		button.hidden = true;
		const played: string[] = [];
		const gate = new SoundGate( button, ( url ) => ( {
			play: () => {
				played.push( url );
				return Promise.resolve();
			},
		} ) );

		gate.play( '/ding.mp3' );
		await settle();

		expect( played ).toEqual( [ '/ding.mp3' ] );
		expect( button.hidden ).toBe( true );
	} );

	it( 'shows the button when autoplay is blocked and plays the sound after the click', async () => {
		const button = document.createElement( 'button' );
		button.hidden = true;
		const attempts: string[] = [];
		let allowed = false;
		const gate = new SoundGate( button, ( url ) => ( {
			play: () => {
				attempts.push( url );
				return allowed
					? Promise.resolve()
					: Promise.reject( blocked() );
			},
		} ) );

		gate.play( '/ding.mp3' );
		await settle();
		expect( button.hidden ).toBe( false );

		allowed = true;
		button.click();
		await settle();

		expect( button.hidden ).toBe( true );
		expect( attempts ).toEqual( [ '/ding.mp3', '/ding.mp3' ] );
	} );

	it( 'ignores other playback errors and missing audio support', async () => {
		const button = document.createElement( 'button' );
		button.hidden = true;
		const failing = new SoundGate( button, () => ( {
			play: () => Promise.reject( new Error( 'Decode error' ) ),
		} ) );
		const unsupported = new SoundGate( button, () => {
			throw new Error( 'No Audio' );
		} );

		failing.play( '/broken.mp3' );
		unsupported.play( '/ding.mp3' );
		await settle();

		expect( button.hidden ).toBe( true );
		expect( isAutoplayBlocked( blocked() ) ).toBe( true );
		expect( isAutoplayBlocked( new Error( 'x' ) ) ).toBe( false );
	} );
} );
