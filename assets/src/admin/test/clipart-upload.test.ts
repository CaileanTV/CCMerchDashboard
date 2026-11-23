/**
 * Tests for the upload of a clipart with parts (phase 56, step 56.13).
 */
import { describe, expect, it } from '@jest/globals';

import type { ClipartArt } from '../../dashboard/sections/designer/clipart-model';
import {
	clipartError,
	clipartWarnings,
	definitionOf,
	differsTooMuch,
	fixedColors,
	paletteOf,
	startColors,
} from '../clipart-upload';
import type { BaseInfo } from '../clipart-upload';
import { ClipartError } from '../psd-clipart';

/** 100 × 200 px: one part, one fixed black pixel. */
const ART: ClipartArt = {
	width: 100,
	height: 200,
	parts: [
		{
			label: 'Låg',
			box: { x: 0, y: 0, width: 100, height: 200 },
			stroke: '#101010',
			scale: 1,
			paint: { type: 'solid', color: '#ffffff' },
			gradient: {
				type: 'gradient',
				from: '#65b6f5',
				to: '#f1a90c',
				angle: 45,
			},
		},
	],
	steps: [
		{
			type: 'image',
			rect: { x: 0, y: 0, width: 2, height: 1 },
			image: { x: 0, y: 0 },
		},
	],
};

/** The atlas: the fixed image's two pixels, one see-through. */
const ATLAS = {
	width: 2,
	height: 1,
	data: new Uint8ClampedArray( [ 0, 0, 0, 255, 200, 0, 0, 10 ] ),
};

const threads = [
	{ code: '1801', name: 'White', hex: '#ffffff' },
	{ code: '1800', name: 'Black', hex: '#000000' },
];

const BASES: BaseInfo[] = [
	{
		id: 4,
		name: 'Drikkedunk',
		views: [
			// 300 × 400 at 300 dpi: locked it is scaled by 2, so 150 dpi.
			{ name: 'Front', width: 300, height: 400, dpi: 300, pack: null },
		],
	},
	{
		id: 9,
		name: 'Kasket',
		views: [
			{
				name: 'Front',
				width: 100,
				height: 100,
				dpi: 300,
				pack: { max: 1, threads },
			},
		],
	},
];

describe( 'the colours of a clipart', () => {
	it( 'start with the file’s paints and gradients', () => {
		expect( startColors( ART ) ).toEqual( [
			'#ffffff',
			'#65b6f5',
			'#f1a90c',
		] );
	} );

	it( 'make a palette hold the file’s own colours', () => {
		expect( paletteOf( '#FF0000, #ffffff\n#00ff00', ART ) ).toEqual( [
			'#ffffff',
			'#65b6f5',
			'#f1a90c',
			'#ff0000',
			'#00ff00',
		] );
		expect( paletteOf( 'red', ART ) ).toBeNull();
	} );

	it( 'that never change are the strokes and the solid fixed pixels', () => {
		expect( fixedColors( ART, ATLAS ) ).toEqual( [ '#101010', '#000000' ] );
	} );
} );

describe( 'the definition', () => {
	it( 'leaves the atlas’ file to the server', () => {
		const made = definitionOf(
			ART,
			{ width: 2, height: 1 },
			{ bases: [ 4 ], palette: null, locked: true }
		);

		expect( made ).toEqual( {
			bases: [ 4 ],
			locked: true,
			parts: ART.parts,
			steps: ART.steps,
			atlas: { width: 2, height: 1 },
			palette: null,
		} );
	} );
} );

describe( 'the warnings before saving', () => {
	it( 'tell where a locked clipart prints under the dpi', () => {
		const warnings = clipartWarnings( ART, [], BASES, {
			bases: [ 4 ],
			palette: null,
			locked: true,
		} );

		expect( warnings ).toEqual( [
			'Drikkedunk – Front: the clipart prints at about 150 dpi, under the side’s 300 dpi.',
		] );
	} );

	it( 'tell where a locked clipart only fits the width', () => {
		const wide = { ...ART, width: 400, height: 100 };

		expect(
			clipartWarnings( wide, [], BASES, {
				bases: [ 4 ],
				palette: null,
				locked: true,
			} )[ 0 ]
		).toContain( 'fits the width' );
	} );

	it( 'tell the threads embroidery locks, and when they are too many', () => {
		const warnings = clipartWarnings(
			{ ...ART, width: 1000, height: 1000 },
			[ '#101010', '#fafafa' ],
			BASES,
			{ bases: [ 9 ], palette: null, locked: false }
		);

		expect( warnings ).toEqual( [
			'Kasket – Front is embroidered: the stroke and the fixed parts use 1800 Black, 1801 White, which cannot be swapped.',
			'Kasket – Front: the clipart needs more than 1 threads and cannot be sent there.',
		] );
	} );

	it( 'cover every product without a choice', () => {
		expect(
			clipartWarnings( ART, [ '#101010' ], BASES, {
				bases: null,
				palette: null,
				locked: false,
			} )
		).toHaveLength( 2 );
	} );
} );

describe( 'the comparison with the PSD', () => {
	it( 'lets rounding pass and stops what was left out', () => {
		expect( differsTooMuch( null ) ).toBe( false );
		expect( differsTooMuch( { mean: 0.3, seen: 0.00004 } ) ).toBe( false );
		expect( differsTooMuch( { mean: 4, seen: 0 } ) ).toBe( true );
		expect( differsTooMuch( { mean: 0.5, seen: 0.01 } ) ).toBe( true );
	} );

	it( 'names the layer of a refused PSD', () => {
		expect( clipartError( new ClipartError( 'mask', 'Lag 3' ) ) ).toContain(
			'"Lag 3"'
		);
		expect( clipartError( new ClipartError( 'no_parts' ) ) ).toContain(
			'farvet lag'
		);
	} );
} );
