/**
 * Tests for the views under a print around the product (step 57.19): the
 * drawings as in a print editor, and photos with their names.
 */
import { afterEach, describe, expect, it, jest } from '@jest/globals';

import type { DesignerGuide, DesignerView } from '../api/designer';
import { GuideViews } from '../sections/designer/GuideViews';
import { render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const BOX = { x: 0.06, y: 0.28, width: 0.88, height: 0.68 };

/**
 * A bottle's side with the given guides.
 *
 * @param guides The guides.
 */
function side( guides: DesignerGuide[] ): DesignerView {
	return {
		key: 'front',
		name: 'Front',
		width: 1000,
		height: 1000,
		layers: { background: 7 },
		area: { x: 30, y: 50, width: 940, height: 750 },
		printWidth: 2534,
		printHeight: 2067,
		dpi: 300,
		flat: false,
		sizes: [],
		colors: [],
		trims: {},
		format: false,
		small: false,
		scene: null,
		embroidery: null,
		guides,
	} as unknown as DesignerView;
}

const images = new Map( [
	[ 40, 'data:image/png;base64,AA==' ],
	[ 42, 'data:image/png;base64,AB==' ],
	[ 50, 'data:image/png;base64,AC==' ],
] );

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

describe( 'the views under a print around the product', () => {
	it( 'shows drawings framed, the seam view at both ends, names hidden', () => {
		rendered = render(
			<GuideViews
				view={ side( [
					{ x: 0, label: 'Back', figure: 42, box: BOX, seam: 0.494 },
					{
						x: 0.497,
						label: 'Front',
						figure: 40,
						box: BOX,
						seam: null,
					},
				] ) }
				images={ images }
				style={ { left: 0, top: 0, width: 600 } }
				room={ 200 }
			/>
		);

		const items = [ ...rendered.container.querySelectorAll( 'li' ) ];

		expect( items.map( ( item ) => item.title ) ).toEqual( [
			'Back',
			'Front',
			'Back',
		] );
		expect( items.map( ( item ) => item.style.left ) ).toEqual( [
			'0%',
			'49.7%',
			'100%',
		] );
		expect( items[ 0 ]?.style.transform ).toBe( 'translateX(-49.4%)' );
		expect(
			items.every( ( item ) => item.className === 'is-drawing' )
		).toBe( true );
		// At most 88 px high, as the room allows.
		expect( items[ 1 ]?.querySelector( 'img' )?.style.height ).toBe(
			'88px'
		);
		expect(
			items.map(
				( item ) =>
					(
						item.querySelector(
							'.merchboard-product-designer__seen'
						) as HTMLElement | null
					 )?.style.width
			)
		).toEqual( [ '44.6%', '88%', '43.4%' ] );
		expect(
			items.every(
				( item ) =>
					item.querySelector( '.merchboard-visually-hidden' )
						?.textContent === item.title
			)
		).toBe( true );
	} );

	it( 'keeps a photo with its name and fits drawings in little room', () => {
		rendered = render(
			<GuideViews
				view={ side( [
					{
						x: 0.5,
						label: 'Front',
						figure: 50,
						box: null,
						seam: null,
					},
					{
						x: 0.75,
						label: 'Right side',
						figure: 42,
						box: BOX,
						seam: null,
					},
				] ) }
				images={ images }
				style={ {} }
				room={ 50 }
			/>
		);

		const [ photo, drawing ] = [
			...rendered.container.querySelectorAll( 'li' ),
		];

		expect( photo?.className ).toBe( '' );
		expect( photo?.querySelector( 'img' )?.style.height ).toBe( '' );
		expect( photo?.querySelector( 'span:last-child' )?.className ).toBe(
			''
		);
		expect( photo?.textContent ).toBe( 'Front' );
		expect( drawing?.querySelector( 'img' )?.style.height ).toBe( '38px' );
	} );
} );
