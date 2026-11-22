/**
 * Tests for a poster's sizes listed by format (ADR-118).
 */
import { describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { ProductPanel } from '../sections/designer/DesignerEditor';
import type { DesignerBase } from '../api/designer';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const format = (
	key: string,
	color: string,
	sizes: string[],
	printWidth: number,
	printHeight: number
) => ( {
	key,
	name: key,
	colors: [ color ],
	sizes,
	printWidth,
	printHeight,
	format: true,
} );

const BASE = {
	name: 'Poster',
	sizes: [ 'A3', '30x30', 'A2', 'Extra' ],
	stitches: [],
	colors: [
		{ key: 'lodret', name: 'Lodret', hex: '#ffffff' },
		{ key: 'vandret', name: 'Vandret', hex: '#ffffff' },
	],
	views: [
		{
			...format(
				'lodret-format-1',
				'lodret',
				[ 'A3', 'A2' ],
				2528,
				3555
			),
			// A2 with 4 mm bleed: 428 × 602 mm printed, 420 × 594 mm finished.
			trims: {
				A2: {
					x: 4 / 428,
					y: 4 / 602,
					width: 420 / 428,
					height: 594 / 602,
				},
				A3: { x: 0.15, y: 0.15, width: 0.7, height: 0.7 },
			},
		},
		format( 'lodret-format-2', 'lodret', [ '30x30' ], 1819, 1819 ),
		{
			...format(
				'vandret-format-1',
				'vandret',
				[ 'A3', 'A2' ],
				3555,
				2528
			),
			trims: {
				A2: {
					x: 4 / 602,
					y: 4 / 428,
					width: 594 / 602,
					height: 420 / 428,
				},
			},
		},
		format( 'vandret-format-2', 'vandret', [ '30x30' ], 1819, 1819 ),
	],
} as unknown as DesignerBase;

describe( 'the sizes of a poster', () => {
	it( 'are listed by format with its aspect ratio and no orientation', async () => {
		const container = document.createElement( 'div' );
		const onSizes = jest.fn();

		document.body.append( container );

		await act( async () => {
			createRoot( container ).render(
				<ProductPanel
					base={ BASE }
					locale="da-DK"
					creator="Nova"
					title=""
					colors={ [ 'vandret' ] }
					sizes={ [ 'A3' ] }
					onTitle={ jest.fn() }
					onColors={ jest.fn() }
					onSizes={ onSizes }
					stitch=""
					onStitch={ jest.fn() }
					laterChoices={ false }
				/>
			);
			await Promise.resolve();
		} );

		const groups = Array.from(
			container.querySelectorAll(
				'.merchboard-product-designer__size-group'
			)
		);

		expect(
			groups.map(
				( group ) => group.querySelector( 'strong' )?.textContent
			)
		).toEqual( [ 'Format 1 · 1:√2', 'Format 2 · 1:1' ] );
		expect( container.textContent ).toContain( 'Extra' );
		expect( container.textContent ).not.toContain( 'vandret-format' );

		// "Format 1" chooses all its sizes, in the base product's order.
		const whole = groups[ 0 ]?.querySelector( 'input' ) as HTMLInputElement;

		expect( whole.indeterminate ).toBe( true );

		await act( async () => {
			whole.click();
			await Promise.resolve();
		} );

		expect( onSizes ).toHaveBeenCalledWith( [ 'A3', 'A2' ] );
	} );
} );
