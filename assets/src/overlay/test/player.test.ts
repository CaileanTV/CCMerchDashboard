/**
 * Tests for the alert player (ADR-065 pkt. 3).
 */
import { describe, expect, it } from '@jest/globals';
import { AlertPlayer, MAX_QUEUE } from '../player';
import type { OverlayAlert } from '../feed';

function setup( hidden: string[] = [] ) {
	const image = document.createElement( 'div' );
	const text = document.createElement( 'div' );
	const secondary = document.createElement( 'div' );
	const timers: Array< { callback: () => void; delay: number } > = [];
	const names = new Map< HTMLElement, string >( [
		[ image, 'image' ],
		[ text, 'text' ],
		[ secondary, 'secondary' ],
	] );

	for ( const element of [ image, text, secondary ] ) {
		element.className =
			'merchboard-overlay__el merchboard-overlay__el--hidden';
	}

	const player = new AlertPlayer(
		{ image, text, secondary },
		{ displayDuration: 5000, animDuration: 500 },
		( element ) => ! hidden.includes( names.get( element ) ?? '' ),
		( callback, delay ) => {
			timers.push( { callback, delay } );
		}
	);

	const tick = (): number => {
		const timer = timers.shift();

		if ( timer === undefined ) {
			throw new Error( 'No timer pending.' );
		}

		timer.callback();

		return timer.delay;
	};

	return { image, text, secondary, player, timers, tick };
}

function alert(
	id: number,
	extra: Partial< OverlayAlert > = {}
): OverlayAlert {
	return { id, image: '', text: `Alert ${ id }`, secondary: '', ...extra };
}

describe( 'AlertPlayer', () => {
	it( 'shows alerts one at a time: display, then fade out, then the next', () => {
		const { text, player, tick } = setup();

		player.enqueue( [ alert( 1 ), alert( 2 ) ] );

		expect( text.textContent ).toBe( 'Alert 1' );
		expect(
			text.classList.contains( 'merchboard-overlay__el--visible' )
		).toBe( true );
		expect( player.waiting ).toBe( 1 );

		expect( tick() ).toBe( 5000 );
		expect(
			text.classList.contains( 'merchboard-overlay__el--hiding' )
		).toBe( true );

		expect( tick() ).toBe( 500 );
		expect( text.textContent ).toBe( 'Alert 2' );
		expect(
			text.classList.contains( 'merchboard-overlay__el--visible' )
		).toBe( true );
		expect( player.waiting ).toBe( 0 );

		tick();
		tick();
		expect( text.textContent ).toBe( '' );
		expect(
			text.classList.contains( 'merchboard-overlay__el--hidden' )
		).toBe( true );
	} );

	it( 'inserts text as text and the image as an img element', () => {
		const { image, text, secondary, player } = setup();

		player.enqueue( [
			alert( 1, {
				text: '<b>bold</b>',
				image: '/img.jpg',
				secondary: 'Thanks',
			} ),
		] );

		expect( text.innerHTML ).toBe( '&lt;b&gt;bold&lt;/b&gt;' );
		expect( image.querySelector( 'img' )?.getAttribute( 'src' ) ).toBe(
			'/img.jpg'
		);
		expect( image.querySelector( 'img' )?.alt ).toBe( '' );
		expect( secondary.textContent ).toBe( 'Thanks' );
		expect(
			secondary.classList.contains( 'merchboard-overlay__el--visible' )
		).toBe( true );
	} );

	it( 'skips hidden boxes, an empty image and an empty secondary text', () => {
		const { image, secondary, player } = setup( [ 'secondary' ] );

		player.enqueue( [ alert( 1, { secondary: 'Thanks' } ) ] );

		expect(
			image.classList.contains( 'merchboard-overlay__el--hidden' )
		).toBe( true );
		expect(
			secondary.classList.contains( 'merchboard-overlay__el--hidden' )
		).toBe( true );
	} );

	it( 'moves on at once when no box is shown', () => {
		const { text, player, timers } = setup( [
			'image',
			'text',
			'secondary',
		] );

		player.enqueue( [ alert( 1 ), alert( 2 ) ] );

		expect( timers ).toHaveLength( 0 );
		expect( player.waiting ).toBe( 0 );
		expect( text.textContent ).toBe( '' );
	} );

	it( 'keeps at most MAX_QUEUE alerts, one of them playing', () => {
		const { player } = setup();

		player.enqueue(
			Array.from( { length: MAX_QUEUE + 10 }, ( _, i ) => alert( i ) )
		);

		expect( player.waiting ).toBe( MAX_QUEUE - 1 );
	} );
} );
