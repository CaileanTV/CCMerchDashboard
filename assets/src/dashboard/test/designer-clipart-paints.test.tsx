/**
 * Tests for choosing the paints of a clipart's parts (phase 56, step 56.11).
 */
import { describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { ClipartPaints } from '../sections/designer/ClipartPaints';
import type { ClipartPaintsProps } from '../sections/designer/ClipartPaints';
import type {
	ClipartArt,
	ClipartPaint,
} from '../sections/designer/clipart-model';
import type { DesignObject } from '../sections/designer/design-model';
import { LayerList } from '../sections/designer/LayerList';

(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

const WHITE: ClipartPaint = { type: 'solid', color: '#ffffff' };
const FILE_GRADIENT: ClipartPaint = {
	type: 'gradient',
	from: '#aaccff',
	to: '#ff8800',
	angle: 90,
};

const part = ( label: string ) => ( {
	label,
	box: { x: 0, y: 0, width: 4, height: 2 },
	stroke: '#000000',
	scale: 1,
	paint: WHITE,
	gradient: FILE_GRADIENT as Extract< ClipartPaint, { type: 'gradient' } >,
} );

const ART: ClipartArt = {
	width: 4,
	height: 2,
	parts: [ part( 'Låg' ), part( '' ) ],
	steps: [],
};

/**
 * Renders the paints.
 *
 * @param props Props to change.
 */
function render( props: Partial< ClipartPaintsProps > = {} ) {
	const onChange = jest.fn< ClipartPaintsProps[ 'onChange' ] >();
	const onBegin = jest.fn();
	const onEnd = jest.fn();
	const container = document.createElement( 'div' );
	document.body.append( container );
	const root = createRoot( container );

	act( () => {
		root.render(
			<ClipartPaints
				art={ ART }
				palette={ null }
				paints={ [ WHITE, WHITE ] }
				gradients
				onChange={ onChange }
				onBegin={ onBegin }
				onEnd={ onEnd }
				{ ...props }
			/>
		);
	} );

	return { container, onChange, onBegin, onEnd, root };
}

/**
 * Sets a form field's value as the browser does.
 *
 * @param field The field.
 * @param value The value.
 */
function type( field: HTMLInputElement | HTMLSelectElement, value: string ) {
	const setter = Object.getOwnPropertyDescriptor(
		Object.getPrototypeOf( field ) as object,
		'value'
	)?.set;

	act( () => {
		setter?.call( field, value );
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
}

describe( 'the paints of a clipart', () => {
	it( 'names each part by its layer, or by its number', () => {
		const { container } = render();
		const legends = Array.from( container.querySelectorAll( 'legend' ) );

		expect( legends.map( ( legend ) => legend.textContent ) ).toEqual( [
			'Låg',
			'Part 2',
		] );
	} );

	it( 'starts a gradient as the file’s', () => {
		const { container, onChange } = render();
		const kind = container.querySelector( 'select' )!;

		type( kind, 'gradient' );

		expect( onChange ).toHaveBeenLastCalledWith(
			[ FILE_GRADIENT, WHITE ],
			undefined
		);
	} );

	it( 'makes dragging in a colour field one undo step', () => {
		const { container, onChange, onBegin, onEnd } = render();
		const field = container.querySelector< HTMLInputElement >(
			'input[type="color"]'
		)!;

		act( () => field.focus() );
		type( field, '#123456' );
		act( () => field.blur() );

		expect( onBegin ).toHaveBeenCalledTimes( 1 );
		expect( onChange ).toHaveBeenLastCalledWith(
			[ { type: 'solid', color: '#123456' }, WHITE ],
			true
		);
		expect( onEnd ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'offers only the palette when the clipart has one', () => {
		const { container, onChange } = render( {
			palette: [ '#ffffff', '#ff0000' ],
		} );

		expect( container.querySelector( 'input[type="color"]' ) ).toBeNull();

		const red = container.querySelector< HTMLButtonElement >(
			'button[aria-label="#ff0000"]'
		)!;
		act( () => red.click() );

		expect( onChange ).toHaveBeenLastCalledWith(
			[ { type: 'solid', color: '#ff0000' }, WHITE ],
			undefined
		);
	} );

	it( 'offers no gradient on embroidery and shows one as its first colour', () => {
		const { container } = render( {
			gradients: false,
			paints: [ FILE_GRADIENT, WHITE ],
		} );

		expect( container.querySelector( 'select' ) ).toBeNull();
		expect(
			container.querySelector< HTMLInputElement >( 'input[type="color"]' )
				?.value
		).toBe( '#ffffff' );
	} );

	it( 'keeps the angle between 0 and 359', () => {
		const { container, onChange } = render( {
			paints: [ FILE_GRADIENT, WHITE ],
		} );
		const angle = container.querySelector< HTMLInputElement >(
			'input[type="number"]'
		)!;

		type( angle, '360' );

		expect( onChange ).toHaveBeenLastCalledWith(
			[ { ...FILE_GRADIENT, angle: 0 }, WHITE ],
			true
		);
	} );
} );

describe( 'a clipart in the layers', () => {
	it( 'is named after its clipart', () => {
		const object: DesignObject = {
			type: 'image',
			id: 'a',
			asset: 7,
			x: 0,
			y: 0,
			width: 4,
			height: 2,
			angle: 0,
			scaleX: 1,
			scaleY: 1,
			flipX: false,
			flipY: false,
			opacity: 1,
			locked: false,
			hidden: false,
		};
		const container = document.createElement( 'div' );
		const root = createRoot( container );

		act( () => {
			root.render(
				<LayerList
					objects={ [ object, { ...object, id: 'b', asset: 8 } ] }
					selected={ null }
					onSelect={ () => undefined }
					onChange={ () => undefined }
					onMove={ () => undefined }
					onDuplicate={ () => undefined }
					onRemove={ () => undefined }
					imageName={ ( asset ) =>
						asset === 7 ? 'Drikkedunk' : undefined
					}
				/>
			);
		} );

		expect(
			Array.from(
				container.querySelectorAll(
					'.merchboard-product-designer__layer-name span'
				)
			).map( ( name ) => name.textContent )
		).toEqual( [ 'Image', 'Drikkedunk' ] );
	} );
} );
