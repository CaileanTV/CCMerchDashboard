/**
 * Tests for the text fills (phase 25, step 6.10).
 */
import { describe, expect, it } from '@jest/globals';

import { renderText, DEFAULT_ANIMATION } from '../animations';
import {
	DEFAULT_FILL,
	PRESET_IMAGES,
	applyFill,
	clearFill,
	cssUrl,
	fillLook,
	parseFillLook,
	parseTextFill,
} from '../textfill';

const ORIGIN = 'https://shop.example';

describe( 'text fills', () => {
	it( 'reads stored fills and falls back to none', () => {
		expect( parseTextFill( null ) ).toEqual( DEFAULT_FILL );
		expect(
			parseTextFill( {
				type: 'image',
				preset: 'lava',
				image: 12,
				motion: 'fall',
			} )
		).toEqual( {
			type: 'image',
			preset: 'rainbow',
			image: 12,
			motion: 'fall',
		} );
		expect( parseTextFill( { type: 'x', image: -3 } ) ).toEqual(
			DEFAULT_FILL
		);
	} );

	it( 'accepts presets and images of this site only', () => {
		expect(
			parseFillLook(
				{ kind: 'preset', preset: 'gold', motion: 'pan' },
				ORIGIN
			)
		).toEqual( { kind: 'preset', preset: 'gold', motion: 'pan' } );
		expect(
			parseFillLook(
				{
					kind: 'image',
					url: `${ ORIGIN }/wp-content/uploads/a.gif`,
					width: 40,
					height: 20,
					motion: 'nope',
				},
				ORIGIN
			)
		).toEqual( {
			kind: 'image',
			url: `${ ORIGIN }/wp-content/uploads/a.gif`,
			width: 40,
			height: 20,
			motion: 'none',
		} );

		for ( const bad of [
			null,
			{ kind: 'preset', preset: 'lava' },
			{
				kind: 'image',
				url: 'https://evil.example/a.png',
				width: 1,
				height: 1,
			},
			{ kind: 'image', url: 'javascript:alert(1)', width: 1, height: 1 },
			{ kind: 'image', url: `${ ORIGIN }/a.png`, width: 0, height: 1 },
		] ) {
			expect( parseFillLook( bad, ORIGIN ) ).toBeNull();
		}
	} );

	it( 'finds the image of a stored fill among the allowed images', () => {
		const images = [
			{ id: 7, url: `${ ORIGIN }/a.png`, width: 4, height: 2 },
		];

		expect(
			fillLook( { ...DEFAULT_FILL, type: 'image', image: 7 }, images )
		).toEqual( {
			kind: 'image',
			url: `${ ORIGIN }/a.png`,
			width: 4,
			height: 2,
			motion: 'none',
		} );
		expect(
			fillLook( { ...DEFAULT_FILL, type: 'image', image: 8 }, images )
		).toBeNull();
		expect( fillLook( DEFAULT_FILL, images ) ).toBeNull();
		expect(
			fillLook( { ...DEFAULT_FILL, type: 'preset', preset: 'ocean' }, [] )
		).toEqual( { kind: 'preset', preset: 'ocean', motion: 'none' } );
	} );

	it( 'escapes characters that could end a CSS url()', () => {
		expect( cssUrl( 'https://a.example/x").y(\'z 1.png' ) ).toBe(
			'url("https://a.example/x%22%29.y%28%27z%201.png")'
		);
	} );

	it( 'splits plain text into letters, fills them and cleans up', () => {
		const element = document.createElement( 'div' );
		element.textContent = 'Hi <b>!';

		applyFill( element, {
			kind: 'preset',
			preset: 'candy',
			motion: 'pan',
		} );

		const letters = element.querySelectorAll( '.merchboard-anim__letter' );
		expect( letters ).toHaveLength( 6 );
		expect( element.textContent ).toBe( 'Hi <b>!' );
		expect( element.querySelector( 'b' ) ).toBeNull();
		expect( element.classList.contains( 'merchboard-fill' ) ).toBe( true );
		expect( element.dataset.fillMotion ).toBe( 'pan' );
		expect(
			element.style.getPropertyValue( '--merchboard-fill-image' )
		).toBe( PRESET_IMAGES.candy );
		expect(
			( letters[ 0 ] as HTMLElement ).style.getPropertyValue( '--fx' )
		).toBe( '0px' );

		clearFill( element );
		expect( element.className ).toBe( '' );
		expect( element.dataset.fillMotion ).toBeUndefined();
		expect(
			element.style.getPropertyValue( '--merchboard-fill-image' )
		).toBe( '' );
	} );

	it( 'keeps the letters of a letter animation and fills images', () => {
		const element = document.createElement( 'div' );
		renderText(
			element,
			'Yo',
			{ ...DEFAULT_ANIMATION, in: 'typewriter' },
			500
		);

		applyFill( element, {
			kind: 'image',
			url: `${ ORIGIN }/a.png`,
			width: 40,
			height: 20,
			motion: 'none',
		} );

		expect(
			element.querySelectorAll( '.merchboard-anim__letter' )
		).toHaveLength( 2 );
		expect(
			element.querySelector( '.merchboard-anim__cursor' )
		).not.toBeNull();
		expect( element.dataset.fillWidth ).toBe( '40' );
		expect(
			element.style.getPropertyValue( '--merchboard-fill-image' )
		).toBe( `url("${ ORIGIN }/a.png")` );

		applyFill( element, null );
		expect( element.classList.contains( 'merchboard-fill' ) ).toBe( false );
		expect( element.dataset.fillWidth ).toBeUndefined();
	} );
} );
