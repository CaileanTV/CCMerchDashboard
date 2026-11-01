/**
 * Colour photos of a side in batches.
 */
import {
	afterEach,
	beforeEach,
	describe,
	expect,
	it,
	jest,
} from '@jest/globals';

import {
	attachColorPhotos,
	batches,
	colorOfFile,
	medianHex,
	withColors,
} from '../color-photos';

const flush = async () => {
	for ( let round = 0; round < 20; round++ ) {
		await Promise.resolve();
	}
};

const photo = ( name: string ) =>
	new File( [ 'x' ], name, { type: 'image/png' } );

describe( 'naming and measuring', () => {
	it( 'takes the colour from the file name, in one form of letters', () => {
		expect( colorOfFile( 'Mørk grøn.png' ) ).toBe( 'Mørk grøn' );
		expect( colorOfFile( 'Himmelblå.PNG' ) ).toBe( 'Himmelblå' );
	} );

	it( 'adds only the colours the field lacks, whatever their case', () => {
		expect(
			withColors( 'Lime Grøn #00ff00\n\nSort, 111111\n', [
				{ name: 'lime grøn', hex: '#111111' },
				{ name: 'Sort', hex: '#222222' },
				{ name: 'Hvid', hex: '#eeeeee' },
			] )
		).toBe( 'Lime Grøn #00ff00\nSort, 111111\nHvid #eeeeee' );
		expect( withColors( '', [ { name: 'Hvid', hex: '#eeeeee' } ] ) ).toBe(
			'Hvid #eeeeee'
		);
	} );

	it( 'measures the solid pixels only', () => {
		const pixels = new Uint8ClampedArray( [
			255, 0, 0, 0, 10, 20, 30, 255, 10, 20, 30, 255, 200, 200, 200, 255,
		] );

		expect( medianHex( pixels ) ).toBe( '#0a141e' );
		expect( medianHex( new Uint8ClampedArray( 8 ) ) ).toBe( '#808080' );
	} );

	it( 'splits by files and by bytes, and a large file goes alone', () => {
		const sized = ( size: number ) => ( { size } );

		expect(
			batches( [ 1, 1, 1 ].map( sized ), 2, 100 ).map( ( b ) => b.length )
		).toEqual( [ 2, 1 ] );
		expect(
			batches( [ 60, 60, 500, 1 ].map( sized ), 9, 100 ).map(
				( b ) => b.length
			)
		).toEqual( [ 1, 1, 1, 1 ] );
		expect( batches( [], 2, 100 ) ).toEqual( [] );
	} );
} );

describe( 'the upload', () => {
	const SAVED = 'https://site.test/wp-admin/admin.php?base=7&result=saved';
	const original = {
		fetch: global.fetch,
		bitmap: global.createImageBitmap,
		data: global.DataTransfer,
		url: URL.createObjectURL,
		context: HTMLCanvasElement.prototype.getContext,
	};
	let requests: FormData[];
	let answers: string[];
	let navigate: jest.Mock;

	const fill = ( form: HTMLFormElement, name: string, value: string ) => {
		( form.elements.namedItem( name ) as HTMLInputElement ).value = value;
	};
	const setup = ( files: File[] ) => {
		const form = document.createElement( 'form' );

		form.setAttribute(
			'action',
			'https://site.test/wp-admin/admin-post.php'
		);
		form.dataset.photoFiles = '2';
		form.dataset.photoBytes = '1000000';
		form.dataset.resultArg = 'result';
		form.innerHTML = `
			<input name="action" value="merchboard_base_product">
			<input name="_wpnonce" value="nonce">
			<textarea name="colors"></textarea>
			<input name="views[0][key]" value="">
			<input name="views[0][name]">
			<input name="views[0][width]">
			<input name="views[0][height]">
			<input name="views[0][width_mm]">
			<input name="views[0][height_mm]">
			<input type="file" name="layer_0_background">
			<div data-merchboard-area="0" data-url=""></div>
			<input type="file" data-merchboard-photos="0">
			<span id="merchboard-photos-status-0"></span>
			<div id="merchboard-photos-preview-0"></div>
			<button type="submit">Save</button>`;
		document.body.append( form );

		const input = form.querySelector(
			'[data-merchboard-photos]'
		) as HTMLInputElement;

		Object.defineProperty( input, 'files', { value: files } );
		Object.defineProperty(
			form.elements.namedItem( 'layer_0_background' ),
			'files',
			{ writable: true, value: null }
		);
		attachColorPhotos( form, navigate );

		return { form, input };
	};
	const status = () =>
		document.getElementById( 'merchboard-photos-status-0' )?.textContent;

	beforeEach( () => {
		requests = [];
		answers = [ SAVED, SAVED, SAVED ];
		navigate = jest.fn();
		global.fetch = jest.fn( ( _url: unknown, init: RequestInit ) => {
			requests.push( init.body as FormData );

			return Promise.resolve( {
				url: answers.shift() ?? SAVED,
			} as Response );
		} ) as unknown as typeof fetch;
		global.createImageBitmap = jest.fn( () =>
			Promise.resolve( { width: 1000, height: 1000 } as ImageBitmap )
		);
		global.DataTransfer = jest.fn( () => ( {
			items: { add: jest.fn() },
			files: [],
		} ) ) as unknown as typeof DataTransfer;
		URL.createObjectURL = jest.fn( () => 'blob:photo' );
		HTMLCanvasElement.prototype.getContext = jest.fn( () => ( {
			drawImage: jest.fn(),
			getImageData: () => ( {
				data: new Uint8ClampedArray( [ 16, 32, 48, 255 ] ),
			} ),
		} ) ) as unknown as typeof HTMLCanvasElement.prototype.getContext;
	} );

	afterEach( () => {
		global.fetch = original.fetch;
		global.createImageBitmap = original.bitmap;
		global.DataTransfer = original.data;
		URL.createObjectURL = original.url;
		HTMLCanvasElement.prototype.getContext = original.context;
		document.body.replaceChildren();
	} );

	it( 'shows every photo and fills the colours, nothing sent yet', async () => {
		const { form, input } = setup( [
			photo( 'Sort.png' ),
			photo( 'Hvid.png' ),
		] );

		input.dispatchEvent( new Event( 'change' ) );
		await flush();

		expect(
			( form.elements.namedItem( 'colors' ) as HTMLTextAreaElement ).value
		).toBe( 'Sort #102030\nHvid #102030' );
		expect(
			Array.from( form.querySelectorAll( 'img' ) ).map(
				( image ) => image.alt
			)
		).toEqual( [ 'Sort', 'Hvid' ] );
		expect( global.fetch ).not.toHaveBeenCalled();
	} );

	it( 'refuses photos of different sizes', async () => {
		(
			global.createImageBitmap as unknown as jest.Mock<
				() => Promise< unknown >
			>
		 )
			.mockResolvedValueOnce( { width: 1000, height: 1000 } )
			.mockResolvedValueOnce( { width: 800, height: 1000 } );

		const { form, input } = setup( [
			photo( 'Sort.png' ),
			photo( 'Hvid.png' ),
		] );

		input.dispatchEvent( new Event( 'change' ) );
		await flush();
		form.dispatchEvent(
			new Event( 'submit', { bubbles: true, cancelable: true } )
		);

		expect( status() ).toContain( 'same size' );
		expect( form.querySelector( 'img' ) ).toBeNull();
		expect( global.fetch ).not.toHaveBeenCalled();
	} );

	it( 'keeps everything in the browser until the side is complete', async () => {
		const { form, input } = setup( [ photo( 'Sort.png' ) ] );

		input.dispatchEvent( new Event( 'change' ) );
		await flush();

		const event = new Event( 'submit', {
			bubbles: true,
			cancelable: true,
		} );

		form.dispatchEvent( event );

		expect( event.defaultPrevented ).toBe( true );
		expect( status() ).toContain( 'print area' );
		expect( global.fetch ).not.toHaveBeenCalled();
	} );

	const complete = ( form: HTMLFormElement ) => {
		fill( form, 'views[0][name]', 'Forside' );
		fill( form, 'views[0][width]', '366' );
		fill( form, 'views[0][height]', '357' );
		fill( form, 'views[0][width_mm]', '300' );
		fill( form, 'views[0][height_mm]', '300' );
	};

	it( 'saves the form and then sends the photos in batches', async () => {
		const { form, input } = setup(
			[ 'Sort', 'Hvid', 'Gul' ].map( ( name ) =>
				photo( `${ name }.png` )
			)
		);

		input.dispatchEvent( new Event( 'change' ) );
		await flush();
		complete( form );
		form.dispatchEvent(
			new Event( 'submit', { bubbles: true, cancelable: true } )
		);
		await flush();

		expect( requests ).toHaveLength( 3 );
		expect(
			( global.fetch as unknown as jest.Mock ).mock.calls[ 0 ]?.[ 0 ]
		).toBe( 'https://site.test/wp-admin/admin-post.php' );
		expect( requests[ 1 ]?.get( 'do' ) ).toBe( 'color_photos' );
		expect( requests[ 1 ]?.get( 'base_id' ) ).toBe( '7' );
		expect( requests[ 1 ]?.get( 'side' ) ).toBe( 'Forside' );
		expect( requests[ 1 ]?.get( 'photo_colors[1]' ) ).toBe( 'Hvid' );
		expect( requests[ 2 ]?.get( 'photo_colors[0]' ) ).toBe( 'Gul' );
		expect( requests[ 2 ]?.get( 'photo_1' ) ).toBeNull();
		expect( navigate ).toHaveBeenCalledWith( SAVED );
	} );

	it( 'stops at a refused batch and shows the reason on the page', async () => {
		const refused =
			'https://site.test/wp-admin/admin.php?base=7&result=base_layer_size';

		answers = [ SAVED, refused ];

		const { form, input } = setup(
			[ 'Sort', 'Hvid', 'Gul' ].map( ( name ) =>
				photo( `${ name }.png` )
			)
		);

		input.dispatchEvent( new Event( 'change' ) );
		await flush();
		complete( form );
		form.dispatchEvent(
			new Event( 'submit', { bubbles: true, cancelable: true } )
		);
		await flush();

		expect( requests ).toHaveLength( 2 );
		expect( navigate ).toHaveBeenCalledTimes( 1 );
		expect( navigate ).toHaveBeenCalledWith( refused );
	} );

	it( 'says so when a request does not reach the handler', async () => {
		answers = [ 'https://site.test/wp-admin/admin-post.php' ];

		const { form, input } = setup( [ photo( 'Sort.png' ) ] );

		input.dispatchEvent( new Event( 'change' ) );
		await flush();
		complete( form );
		form.dispatchEvent(
			new Event( 'submit', { bubbles: true, cancelable: true } )
		);
		await flush();

		expect( navigate ).not.toHaveBeenCalled();
		expect( status() ).toContain( 'stopped' );
		expect(
			form.querySelector< HTMLButtonElement >( 'button' )?.disabled
		).toBe( false );
	} );
} );
