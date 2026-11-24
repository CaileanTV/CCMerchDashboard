/**
 * Tests for cliparts with a definition in a design (phase 56, step 56.11):
 * a new one, the rules the server keeps them to, and their pictures.
 */
import { describe, expect, it, jest } from '@jest/globals';

import type { DesignerAsset } from '../api/designer';
import type {
	ClipartArt,
	ClipartPaint,
} from '../sections/designer/clipart-model';
import {
	ClipartPictures,
	handlingOf,
	newAssetImage,
	ruledImage,
	ruledObjects,
} from '../sections/designer/clipart-objects';
import type { ClipartPixels } from '../sections/designer/clipart-paint';
import { lockedPlacement } from '../sections/designer/clipart-paint';
import type {
	DesignObject,
	ImageObject,
} from '../sections/designer/design-model';
import { parseDesign } from '../sections/designer/design-model';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const WHITE: ClipartPaint = { type: 'solid', color: '#ffffff' };
const BLUE: ClipartPaint = { type: 'solid', color: '#0000ff' };
const GRADIENT: ClipartPaint = {
	type: 'gradient',
	from: '#ff0000',
	to: '#00ff00',
	angle: 45,
};

/** A clipart of 4 × 2 px with one part. */
const ART: ClipartArt = {
	width: 4,
	height: 2,
	parts: [
		{
			label: 'Bund',
			box: { x: 0, y: 0, width: 4, height: 2 },
			stroke: null,
			scale: 1,
			paint: WHITE,
			gradient: {
				type: 'gradient',
				from: '#aaccff',
				to: '#ff8800',
				angle: 90,
			},
		},
	],
	steps: [
		{
			type: 'part',
			part: 0,
			rect: { x: 0, y: 0, width: 4, height: 2 },
			fill: { x: 0, y: 0 },
			grown: null,
		},
	],
};

/** Its atlas: the fill mask, wholly covered. */
const ATLAS: ClipartPixels = {
	width: 4,
	height: 2,
	data: new Uint8ClampedArray( 4 * 2 * 4 ).fill( 255 ),
};

/**
 * An asset.
 *
 * @param fields What it adds; undefined for a clipart from before.
 */
function asset( fields?: DesignerAsset[ 'clipart' ] ): DesignerAsset {
	return {
		id: 7,
		name: 'Drikkedunk',
		width: 4,
		height: 2,
		mime: 'image/png',
		...( fields === undefined ? {} : { clipart: fields } ),
	};
}

const withParts = ( locked = false ): DesignerAsset =>
	asset( {
		bases: [ 3 ],
		locked,
		art: { art: ART, atlas: { width: 4, height: 2 }, palette: null },
	} );

/**
 * An image of asset 7.
 *
 * @param fields Fields to change.
 */
function image( fields: Partial< ImageObject > = {} ): ImageObject {
	return {
		type: 'image',
		id: 'a',
		asset: 7,
		x: 50,
		y: 60,
		width: 4,
		height: 2,
		angle: 10,
		scaleX: 2,
		scaleY: 3,
		flipX: true,
		flipY: false,
		opacity: 0.5,
		locked: false,
		hidden: false,
		...fields,
	};
}

describe( 'a new clipart', () => {
	it( 'starts with the file’s paints, evenly scaled and never faded', () => {
		const made = newAssetImage( withParts(), 100, 100 );

		expect( made.paints ).toEqual( [ WHITE ] );
		expect( made.scaleX ).toBe( made.scaleY );
		expect( made.opacity ).toBe( 1 );
		expect( made.width ).toBe( 4 );
	} );

	it( 'is placed by the lock: from top to bottom in the middle', () => {
		const made = newAssetImage( withParts( true ), 100, 40 );

		expect( made ).toMatchObject( lockedPlacement( 4, 2, 100, 40 ) );
		expect( made.scaleX ).toBe( 20 );
		expect( made.y ).toBe( 20 );
	} );

	it( 'is an image as before without a definition', () => {
		const made = newAssetImage( asset(), 100, 100 );

		expect( 'paints' in made ).toBe( false );
		expect( made.scaleX ).toBe( made.scaleY );
	} );
} );

describe( 'the rules of a clipart', () => {
	it( 'leaves an image without a definition alone', () => {
		const object = image();

		expect( ruledImage( object, asset(), 100, 100, false ) ).toBe( object );
		expect( ruledImage( object, undefined, 100, 100, false ) ).toBe(
			object
		);
	} );

	it( 'scales one with parts evenly and shows it whole', () => {
		const ruled = ruledImage(
			image( { paints: [ BLUE ] } ),
			withParts(),
			100,
			100,
			false
		);

		expect( ruled ).toMatchObject( {
			scaleX: 2,
			scaleY: 2,
			opacity: 1,
			angle: 10,
			flipX: true,
			paints: [ BLUE ],
		} );
	} );

	it( 'moves a locked one only sideways and keeps it whole', () => {
		// 4 × 2 on 100 × 40: scale 20, 80 wide, so the middle is 40–60.
		const ruled = ruledImage(
			image( { x: 95, paints: [ BLUE ] } ),
			withParts( true ),
			100,
			40,
			false
		);

		expect( ruled ).toMatchObject( {
			x: 60,
			y: 20,
			scaleX: 20,
			scaleY: 20,
			angle: 0,
			flipX: false,
			flipY: false,
		} );
	} );

	it( 'drops paints of a clipart without parts', () => {
		const ruled = ruledImage(
			image( { paints: [ BLUE ] } ),
			asset( { bases: null, locked: false, art: null } ),
			100,
			100,
			false
		);

		expect( 'paints' in ruled ).toBe( false );
		expect( ruled.opacity ).toBe( 0.5 );
	} );

	it( 'keeps missing paints missing, so the picture fails as the server refuses', () => {
		const ruled = ruledImage( image(), withParts(), 100, 100, false );

		expect( ruled.paints ).toBeUndefined();
	} );

	it( 'gives a gradient its first colour on embroidery', () => {
		const ruled = ruledImage(
			image( { paints: [ GRADIENT ] } ),
			withParts(),
			100,
			100,
			true
		);

		expect( ruled.paints ).toEqual( [
			{ type: 'solid', color: '#ff0000' },
		] );
	} );

	it( 'keeps the same objects when nothing changes', () => {
		const objects: DesignObject[] = [
			image( { scaleY: 2, opacity: 1, paints: [ BLUE ] } ),
		];

		expect(
			ruledObjects( objects, () => withParts(), 100, 100, false )
		).toBe( objects );
	} );

	it( 'tells the canvas how it may be changed', () => {
		expect( handlingOf( withParts( true ) ) ).toBe( 'sideways' );
		expect( handlingOf( withParts() ) ).toBe( 'uniform' );
		expect( handlingOf( asset() ) ).toBeNull();
		expect(
			handlingOf( asset( { bases: null, locked: false, art: null } ) )
		).toBeNull();
	} );
} );

describe( 'the paints in the stored design', () => {
	const stored = ( paints: unknown ) => ( {
		version: 1,
		sides: {
			front: [ { ...image(), paints } ],
		},
	} );

	it( 'reads them', () => {
		const read = parseDesign( stored( [ GRADIENT ] ) );

		expect( read?.sides.front?.[ 0 ] ).toMatchObject( {
			paints: [ GRADIENT ],
		} );
	} );

	it( 'refuses broken ones rather than drop them', () => {
		expect(
			parseDesign( stored( [ { type: 'solid', color: 'red' } ] ) )
		).toBeNull();
		expect( parseDesign( stored( [] ) ) ).toBeNull();
	} );

	it( 'adds nothing to an image without', () => {
		const { paints: _paints, ...plain } = image();
		const read = parseDesign( { version: 1, sides: { front: [ plain ] } } );

		expect( read?.sides.front?.[ 0 ] ).toEqual( plain );
		expect( 'paints' in ( read?.sides.front?.[ 0 ] ?? {} ) ).toBe( false );
	} );
} );

describe( 'the pictures of cliparts', () => {
	/**
	 * Pictures whose canvases are the pixels they were drawn from.
	 *
	 * @param load The atlas loader.
	 */
	function pictures(
		load: ( id: number ) => Promise< ClipartPixels | null >
	) {
		const drawn: ClipartPixels[] = [];
		const made = new ClipartPictures( load, ( pixels ) => {
			drawn.push( pixels );

			return pixels as unknown as HTMLCanvasElement;
		} );

		return { made, drawn };
	}

	it( 'leaves an image without parts to its file', () => {
		const { made } = pictures( () => Promise.resolve( ATLAS ) );

		expect(
			made.resolver( () => asset() ).image( image() )
		).toBeUndefined();
	} );

	it( 'cannot draw one before its atlas, or without paints', async () => {
		const { made } = pictures( () => Promise.resolve( ATLAS ) );
		const lookup = () => withParts();

		expect(
			made.resolver( lookup ).image( image( { paints: [ BLUE ] } ) )
		).toBeNull();

		await made.ready( [ image( { paints: [ BLUE ] } ) ], lookup );

		expect( made.resolver( lookup ).image( image() ) ).toBeNull();
	} );

	it( 'draws the paints once each and changes its key with the atlases', async () => {
		const { made, drawn } = pictures( () => Promise.resolve( ATLAS ) );
		const lookup = () => withParts();
		const before = made.resolver( lookup ).key;

		expect(
			await made.ready( [ image( { paints: [ BLUE ] } ) ], lookup )
		).toBe( true );

		const resolver = made.resolver( lookup );

		expect( resolver.key ).not.toBe( before );
		resolver.image( image( { paints: [ BLUE ] } ) );
		resolver.image( image( { paints: [ BLUE ] } ) );

		expect( drawn ).toHaveLength( 1 );
		// Blue on every pixel.
		expect( Array.from( drawn[ 0 ]?.data.slice( 0, 4 ) ?? [] ) ).toEqual( [
			0, 0, 255, 255,
		] );
		expect( resolver.handling?.( image() ) ).toBe( 'uniform' );
	} );

	it( 'reports an atlas that does not load and tries it again', async () => {
		const load = jest
			.fn< ( id: number ) => Promise< ClipartPixels | null > >()
			.mockResolvedValueOnce( null )
			.mockResolvedValueOnce( ATLAS );
		const { made } = pictures( load );
		const objects = [ image( { paints: [ BLUE ] } ) ];
		const lookup = () => withParts();

		expect( await made.ready( objects, lookup ) ).toBe( false );
		expect( made.resolver( lookup ).image( objects[ 0 ]! ) ).toBeNull();
		expect( await made.ready( objects, lookup ) ).toBe( true );
		expect( load ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'never draws paints that do not fit the parts', async () => {
		const { made } = pictures( () => Promise.resolve( ATLAS ) );
		const lookup = () => withParts();
		const objects = [ image( { paints: [ BLUE, BLUE ] } ) ];

		await made.ready( objects, lookup );

		expect( made.resolver( lookup ).image( objects[ 0 ]! ) ).toBeNull();
	} );

	it( 'draws a dragged colour small in one place, then the full picture (step 57.20)', async () => {
		const { made, drawn } = pictures( () => Promise.resolve( ATLAS ) );
		const lookup = () => withParts();
		const red: ClipartPaint = { type: 'solid', color: '#ff0000' };

		await made.ready( [ image( { paints: [ BLUE ] } ) ], lookup );

		// The longest side 2 of 4: half the size, scaled up as large on the canvas.
		const live = made.resolver( lookup, false, null, 2 );

		expect( live.key ).not.toBe( made.resolver( lookup ).key );
		live.image( image( { paints: [ BLUE ] } ) );
		expect( drawn.at( -1 )?.width ).toBe( 2 );
		expect( drawn.at( -1 )?.height ).toBe( 1 );
		expect( live.ratio?.( image( { paints: [ BLUE ] } ) ) ).toBe( 0.5 );

		// The next colour takes its place; the same colour is not drawn again.
		live.image( image( { paints: [ red ] } ) );
		live.image( image( { paints: [ red ] } ) );
		expect( drawn ).toHaveLength( 2 );

		// Embroidery is never drawn small.
		expect(
			made
				.resolver( lookup, true, null, 2 )
				.ratio?.( image( { paints: [ red ] } ) )
		).toBe( 1 );

		// At the end the full picture, once; the small ones were never kept.
		const full = made.resolver( lookup );

		full.image( image( { paints: [ red ] } ) );
		full.image( image( { paints: [ red ] } ) );
		expect( drawn.at( -1 )?.width ).toBe( 4 );
		expect( full.ratio?.( image( { paints: [ red ] } ) ) ).toBe( 1 );
		// Two small canvases and one full; the ratios made no canvas.
		expect( drawn ).toHaveLength( 3 );
	} );

	it( 'keeps drawn pictures up to a number of bytes, the newest always (step 57.20)', async () => {
		const drawn: ClipartPixels[] = [];
		// A 4 × 2 picture and its canvas are 64 bytes: room for two.
		const made = new ClipartPictures(
			() => Promise.resolve( ATLAS ),
			( pixels ) => {
				drawn.push( pixels );

				return pixels as unknown as HTMLCanvasElement;
			},
			128
		);
		const lookup = () => withParts();
		const paint = ( color: string ): ImageObject =>
			image( { paints: [ { type: 'solid', color } ] } );

		await made.ready( [ paint( '#000001' ) ], lookup );

		const resolver = made.resolver( lookup );

		resolver.image( paint( '#000001' ) );
		resolver.image( paint( '#000002' ) );
		resolver.image( paint( '#000001' ) );
		expect( drawn ).toHaveLength( 2 );

		// A third goes over: the oldest used (#000002) goes, #000001 stays.
		resolver.image( paint( '#000003' ) );
		resolver.image( paint( '#000001' ) );
		expect( drawn ).toHaveLength( 3 );
		resolver.image( paint( '#000002' ) );
		expect( drawn ).toHaveLength( 4 );

		// Too small a room still keeps the picture just drawn.
		const tight = new ClipartPictures(
			() => Promise.resolve( ATLAS ),
			( pixels ) => {
				drawn.push( pixels );

				return pixels as unknown as HTMLCanvasElement;
			},
			1
		);

		await tight.ready( [ paint( '#000001' ) ], lookup );
		tight.resolver( lookup ).image( paint( '#000001' ) );
		tight.resolver( lookup ).image( paint( '#000001' ) );
		expect( drawn ).toHaveLength( 5 );
	} );
} );
