/**
 * Tests for colour designs in the editor (ADR-096): the choice of a colour's
 * design, the merge dialog and the files a submission sends.
 */
import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';
import type { ReactElement } from 'react';
import { createRoot } from 'react-dom/client';

import type { DesignerBase, DesignerView } from '../api/designer';
import {
	ColorDesignPicker,
	MergeDesigns,
	NEW_DESIGN,
} from '../sections/designer/ColorDesigns';
import { ProductPanel } from '../sections/designer/DesignerEditor';
import { newShape, SHARED } from '../sections/designer/design-model';
import type { DesignDocument } from '../sections/designer/design-model';
import { submissionSize } from '../sections/designer/submission';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const RECT = { ...newShape( 'rect', 1000, 1000 ), id: 'r1' };

const DESIGN: DesignDocument = {
	version: 1,
	sides: { front: [ RECT ] },
	designs: [
		{
			id: 'd1',
			colors: [ 'red' ],
			sides: { front: [ RECT ], back: [ RECT ] },
		},
	],
};

const BASE = {
	name: 'Tee',
	sizes: [ 'M' ],
	stitches: [ 'white' ],
	colors: [
		{ key: 'black', name: 'Black', hex: '#111111' },
		{ key: 'sand', name: 'Sand', hex: '#d8c8a8' },
		{ key: 'red', name: 'Red', hex: '#ff0000' },
	],
} as unknown as DesignerBase;

/**
 * Renders an element.
 *
 * @param element The element.
 */
async function show( element: ReactElement ): Promise< void > {
	const container = document.createElement( 'div' );

	document.body.append( container );

	await act( async () => {
		createRoot( container ).render( element );
		await Promise.resolve();
	} );
}

/**
 * A button by its text.
 *
 * @param text The text.
 */
function button( text: string ): HTMLButtonElement | undefined {
	return Array.from(
		document.querySelectorAll< HTMLButtonElement >( 'button' )
	).find( ( candidate ) => candidate.textContent === text );
}

describe( 'colour designs in the editor', () => {
	afterEach( () => {
		document.body.replaceChildren();
	} );

	it( 'names each colour’s design and offers a new one', async () => {
		const onChoose = jest.fn();

		await show(
			<ColorDesignPicker
				design={ DESIGN }
				color="red"
				colorName="Red"
				canAdd
				onChoose={ onChoose }
			/>
		);

		const select = document.querySelector( 'select' )!;

		expect( select.value ).toBe( 'd1' );
		expect(
			Array.from( select.options ).map( ( option ) => option.textContent )
		).toEqual( [ 'Shared design', 'Design 2', '+ New design' ] );

		await act( async () => {
			select.value = SHARED;
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			await Promise.resolve();
		} );

		expect( onChoose ).toHaveBeenCalledWith( SHARED );
	} );

	it( 'offers no new design when there are eight', async () => {
		await show(
			<ColorDesignPicker
				design={ DESIGN }
				color="black"
				colorName="Black"
				canAdd={ false }
				onChoose={ jest.fn() }
			/>
		);

		expect(
			Array.from( document.querySelectorAll( 'option' ) ).map(
				( option ) => option.value
			)
		).not.toContain( NEW_DESIGN );
	} );

	it( 'shows the choice per colour in the product panel', async () => {
		const onDesign = jest.fn();

		await show(
			<ProductPanel
				base={ BASE }
				locale="da-DK"
				creator="Nova"
				title=""
				colors={ [ 'black', 'red' ] }
				sizes={ [ 'M' ] }
				onTitle={ jest.fn() }
				onColors={ jest.fn() }
				onSizes={ jest.fn() }
				stitch="white"
				onStitch={ jest.fn() }
				design={ DESIGN }
				onDesign={ onDesign }
			/>
		);

		const picker = document.querySelector< HTMLSelectElement >(
			'[aria-label="Design of Black"]'
		)!;

		expect( picker.value ).toBe( SHARED );

		await act( async () => {
			picker.value = NEW_DESIGN;
			picker.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			await Promise.resolve();
		} );

		expect( onDesign ).toHaveBeenCalledWith( 'black', NEW_DESIGN );
	} );

	it( 'lets the creator choose the product image', async () => {
		const onImage = jest.fn();

		await show(
			<ProductPanel
				base={ BASE }
				locale="da-DK"
				creator="Nova"
				title=""
				colors={ [ 'black', 'red' ] }
				sizes={ [ 'M' ] }
				onTitle={ jest.fn() }
				onColors={ jest.fn() }
				onSizes={ jest.fn() }
				stitch="white"
				onStitch={ jest.fn() }
				images={ [
					{ value: 'front:black', label: 'Black – Front' },
					{ value: 'back:red', label: 'Red – Back' },
				] }
				image="back:red"
				onImage={ onImage }
			/>
		);

		const select = Array.from(
			document.querySelectorAll< HTMLSelectElement >( 'select' )
		).find( ( candidate ) => candidate.value === 'back:red' )!;

		expect(
			Array.from( select.options ).map( ( option ) => option.textContent )
		).toEqual( [
			'The first colour, first side',
			'Black – Front',
			'Red – Back',
		] );

		await act( async () => {
			select.value = '';
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			await Promise.resolve();
		} );

		expect( onImage ).toHaveBeenCalledWith( null );
	} );

	it( 'shows the product image and a gallery to choose from', async () => {
		const onImage = jest.fn();
		const onGallery = jest.fn();
		const panel = ( gallery: Record< string, string > | null ) => (
			<ProductPanel
				base={ BASE }
				locale="da-DK"
				creator="Nova"
				title=""
				colors={ [ 'black', 'red' ] }
				sizes={ [ 'M' ] }
				onTitle={ jest.fn() }
				onColors={ jest.fn() }
				onSizes={ jest.fn() }
				stitch="white"
				onStitch={ jest.fn() }
				images={ [
					{ value: 'front:black', label: 'Black – Front' },
					{ value: 'back:red', label: 'Red – Back' },
				] }
				image={ null }
				onImage={ onImage }
				imagePreview="data:image/png;base64,AAAA"
				gallery={ gallery }
				galleryBusy={ false }
				onGallery={ onGallery }
			/>
		);

		await show( panel( null ) );

		expect(
			document
				.querySelector(
					'.merchboard-product-designer__image-preview > img'
				)
				?.getAttribute( 'src' )
		).toBe( 'data:image/png;base64,AAAA' );

		await act( async () => {
			button( 'Show all images' )?.click();
			await Promise.resolve();
		} );
		expect( onGallery ).toHaveBeenCalled();

		document.body.replaceChildren();
		await show(
			panel( {
				'front:black': 'data:image/png;base64,BBBB',
				'back:red': 'data:image/png;base64,CCCC',
			} )
		);

		const tiles = Array.from(
			document.querySelectorAll< HTMLButtonElement >(
				'.merchboard-product-designer__image-gallery button'
			)
		);

		expect(
			tiles.map( ( tile ) => tile.getAttribute( 'aria-pressed' ) )
		).toEqual( [ 'true', 'false' ] );

		await act( async () => {
			tiles[ 1 ]?.click();
			await Promise.resolve();
		} );
		expect( onImage ).toHaveBeenCalledWith( 'back:red' );
	} );

	it( 'asks how two designs are merged', async () => {
		const onMerge = jest.fn();

		await show(
			<MergeDesigns
				colorName="Red"
				target={ SHARED }
				design={ DESIGN }
				onMerge={ onMerge }
				onClose={ jest.fn() }
			/>
		);

		await act( async () => {
			button( 'Use the design of Red' )?.click();
			await Promise.resolve();
		} );
		expect( onMerge ).toHaveBeenLastCalledWith( 'mine' );

		await act( async () => {
			button( 'Put them together' )?.click();
			await Promise.resolve();
		} );
		expect( onMerge ).toHaveBeenLastCalledWith( 'both' );

		await act( async () => {
			button( 'Use Shared design' )?.click();
			await Promise.resolve();
		} );
		expect( onMerge ).toHaveBeenLastCalledWith( 'target' );
	} );

	it( 'sends the files of every design its colours use', () => {
		const views = [
			{ key: 'front', embroidery: null, scene: null },
			{ key: 'back', embroidery: null, scene: null },
		] as unknown as DesignerView[];

		// Shared: front for black and sand (1 print + 2 mockups). Red: front and back (2 prints + 2 mockups).
		expect(
			submissionSize( DESIGN, [ 'black', 'sand', 'red' ], views )
		).toBe( 7 );
		expect( submissionSize( DESIGN, [ 'red' ], views ) ).toBe( 4 );
	} );
} );
