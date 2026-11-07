/**
 * Tests for drawing a print area on a template.
 */
import { describe, expect, it, jest } from '@jest/globals';

import { drawnArea, isInside, movedArea } from '../area-picker';
import {
	attachSideOrder,
	attachSizeOrder,
	movedSize,
	sizesOf,
} from '../base-products-admin';

jest.mock( '../psd-import', () => ( { importPsd: jest.fn() } ) );

describe( 'the sizes with a price', () => {
	it( 'are the sizes of the field, each once', () => {
		expect( sizesOf( 'S, M\nL,, M ' ) ).toEqual( [ 'S', 'M', 'L' ] );
		expect( sizesOf( '  ' ) ).toEqual( [] );
	} );
} );

describe( 'the order of the sizes', () => {
	const sizes = [ '33 cl', '44 cl', '55 cl' ];

	it( 'moves one size a place, up or down', () => {
		expect( movedSize( sizes, 1, -1 ) ).toEqual( [
			'44 cl',
			'33 cl',
			'55 cl',
		] );
		expect( movedSize( sizes, 1, 1 ) ).toEqual( [
			'33 cl',
			'55 cl',
			'44 cl',
		] );
		expect( sizes ).toEqual( [ '33 cl', '44 cl', '55 cl' ] );
	} );
} );

describe( 'the arrows of the sizes', () => {
	const setup = () => {
		const holder = document.createElement( 'div' );
		const field = document.createElement( 'input' );

		field.value = 'S, M, L';
		document.body.append( field, holder );
		attachSizeOrder( holder, field );

		return { holder, field };
	};
	const arrow = ( holder: HTMLElement, move: string ) =>
		holder.querySelector< HTMLButtonElement >(
			`[data-move="${ move }"]`
		) as HTMLButtonElement;

	it( 'writes the sizes field and tells the other fields', () => {
		const { holder, field } = setup();
		const changes: string[] = [];

		field.addEventListener( 'input', () => changes.push( field.value ) );
		arrow( holder, 'M:1' ).click();

		expect( field.value ).toBe( 'S, L, M' );
		expect( changes ).toEqual( [ 'S, L, M' ] );
		expect(
			Array.from( holder.querySelectorAll( 'p' ) ).map( ( row ) =>
				row.textContent?.trim()
			)
		).toEqual( [ '↑ ↓ S', '↑ ↓ L', '↑ ↓ M' ] );
	} );

	it( 'cannot move past the ends and keeps the keyboard on an arrow', () => {
		const { holder } = setup();

		expect( arrow( holder, 'S:-1' ).disabled ).toBe( true );
		expect( arrow( holder, 'L:1' ).disabled ).toBe( true );

		arrow( holder, 'S:1' ).click();
		expect( holder.ownerDocument.activeElement ).toBe(
			arrow( holder, 'S:1' )
		);

		arrow( holder, 'S:1' ).click();
		expect( arrow( holder, 'S:1' ).disabled ).toBe( true );
		expect(
			( holder.ownerDocument.activeElement as HTMLElement ).tagName
		).toBe( 'BUTTON' );
	} );

	it( 'follows what is typed and hides with a single size', () => {
		const { holder, field } = setup();

		field.value = 'One size';
		field.dispatchEvent( new Event( 'input' ) );

		expect( holder.hidden ).toBe( true );
	} );
} );

describe( 'the arrows of the sides', () => {
	const setup = () => {
		const form = document.createElement( 'form' );

		form.innerHTML = [ '44cl', '33cl' ]
			.map(
				( name ) =>
					`<fieldset data-merchboard-side="${ name }"><legend>${ name }</legend></fieldset>`
			)
			.join( '' );
		form.insertAdjacentHTML(
			'beforeend',
			'<fieldset><legend>New side</legend></fieldset>'
		);
		document.body.append( form );
		attachSideOrder( form );

		return form;
	};
	const order = ( form: HTMLElement ) =>
		Array.from( form.querySelectorAll( 'legend' ) ).map(
			( legend ) => legend.textContent
		);
	const arrow = ( form: HTMLElement, label: string ) =>
		form.querySelector< HTMLButtonElement >(
			`[aria-label="${ label }"]`
		) as HTMLButtonElement;

	it( 'moves a side in the form, and a new side stays last', () => {
		const form = setup();

		arrow( form, 'Move 33cl up' ).click();

		expect( order( form ) ).toEqual( [ '33cl', '44cl', 'New side' ] );
	} );

	it( 'cannot move past the ends and keeps the keyboard on an arrow', () => {
		const form = setup();

		expect( arrow( form, 'Move 44cl up' ).disabled ).toBe( true );
		expect( arrow( form, 'Move 33cl down' ).disabled ).toBe( true );

		arrow( form, 'Move 33cl up' ).click();

		expect( arrow( form, 'Move 33cl up' ).disabled ).toBe( true );
		expect( arrow( form, 'Move 33cl down' ).disabled ).toBe( false );
		expect( form.ownerDocument.activeElement ).toBe(
			arrow( form, 'Move 33cl down' )
		);
	} );

	it( 'shows no arrows on a single side', () => {
		const form = document.createElement( 'form' );

		form.innerHTML =
			'<fieldset data-merchboard-side="Front"><legend>Front</legend></fieldset>';
		attachSideOrder( form );

		expect( form.querySelector( 'button' ) ).toBeNull();
	} );
} );

describe( 'the print area on the template', () => {
	it( 'is the box of a drag, whichever way it goes', () => {
		const area = { x: 10, y: 20, width: 30, height: 40 };

		expect(
			drawnArea( { x: 10, y: 20 }, { x: 40, y: 60 }, 100, 100 )
		).toEqual( area );
		expect(
			drawnArea( { x: 40, y: 60 }, { x: 10, y: 20 }, 100, 100 )
		).toEqual( area );
	} );

	it( 'stays inside the template and in whole pixels', () => {
		expect(
			drawnArea( { x: -5, y: 10.4 }, { x: 140, y: 50.6 }, 100, 100 )
		).toEqual( { x: 0, y: 10, width: 100, height: 41 } );
	} );

	it( 'moves without leaving the template', () => {
		const area = { x: 10, y: 10, width: 30, height: 40 };

		expect( movedArea( area, 5.4, -3, 100, 100 ) ).toEqual( {
			...area,
			x: 15,
			y: 7,
		} );
		expect( movedArea( area, 500, 500, 100, 100 ) ).toEqual( {
			...area,
			x: 70,
			y: 60,
		} );
		expect( movedArea( area, -500, -500, 100, 100 ) ).toEqual( {
			...area,
			x: 0,
			y: 0,
		} );
	} );

	it( 'knows a drag that starts inside it', () => {
		const area = { x: 10, y: 10, width: 30, height: 40 };

		expect( isInside( area, { x: 20, y: 20 } ) ).toBe( true );
		expect( isInside( area, { x: 5, y: 20 } ) ).toBe( false );
	} );
} );
