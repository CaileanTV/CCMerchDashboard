/**
 * Tests for the alert player (ADR-065 pkt. 3, ADR-088).
 */
import { describe, expect, it } from '@jest/globals';
import {
	ALERT_ENTERING,
	ALERT_HIDDEN,
	ALERT_LEAVING,
	AlertPlayer,
	MAX_QUEUE,
} from '../player';
import type { PlayerHooks } from '../player';
import type { OverlayAlert } from '../feed';
import { LETTER_STEP } from '../animations';
import { parseLook } from '../render';
import type { AlertElements, AlertLook } from '../render';

function elements(): AlertElements {
	const box = document.createElement( 'div' );
	const image = document.createElement( 'div' );
	const texts = document.createElement( 'div' );
	const text = document.createElement( 'div' );
	const secondary = document.createElement( 'div' );

	box.className = `merchboard-alert ${ ALERT_HIDDEN }`;
	texts.append( text, secondary );
	box.append( image, texts );

	return { alert: box, image, texts, text, secondary };
}

function look( data: Record< string, unknown > = {} ): AlertLook {
	return parseLook(
		{
			show_secondary: true,
			elements: { secondary_text: { visible: true } },
			...data,
		},
		window.location.origin
	);
}

function setup(
	data: Record< string, unknown > = {},
	length: number | null = null,
	hooks: PlayerHooks = {},
	variations: Record< string, AlertLook > = {}
) {
	const parts = elements();
	const timers: Array< { callback: () => void; delay: number } > = [];
	const played: Array< { url: string; volume: number } > = [];
	const base = look( data );
	const player = new AlertPlayer( parts, ( id ) => variations[ id ] ?? base, {
		schedule: ( callback, delay ) => {
			timers.push( { callback, delay } );
		},
		play: ( url, volume ) => {
			played.push( { url, volume } );

			return Promise.resolve( length );
		},
		...hooks,
	} );

	const tick = (): number => {
		const timer = timers.shift();

		if ( timer === undefined ) {
			throw new Error( 'No timer pending.' );
		}

		timer.callback();

		return timer.delay;
	};

	return { ...parts, player, timers, played, tick };
}

function alert(
	id: number,
	extra: Partial< OverlayAlert > = {}
): OverlayAlert {
	const text = extra.text ?? `Alert ${ id }`;

	return {
		id,
		look: '',
		image: '',
		text,
		secondary: '',
		parts: [ { text, value: false } ],
		secondaryParts: [],
		sound: '',
		tts: '',
		...extra,
	};
}

/** Lets pending promises (sound lengths, read-outs) resolve. */
async function settle(): Promise< void > {
	for ( let i = 0; i < 6; i++ ) {
		await Promise.resolve();
	}
}

describe( 'AlertPlayer', () => {
	it( 'shows alerts one at a time: in, display, out, then the next', () => {
		const { alert: box, text, player, tick } = setup();

		player.enqueue( [ alert( 1 ), alert( 2 ) ] );

		expect( text.textContent ).toBe( 'Alert 1' );
		expect( box.classList.contains( ALERT_ENTERING ) ).toBe( true );
		expect( box.classList.contains( ALERT_HIDDEN ) ).toBe( false );
		expect( player.waiting ).toBe( 1 );

		expect( tick() ).toBe( 5000 );
		expect( box.classList.contains( ALERT_LEAVING ) ).toBe( true );

		expect( tick() ).toBe( 500 );
		expect( text.textContent ).toBe( 'Alert 2' );
		expect( box.classList.contains( ALERT_ENTERING ) ).toBe( true );
		expect( player.waiting ).toBe( 0 );

		tick();
		tick();
		expect( text.textContent ).toBe( '' );
		expect( box.classList.contains( ALERT_HIDDEN ) ).toBe( true );
	} );

	it( "plays each alert's sound once, at the look's volume", () => {
		const { player, played, tick } = setup( { audio_volume: 40 } );

		player.enqueue( [ alert( 1, { sound: '/ding.mp3' } ), alert( 2 ) ] );
		expect( played ).toEqual( [ { url: '/ding.mp3', volume: 0.4 } ] );

		tick();
		tick();
		expect( played ).toHaveLength( 1 );
	} );

	it( 'inserts text as text and the image as an img element', () => {
		const { image, text, secondary, player } = setup();

		player.enqueue( [
			alert( 1, {
				text: '<b>bold</b>',
				image: '/img.jpg',
				secondary: 'Thanks',
				secondaryParts: [ { text: 'Thanks', value: false } ],
			} ),
		] );

		expect( text.innerHTML ).toBe( '&lt;b&gt;bold&lt;/b&gt;' );
		expect( image.querySelector( 'img' )?.getAttribute( 'src' ) ).toBe(
			'/img.jpg'
		);
		expect( image.querySelector( 'img' )?.alt ).toBe( '' );
		expect( secondary.textContent ).toBe( 'Thanks' );
	} );

	it( 'moves on at once when nothing would be shown', () => {
		const { text, player, timers } = setup( {
			elements: {
				product_image: { visible: false },
				main_text: { visible: false },
			},
			show_secondary: false,
		} );

		player.enqueue( [ alert( 1, { secondary: 'Hi' } ), alert( 2 ) ] );

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

	it( 'shows an alert with a sound as long as the sound plays', async () => {
		const { player, timers } = setup( { display_from_sound: true }, 2750 );

		player.enqueue( [ alert( 1, { sound: '/ding.mp3' } ) ] );
		expect( timers ).toHaveLength( 0 );

		await settle();
		expect( timers[ 0 ]?.delay ).toBe( 2750 );
	} );

	it( 'uses the display time without a sound, or when its length is unknown', async () => {
		const unknown = setup( { display_from_sound: true }, null );

		unknown.player.enqueue( [ alert( 1, { sound: '/ding.mp3' } ) ] );
		await settle();
		expect( unknown.timers[ 0 ]?.delay ).toBe( 5000 );

		const silent = setup( { display_from_sound: true }, 2750 );

		silent.player.enqueue( [ alert( 2 ) ] );
		expect( silent.timers[ 0 ]?.delay ).toBe( 5000 );
	} );

	it( 'reads the alert out after its delay and stays until the read-out ends', async () => {
		const { player, timers, played } = setup(
			{
				display_duration: 3000,
				tts: { enabled: true, delay: 800, volume: 50 },
			},
			4000
		);

		player.enqueue( [ alert( 1, { tts: '/tts.mp3' } ) ] );
		expect( played ).toEqual( [] );
		expect( timers[ 0 ]?.delay ).toBe( 800 );

		timers.shift()?.callback();
		expect( played ).toEqual( [ { url: '/tts.mp3', volume: 0.5 } ] );

		await settle();
		expect( timers[ 0 ]?.delay ).toBe( 800 + 4000 + 300 );
	} );

	it( 'draws each alert with its variation and places the box', () => {
		const big = look( {
			widget: { x: 100, y: 50, w: 600, h: 300, rotation: 10 },
			layout_mode: 'side',
			display_duration: 8000,
			animations: { alert: { in: 'zoom', out: 'flip', out_ms: 900 } },
		} );
		const {
			alert: box,
			player,
			tick,
		} = setup(
			{},
			null,
			{},
			{
				big001: big,
			}
		);

		player.enqueue( [ alert( 1, { look: 'big001' } ) ] );

		expect( box.dataset.layout ).toBe( 'side' );
		expect( box.dataset.in ).toBe( 'zoom' );
		expect( box.style.left ).toBe( '100px' );
		expect(
			box.style.getPropertyValue( '--merchboard-alert-rotation' )
		).toBe( '10deg' );
		expect( tick() ).toBe( 8000 );
		expect( tick() ).toBe( 900 );
	} );

	it( 'animates the texts and waits for a letter out-animation', () => {
		const { text, player, timers } = setup( {
			animations: {
				main_text: { in: 'pop', out: 'typewriter', out_ms: 300 },
			},
		} );

		player.enqueue( [ alert( 1, { text: 'Hello' } ) ] );
		expect( text.dataset.in ).toBe( 'pop' );
		expect(
			text.querySelectorAll( '.merchboard-anim__letter' )
		).toHaveLength( 5 );

		timers.shift()?.callback();
		expect( text.classList.contains( 'merchboard-anim--out' ) ).toBe(
			true
		);
		expect( text.style.getPropertyValue( '--merchboard-anim-time' ) ).toBe(
			'300ms'
		);
		expect( timers[ 0 ]?.delay ).toBe( 300 + 5 * LETTER_STEP.normal );

		timers.shift()?.callback();
		expect( text.classList.contains( 'merchboard-anim' ) ).toBe( false );
		expect( text.textContent ).toBe( '' );
	} );

	it( 'shows the texts later and lets them go earlier than the box', () => {
		const { text, player, tick } = setup( {
			text_timing: { show_after: 1000, hide_before: 2000 },
		} );

		player.enqueue( [ alert( 1, { text: 'Later' } ) ] );
		expect( text.textContent ).toBe( '' );

		expect( tick() ).toBe( 1000 );
		expect( text.textContent ).toBe( 'Later' );
		expect( text.dataset.in ).toBe( 'fade' );

		expect( tick() ).toBe( 3000 );
		expect( text.classList.contains( 'merchboard-anim--out' ) ).toBe(
			true
		);
	} );

	it( 'never puts a late text into the next alert (30.15 review)', () => {
		const { text, player, timers } = setup( {
			display_duration: 1000,
			text_timing: { show_after: 3000, hide_before: 0 },
		} );

		player.enqueue( [
			alert( 1, { text: 'First' } ),
			alert( 2, { text: 'Second' } ),
		] );

		const lateFirst = timers.shift();
		expect( lateFirst?.delay ).toBe( 3000 );
		// The box goes, then the second alert starts.
		timers.shift()?.callback();
		timers.shift()?.callback();
		expect( player.waiting ).toBe( 0 );

		lateFirst?.callback();
		expect( text.textContent ).toBe( '' );
	} );

	it( 'marks the inserted values when the highlight is on', () => {
		const { text, player } = setup( {
			highlight: { enabled: true, color: '#ff0000', effect: 'pulse' },
		} );

		player.enqueue( [
			alert( 1, {
				text: 'Ann bought Cap',
				parts: [
					{ text: 'Ann', value: true },
					{ text: ' bought ', value: false },
					{ text: 'Cap', value: true },
				],
			} ),
		] );

		const marked = Array.from(
			text.querySelectorAll( '.merchboard-hl' ),
			( letter ) => letter.textContent
		).join( '' );

		expect( marked ).toBe( 'AnnCap' );
		expect( text.dataset.highlight ).toBe( 'pulse' );
		expect( text.style.getPropertyValue( '--merchboard-hl-color' ) ).toBe(
			'#ff0000'
		);
		expect( text.textContent ).toBe( 'Ann bought Cap' );
	} );

	it( 'fills the texts and removes the fill after the alert (step 25.6.10)', () => {
		const { text, secondary, player, tick } = setup( {
			fills: { main: { kind: 'preset', preset: 'gold', motion: 'pan' } },
		} );

		player.enqueue( [
			alert( 1, {
				text: 'Hey',
				secondary: 'You',
				secondaryParts: [ { text: 'You', value: false } ],
			} ),
		] );
		expect( text.classList.contains( 'merchboard-fill' ) ).toBe( true );
		expect(
			text.querySelectorAll( '.merchboard-anim__letter' )
		).toHaveLength( 3 );
		expect( secondary.classList.contains( 'merchboard-fill' ) ).toBe(
			false
		);
		expect( secondary.textContent ).toBe( 'You' );

		tick();
		tick();
		expect( text.classList.contains( 'merchboard-fill' ) ).toBe( false );
		expect( text.textContent ).toBe( '' );
	} );

	it( 'runs the main text round the image instead of fitting it (step 25.6.11)', () => {
		const fitted: HTMLElement[] = [];
		const { text, secondary, player, tick } = setup(
			{
				text_fit: 'shrink',
				frame: {
					direction: 'clockwise',
					speed: 'slow',
					repeat: true,
				},
			},
			null,
			{
				fit: ( element ) => {
					fitted.push( element );
				},
			}
		);

		player.enqueue( [
			alert( 1, {
				text: 'Hey you',
				secondary: 'Hi',
				secondaryParts: [ { text: 'Hi', value: false } ],
			} ),
		] );
		expect( text.classList.contains( 'merchboard-frame' ) ).toBe( true );
		expect( text.dataset.frameSpeed ).toBe( 'slow' );
		expect( fitted ).toEqual( [ secondary ] );
		expect( secondary.classList.contains( 'merchboard-frame' ) ).toBe(
			false
		);

		tick();
		tick();
		expect( text.classList.contains( 'merchboard-frame' ) ).toBe( false );
		expect( text.textContent ).toBe( '' );
	} );
} );
