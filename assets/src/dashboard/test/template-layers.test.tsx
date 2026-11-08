/**
 * Tests for the template around the design: the cutout (ADR-099).
 */
import { afterEach, describe, expect, it } from '@jest/globals';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

import type { DesignerView } from '../api/designer';
import { TemplateLayers } from '../sections/designer/TemplateLayers';

const VIEW = {
	key: 'front',
	name: 'Front',
	width: 1000,
	height: 1000,
	layers: { background: 1, shading: 2, cutout: 3 },
	backgrounds: {},
	flat: false,
} as unknown as DesignerView;

const IMAGES = new Map( [
	[ 1, 'data:image/png;base64,YmFja2dyb3VuZA==' ],
	[ 2, 'data:image/png;base64,c2hhZGluZw==' ],
	[ 3, 'data:image/png;base64,Y3V0b3V0' ],
] );

/**
 * Renders a part of the template.
 *
 * @param view The side.
 */
async function over( view: DesignerView ): Promise< HTMLElement > {
	const container = document.createElement( 'div' );

	document.body.append( container );

	await act( async () => {
		createRoot( container ).render(
			<TemplateLayers
				view={ view }
				images={ IMAGES }
				color="#111111"
				part="over"
			/>
		);
		await Promise.resolve();
	} );

	return container;
}

describe( 'the template over the design', () => {
	afterEach( () => {
		document.body.replaceChildren();
	} );

	it( 'draws the product again in front of the design where the cutout is', async () => {
		const container = await over( VIEW );
		const cutout = container.querySelector(
			'.merchboard-product-designer__cutout'
		);

		expect( cutout ).not.toBeNull();
		expect(
			Array.from( cutout?.querySelectorAll( 'img' ) ?? [] ).map(
				( image ) => image.getAttribute( 'src' )
			)
		).toEqual( [ IMAGES.get( 1 ) ] );
		// The shading still lies over everything.
		expect( container.lastElementChild?.getAttribute( 'src' ) ).toBe(
			IMAGES.get( 2 )
		);
	} );

	it( 'draws nothing in front without a cutout', async () => {
		const container = await over( {
			...VIEW,
			layers: { background: 1, shading: 2 },
		} );

		expect(
			container.querySelector( '.merchboard-product-designer__cutout' )
		).toBeNull();
	} );
} );
