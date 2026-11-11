/**
 * Tests for the alert renderer (ADR-088).
 */
import { describe, expect, it } from '@jest/globals';
import { arrange, parseLook, rgba, styleText } from '../render';
import type { AlertElements } from '../render';

function elements(): AlertElements {
	const box = document.createElement( 'div' );
	const image = document.createElement( 'div' );
	const texts = document.createElement( 'div' );
	const text = document.createElement( 'div' );
	const secondary = document.createElement( 'div' );

	texts.append( text, secondary );
	box.append( image, texts );

	return { alert: box, image, texts, text, secondary };
}

describe( 'parseLook', () => {
	it( 'gives the box around the elements and defaults to untrusted data', () => {
		const look = parseLook(
			{
				font_family: 'Roboto;}</style>',
				text_shadow: '1px 1px red;}',
				text_color: 'red',
				line_height: 9999,
				layout_mode: 'grid',
			},
			window.location.origin
		);

		expect( look.widget ).toEqual( {
			x: 40,
			y: 880,
			w: 836,
			h: 120,
			opacity: 100,
			rotation: 0,
		} );
		expect( look.font_family ).toBe( 'inherit' );
		expect( look.text_shadow ).toBe( '' );
		expect( look.text_color ).toBe( '#ffffff' );
		expect( look.line_height ).toBe( 300 );
		expect( look.layout_mode ).toBe( 'free' );
	} );
} );

describe( 'arrange', () => {
	it( 'places the free elements relative to the box', () => {
		const parts = elements();

		arrange(
			parts,
			parseLook(
				{
					widget: { x: 20, y: 860, w: 900, h: 200 },
					layout_mode: 'free',
				},
				window.location.origin
			)
		);

		expect( parts.alert.dataset.layout ).toBe( 'free' );
		expect( parts.alert.style.width ).toBe( '900px' );
		expect( parts.image.style.left ).toBe( '20px' );
		expect( parts.image.style.top ).toBe( '20px' );
		expect( parts.text.style.left ).toBe( '156px' );
		expect( parts.secondary.hidden ).toBe( true );
	} );

	it( 'lets a layout place the elements and a growing text grow up', () => {
		const side = elements();

		arrange(
			side,
			parseLook( { layout_mode: 'side' }, window.location.origin )
		);
		expect( side.alert.dataset.layout ).toBe( 'left' );
		expect( side.text.style.left ).toBe( '' );

		const grow = elements();

		arrange(
			grow,
			parseLook( { text_fit: 'grow' }, window.location.origin )
		);
		expect( grow.text.dataset.growsUp ).toBe( '1' );
		expect( grow.text.style.bottom ).toBe( '25px' );
		expect( grow.text.style.minHeight ).toBe( '80px' );
	} );
} );

describe( 'layouts (ADR-109)', () => {
	it( 'reads the names before ADR-109 as the layout that looks the same', () => {
		const mode = ( value: string ) =>
			parseLook( { layout_mode: value }, window.location.origin )
				.layout_mode;

		expect( mode( 'above' ) ).toBe( 'top' );
		expect( mode( 'side' ) ).toBe( 'left' );
		expect( mode( 'overlay' ) ).toBe( 'background' );
		expect( mode( 'right' ) ).toBe( 'right' );
		expect( mode( 'grid' ) ).toBe( 'free' );
	} );

	it( 'draws image and texts as one box, and only "free" as boxes of their own', () => {
		const right = elements();

		arrange(
			right,
			parseLook(
				{
					widget: { x: 100, y: 800, w: 600, h: 200 },
					layout_mode: 'right',
				},
				window.location.origin
			)
		);
		expect( right.alert.dataset.layout ).toBe( 'right' );
		expect( right.alert.style.width ).toBe( '600px' );
		expect( right.image.style.left ).toBe( '' );
		expect( right.text.style.left ).toBe( '' );
	} );
} );

describe( 'styleText', () => {
	it( 'sets the text style from the look', () => {
		const text = document.createElement( 'div' );

		styleText(
			text,
			parseLook(
				{
					font_family: 'Open Sans',
					text_color: '#102030',
					text_opacity: 50,
					letter_spacing: 3,
					italic: true,
					text_case: 'upper',
					stroke_width: 2,
					stroke_color: '#ff0000',
					margin: { top: -10 },
				},
				window.location.origin
			)
		);

		expect( text.style.fontFamily ).toBe( '"Open Sans", sans-serif' );
		expect( text.style.color ).toBe( 'rgba(16, 32, 48, 0.5)' );
		expect( text.style.letterSpacing ).toBe( '3px' );
		expect( text.style.fontStyle ).toBe( 'italic' );
		expect( text.style.textTransform ).toBe( 'uppercase' );
		expect( text.style.getPropertyValue( '-webkit-text-stroke' ) ).toBe(
			'2px rgba(255,0,0,1)'
		);
		expect( text.style.marginTop ).toBe( '-10px' );
		expect( text.style.getPropertyValue( '--merchboard-font-size' ) ).toBe(
			'32px'
		);
	} );

	it( 'writes colours with their opacity', () => {
		expect( rgba( '#ff8000', 45 ) ).toBe( 'rgba(255,128,0,0.45)' );
	} );
} );
