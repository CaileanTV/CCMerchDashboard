/**
 * The threads of a design without coloured parts, number by number (phase
 * 56, step 56.4). Locked threads come in 56.12; these must not move, as the
 * digitization fee follows the print file's bytes (ADR-086).
 */
import { describe, expect, it } from '@jest/globals';

import type { DesignerEmbroidery } from '../api/designer';
import type { DesignObject } from '../sections/designer/design-model';
import { paintPixels, threadPlan } from '../sections/designer/threads';

const pack: DesignerEmbroidery = {
	max: 3,
	stitched: false,
	threads: [
		{ code: '1801', name: 'White', hex: '#ffffff' },
		{ code: '1800', name: 'Black', hex: '#000000' },
		{ code: '1838', name: 'Red', hex: '#cb333b' },
		{ code: '1934', name: 'Royal', hex: '#015498' },
		{ code: '1624', name: 'Yellow', hex: '#ffcd01' },
	],
};

const base = {
	x: 0,
	y: 0,
	angle: 0,
	scaleX: 1,
	scaleY: 1,
	flipX: false,
	flipY: false,
	opacity: 1,
	locked: false,
	hidden: false,
};

const objects: DesignObject[] = [
	{ ...base, id: 'image', type: 'image', asset: 7, width: 200, height: 100 },
	{
		...base,
		id: 'red',
		type: 'rect',
		width: 100,
		height: 80,
		fill: '#d02020',
		stroke: '#101010',
		strokeWidth: 4,
		radius: 0,
	},
	{
		...base,
		id: 'yellow',
		type: 'rect',
		width: 10,
		height: 10,
		fill: '#ffd000',
		stroke: null,
		strokeWidth: 0,
		radius: 0,
	},
];

const images = new Map( [
	[
		7,
		new Map( [
			[ '#f8f8f8', 0.6 ],
			[ '#080808', 0.3 ],
			[ '#0050a0', 0.1 ],
		] ),
	],
] );

const colors = [ '#f8f8f8', '#080808', '#d02020', '#0050a0', '#ffd000' ];

describe( 'the threads of a design without coloured parts', () => {
	it( 'are the same threads, places and colours', () => {
		const plan = threadPlan( objects, images, {}, pack );

		expect( plan.signature ).toBe( '1801:1801,1838:1838,1800:1800' );
		expect( plan.threads.map( ( thread ) => thread.code ) ).toEqual( [
			'1801',
			'1838',
			'1800',
		] );
		expect( plan.merged ).toBe( 2 );
		expect( colors.map( plan.colorOf ) ).toEqual( [
			'#ffffff',
			'#000000',
			'#cb333b',
			'#cb333b',
			'#ffffff',
		] );
	} );

	it( 'are the same with threads swapped', () => {
		const plan = threadPlan( objects, images, { '1800': '1934' }, pack );

		expect( plan.signature ).toBe( '1801:1801,1838:1838,1800:1934' );
		expect( plan.threads.map( ( thread ) => thread.code ) ).toEqual( [
			'1801',
			'1838',
			'1934',
		] );
		expect( colors.map( plan.colorOf ) ).toEqual( [
			'#ffffff',
			'#015498',
			'#cb333b',
			'#cb333b',
			'#ffffff',
		] );
	} );

	it( 'paint pixels the same', () => {
		const plan = threadPlan( objects, images, {}, pack );
		const data = new Uint8ClampedArray( [
			248, 248, 248, 255, 8, 8, 8, 128, 208, 32, 32, 127,
		] );

		paintPixels( data, plan.colorOf );

		expect( Array.from( data ) ).toEqual( [
			255, 255, 255, 255, 0, 0, 0, 255, 0, 0, 0, 0,
		] );
	} );
} );
