/**
 * Tests for the overlay feed helpers (ADR-065 pkt. 4).
 */
import { describe, expect, it } from '@jest/globals';
import {
	MAX_BACKOFF,
	backoff,
	feedUrl,
	parseAnswer,
	parseConfig,
} from '../feed';

const FEED = `${ window.location.origin }/wp-json/merchboard/v1/alerts/overlay/${ 'a'.repeat( 32 ) }/feed`;

describe( 'parseConfig', () => {
	it( 'accepts the page configuration', () => {
		expect(
			parseConfig( {
				feed: FEED,
				version: 'v1',
				displayDuration: 5000,
				animDuration: 500,
			} )
		).toEqual( {
			feed: FEED,
			version: 'v1',
			displayDuration: 5000,
			animDuration: 500,
		} );
	} );

	it( 'rejects a feed on another origin and invalid values', () => {
		const base = {
			feed: FEED,
			version: 'v1',
			displayDuration: 5000,
			animDuration: 500,
		};

		expect(
			parseConfig( { ...base, feed: 'https://evil.example/feed' } )
		).toBeNull();
		expect( parseConfig( { ...base, displayDuration: -1 } ) ).toBeNull();
		expect( parseConfig( { ...base, version: 1 } ) ).toBeNull();
		expect( parseConfig( null ) ).toBeNull();
		expect( parseConfig( [] ) ).toBeNull();
	} );
} );

describe( 'parseAnswer', () => {
	it( 'accepts an answer and keeps only same-origin images', () => {
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
					},
					{
						id: 12,
						image: 'https://cdn.example/b.jpg',
						text: 'Anonymous bought Mug',
						secondary: 'Thanks!',
					},
				],
			} )
		).toEqual( {
			cursor: 12,
			version: 'v1',
			alerts: [
				{
					id: 11,
					image: `${ window.location.origin }/wp-content/uploads/a.jpg`,
					text: 'nova_fan bought Hoodie',
					secondary: '',
				},
				{
					id: 12,
					image: '',
					text: 'Anonymous bought Mug',
					secondary: 'Thanks!',
				},
			],
		} );
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
				alerts: [ { id: 1, image: '', text: 3, secondary: '' } ],
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
