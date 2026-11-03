/**
 * Tests for the overlay feed helpers (ADR-065 pkt. 4).
 */
import { describe, expect, it } from '@jest/globals';
import {
	MAX_BACKOFF,
	RELOAD_CURSOR_TTL,
	backoff,
	feedUrl,
	lookOf,
	parseAnswer,
	parseConfig,
	reloadUrl,
	takeCursor,
} from '../feed';

const FEED = `${ window.location.origin }/wp-json/merchboard/v1/alerts/overlay/${ 'a'.repeat( 32 ) }/feed`;

describe( 'parseConfig', () => {
	it( 'accepts the page configuration with its looks', () => {
		const config = parseConfig( {
			feed: FEED,
			version: 'v1',
			looks: {
				'': { text_fit: 'shrink', frame: { speed: 'warp' } },
				big001: {
					font_size: 60,
					fills: {
						main: { kind: 'preset', preset: 'gold', motion: 'pan' },
						secondary: {
							kind: 'image',
							url: 'https://evil.example/x.png',
							width: 10,
							height: 10,
							motion: 'none',
						},
					},
				},
				'../evil': { font_size: 99 },
			},
		} );

		expect( config?.feed ).toBe( FEED );
		expect( [ ...( config?.looks.keys() ?? [] ) ] ).toEqual( [
			'',
			'big001',
		] );
		expect( config?.looks.get( '' )?.text_fit ).toBe( 'shrink' );
		expect( config?.looks.get( '' )?.frame ).toEqual( {
			enabled: true,
			direction: 'clockwise',
			speed: 'normal',
			repeat: true,
		} );
		expect( config?.looks.get( 'big001' )?.fills ).toEqual( {
			main: { kind: 'preset', preset: 'gold', motion: 'pan' },
			secondary: null,
		} );
		expect(
			config === null ? null : lookOf( config, 'gone01' ).font_size
		).toBe( 32 );
		expect(
			config === null ? null : lookOf( config, 'big001' ).font_size
		).toBe( 60 );
	} );

	it( 'rejects a feed on another origin and invalid values', () => {
		const base = {
			feed: FEED,
			version: 'v1',
			looks: { '': {} },
		};

		expect(
			parseConfig( { ...base, feed: 'https://evil.example/feed' } )
		).toBeNull();
		expect( parseConfig( { ...base, looks: {} } ) ).toBeNull();
		expect( parseConfig( { ...base, version: 1 } ) ).toBeNull();
		expect( parseConfig( null ) ).toBeNull();
		expect( parseConfig( [] ) ).toBeNull();
	} );
} );

describe( 'parseAnswer', () => {
	it( 'accepts an answer and keeps only same-origin images and sounds', () => {
		expect(
			parseAnswer( {
				cursor: 12,
				version: 'v1',
				alerts: [
					{
						id: 11,
						image: `${ window.location.origin }/wp-content/uploads/a.jpg`,
						text: 'nova_fan bought Hoodie',
						secondary: '',
						sound: `${ window.location.origin }/wp-content/uploads/ding.mp3`,
					},
					{
						id: 12,
						image: 'https://cdn.example/b.jpg',
						text: 'Anonymous bought Mug',
						secondary: 'Thanks!',
						sound: 'https://cdn.example/ding.mp3',
					},
				],
			} )
		).toEqual( {
			cursor: 12,
			version: 'v1',
			alerts: [
				{
					id: 11,
					look: '',
					image: `${ window.location.origin }/wp-content/uploads/a.jpg`,
					text: 'nova_fan bought Hoodie',
					secondary: '',
					parts: [ { text: 'nova_fan bought Hoodie', value: false } ],
					secondaryParts: [],
					sound: `${ window.location.origin }/wp-content/uploads/ding.mp3`,
				},
				{
					id: 12,
					look: '',
					image: '',
					text: 'Anonymous bought Mug',
					secondary: 'Thanks!',
					parts: [ { text: 'Anonymous bought Mug', value: false } ],
					secondaryParts: [ { text: 'Thanks!', value: false } ],
					sound: '',
				},
			],
		} );
	} );

	it( 'keeps the variation and the marked values, and falls back to the whole text', () => {
		const answer = parseAnswer( {
			cursor: 3,
			version: 'v',
			alerts: [
				{
					id: 3,
					look: 'big001',
					image: '',
					text: 'nova bought Hoodie',
					secondary: '',
					parts: [
						{ text: 'nova', value: true },
						{ text: ' bought ', value: false },
						{ text: 'Hoodie', value: true },
					],
					secondary_parts: [],
					sound: '',
				},
				{
					id: 4,
					look: '../evil',
					image: '',
					text: 'Shown',
					secondary: '',
					parts: [ { text: 'Other', value: true } ],
					sound: '',
				},
			],
		} );

		expect( answer?.alerts[ 0 ]?.look ).toBe( 'big001' );
		expect( answer?.alerts[ 0 ]?.parts[ 2 ] ).toEqual( {
			text: 'Hoodie',
			value: true,
		} );
		expect( answer?.alerts[ 1 ]?.look ).toBe( '' );
		expect( answer?.alerts[ 1 ]?.parts ).toEqual( [
			{ text: 'Shown', value: false },
		] );
	} );

	it( 'rejects malformed answers as a whole', () => {
		expect(
			parseAnswer( { cursor: -1, version: 'v', alerts: [] } )
		).toBeNull();
		expect(
			parseAnswer( { cursor: 1, version: 'v', alerts: {} } )
		).toBeNull();
		expect(
			parseAnswer( {
				cursor: 1,
				version: 'v',
				alerts: [
					{ id: 1, image: '', text: 3, secondary: '', sound: '' },
				],
			} )
		).toBeNull();
		expect(
			parseAnswer( {
				cursor: 1,
				version: 'v',
				alerts: [ { id: 1, image: '', text: 'x', secondary: '' } ],
			} )
		).toBeNull();
		expect( parseAnswer( 'oops' ) ).toBeNull();
	} );
} );

describe( 'feedUrl', () => {
	it( 'asks for the cursor first, then long-polls after it', () => {
		expect( feedUrl( FEED, null, true ) ).toBe( `${ FEED }?wait=false` );
		expect( feedUrl( FEED, 41, true ) ).toBe(
			`${ FEED }?after=41&wait=true`
		);
		expect( feedUrl( FEED, 41, false ) ).toBe(
			`${ FEED }?after=41&wait=false`
		);
	} );
} );

describe( 'backoff', () => {
	it( 'doubles from one second up to the maximum', () => {
		expect( [ 0, 1, 2, 3, 4, 5, 6, 20 ].map( backoff ) ).toEqual( [
			1000,
			2000,
			4000,
			8000,
			16000,
			MAX_BACKOFF,
			MAX_BACKOFF,
			MAX_BACKOFF,
		] );
	} );
} );

describe( 'the cursor across a reload', () => {
	const PAGE = `${ window.location.origin }/merchboard-alerts/overlay/${ 'a'.repeat( 32 ) }/`;

	it( 'is carried in the address and removed from it', () => {
		const href = reloadUrl( PAGE, 41, 1000 );

		expect( takeCursor( href, 2000 ) ).toEqual( {
			cursor: 41,
			href: PAGE,
		} );
		expect( takeCursor( PAGE, 2000 ) ).toEqual( {
			cursor: null,
			href: PAGE,
		} );
	} );

	it( 'is ignored when old, from the future or damaged', () => {
		expect(
			takeCursor(
				reloadUrl( PAGE, 41, 1000 ),
				1000 + RELOAD_CURSOR_TTL + 1
			).cursor
		).toBeNull();
		expect(
			takeCursor( reloadUrl( PAGE, 41, 5000 ), 1000 ).cursor
		).toBeNull();
		expect(
			takeCursor(
				`${ PAGE }?merchboard_after=-1&merchboard_at=1000`,
				1000
			).cursor
		).toBeNull();
		expect(
			takeCursor(
				`${ PAGE }?merchboard_after=x&merchboard_at=1000`,
				1000
			).cursor
		).toBeNull();
	} );
} );
