import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { DEFAULT_ANIMATION } from '../../overlay/animations';
import { DEFAULT_FILL } from '../../overlay/textfill';
import { DEFAULT_FRAME } from '../../overlay/frame';
import { parseAlertsOverlay } from '../api/alerts';
import { ConfigContext } from '../app/config-context';
import {
	AlertsSection,
	maskOverlayUrl,
} from '../sections/alerts/AlertsSection';
import { validateOverlaySettings } from '../sections/alerts/OverlaySettingsForm';
import { mockApi, response } from '../testing/api';
import { CONFIG, ORIGIN, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PATH = '/merchboard/v1/me/alerts/overlay';
const URL_A = `${ ORIGIN }/merchboard-alerts/overlay/${ 'a'.repeat( 32 ) }/`;
const URL_B = `${ ORIGIN }/merchboard-alerts/overlay/${ 'b'.repeat( 32 ) }/`;

const SETTINGS = {
	template: '$Product was purchased by $UserName!',
	secondary_template: '',
	font_size: 32,
	font_weight: '700',
	text_color: '#ffffff',
	text_shadow: '2px 2px 4px rgba(0,0,0,0.8)',
	text_align: 'center',
	text_fit: 'fixed' as const,
	image_size: 120,
	bg_color: '#000000',
	bg_opacity: 80,
	border_radius: 8,
	padding: 20,
	anim_duration: 500,
	display_duration: 5000,
	show_secondary: false,
	display_from_sound: false,
	animations: {
		main_text: DEFAULT_ANIMATION,
		secondary_text: DEFAULT_ANIMATION,
	},
	text_fills: {
		main_text: DEFAULT_FILL,
		secondary_text: DEFAULT_FILL,
	},
	text_frame: DEFAULT_FRAME,
	font_family: 'inherit',
};

const LAYOUT = {
	elements: {
		product_image: {
			x: 40,
			y: 880,
			w: 120,
			h: 120,
			visible: true,
			lock_aspect: true,
			border_radius: 4,
		},
		main_text: {
			x: 176,
			y: 895,
			w: 700,
			h: 80,
			visible: true,
			lock_aspect: false,
			border_radius: 0,
		},
		secondary_text: {
			x: 176,
			y: 985,
			w: 700,
			h: 50,
			visible: false,
			lock_aspect: false,
			border_radius: 0,
		},
	},
	canvas: { snap_grid: false, grid_size: 10 },
};

const ROBOTO = {
	family: 'Roboto',
	url: 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap',
};

const SOUND = {
	url: `${ ORIGIN }/wp-content/uploads/2026/10/ding.mp3`,
	name: 'ding.mp3',
	seconds: 3,
	library_id: 0,
};

const CHIME = {
	id: 40,
	url: `${ ORIGIN }/wp-content/uploads/2026/10/chime.wav`,
	name: 'chime.wav',
	seconds: 2,
};

const AUDIO = {
	enabled: false,
	default: null,
	categories: [ { term_id: 12, name: 'Hoodies', sound: SOUND } ],
	library: [ CHIME ],
	max_bytes: 10485760,
	max_seconds: 30,
};

function body( url: string | null, extra: Record< string, unknown > = {} ) {
	return {
		overlay:
			url === null
				? null
				: {
						url,
						settings: SETTINGS,
						layout: LAYOUT,
						font_approved: true,
						audio: AUDIO,
					},
		alerts_enabled: true,
		merchalert_active: false,
		fonts: [ ROBOTO ],
		...extra,
	};
}

const IMAGE = {
	id: 5,
	url: `${ ORIGIN }/wp-content/uploads/2026/10/stars.gif`,
	name: 'stars.gif',
	width: 400,
	height: 200,
};

/**
 * An answer whose overlay has text fill images.
 *
 * @param own The creator's own images.
 */
function imageBody( own: unknown[] ) {
	const base = body( URL_A );

	return {
		...base,
		overlay: {
			...base.overlay,
			images: {
				own,
				library: [],
				max_bytes: 5242880,
				max_pixels: 4096,
				max_own: 10,
			},
		},
	};
}

describe( 'response validation', () => {
	it( 'accepts an overlay and no overlay', () => {
		expect( parseAlertsOverlay( body( URL_A ) )?.overlay?.url ).toBe(
			URL_A
		);
		expect( parseAlertsOverlay( body( URL_A ) )?.overlay?.audio ).toEqual( {
			enabled: false,
			defaultSound: null,
			categories: [
				{
					termId: 12,
					name: 'Hoodies',
					sound: {
						url: SOUND.url,
						name: SOUND.name,
						seconds: 3,
						libraryId: 0,
					},
				},
			],
			library: [ CHIME ],
			maxBytes: 10485760,
			maxSeconds: 30,
		} );
		expect( parseAlertsOverlay( body( null ) ) ).toEqual( {
			overlay: null,
			alertsEnabled: true,
			merchalertActive: false,
			fonts: [ ROBOTO ],
		} );
	} );

	it.each( [
		null,
		{},
		body( 'https://evil.example/overlay/' ),
		body( URL_A, { alerts_enabled: 'yes' } ),
		{
			...body( URL_A ),
			overlay: { url: URL_A, settings: { ...SETTINGS, font_size: 999 } },
		},
		{
			...body( URL_A ),
			overlay: {
				url: URL_A,
				settings: { ...SETTINGS, text_color: 'red' },
			},
		},
		{
			...body( URL_A ),
			overlay: {
				url: URL_A,
				settings: { ...SETTINGS, show_secondary: 1 },
			},
		},
		{
			...body( URL_A ),
			fonts: [ { family: 'Roboto', url: 'https://evil.example/x.css' } ],
		},
		{
			...body( URL_A ),
			fonts: [ { family: 'Roboto;}', url: ROBOTO.url } ],
		},
		{
			...body( URL_A ),
			overlay: {
				url: URL_A,
				settings: SETTINGS,
				font_approved: true,
				layout: {
					...LAYOUT,
					canvas: { snap_grid: false, grid_size: 0 },
				},
			},
		},
		{
			...body( URL_A ),
			overlay: {
				url: URL_A,
				settings: { ...SETTINGS, font_family: 'x"}' },
				font_approved: true,
				layout: LAYOUT,
			},
		},
		{
			...body( URL_A ),
			overlay: {
				url: URL_A,
				settings: SETTINGS,
				font_approved: true,
				layout: LAYOUT,
			},
		},
		{
			...body( URL_A ),
			overlay: {
				url: URL_A,
				settings: SETTINGS,
				font_approved: true,
				layout: LAYOUT,
				audio: {
					...AUDIO,
					default: { ...SOUND, url: 'https://evil.example/x.mp3' },
				},
			},
		},
		{
			...body( URL_A ),
			overlay: {
				url: URL_A,
				settings: SETTINGS,
				font_approved: true,
				layout: LAYOUT,
				audio: {
					...AUDIO,
					categories: [ { term_id: 0, name: 'x', sound: null } ],
				},
			},
		},
	] )( 'rejects malformed bodies %#', ( candidate ) => {
		expect( parseAlertsOverlay( candidate ) ).toBeNull();
	} );
} );

describe( 'validateOverlaySettings', () => {
	it( 'checks ranges and the main text', () => {
		const errors = validateOverlaySettings( {
			...SETTINGS,
			font_weight: '700',
			text_align: 'center',
			font_size: 500,
			display_duration: Number.NaN,
			template: '  ',
		} );

		expect( Object.keys( errors ).sort() ).toEqual( [
			'display_duration',
			'font_size',
			'template',
		] );
		expect(
			validateOverlaySettings( {
				...SETTINGS,
				font_weight: '700',
				text_align: 'center',
			} )
		).toEqual( {} );
	} );
} );

describe( 'AlertsSection', () => {
	let rendered: Rendered | null = null;

	afterEach( () => {
		rendered?.unmount();
		rendered = null;
	} );

	const button = ( label: string ) =>
		Array.from(
			rendered?.container.querySelectorAll( 'button' ) ?? []
		).find( ( element ) => element.textContent === label ) ?? null;

	const show = async () => {
		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<AlertsSection />
			</ConfigContext.Provider>
		);
		await flush();
	};

	it( 'sets the overlay up on request and shows the OBS URL', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( null ) ),
			[ `POST ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		expect(
			rendered?.container.querySelector( 'input[readonly]' )
		).toBeNull();

		click( button( 'Set up overlay' ) );
		await flush();

		expect( requests.map( ( r ) => r.method ) ).toEqual( [
			'GET',
			'POST',
		] );
		const field = () =>
			rendered?.container.querySelector< HTMLInputElement >(
				'input[readonly]'
			)?.value;

		expect( field() ).toBe( maskOverlayUrl( URL_A ) );
		expect( field() ).not.toContain( 'a'.repeat( 32 ) );
		expect( rendered?.container.innerHTML ).not.toContain(
			'a'.repeat( 32 )
		);

		click( button( 'Show URL' ) );
		expect( field() ).toBe( URL_A );
		click( button( 'Hide URL' ) );
		expect( field() ).toBe( maskOverlayUrl( URL_A ) );

		const open = jest
			.spyOn( window, 'open' )
			.mockImplementation( () => null );
		click( button( 'Open overlay' ) );
		expect( open ).toHaveBeenCalledWith(
			URL_A,
			'_blank',
			'noopener,noreferrer'
		);
		open.mockRestore();
	} );

	it( 'replaces the URL only after confirmation and sends test alerts', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `POST ${ PATH }/regenerate` ]: () =>
				response( 200, body( URL_B ) ),
			[ `POST ${ PATH }/test` ]: () => response( 200, { sent: true } ),
		} );
		const confirm = jest.spyOn( window, 'confirm' );

		await show();

		confirm.mockReturnValueOnce( false );
		click( button( 'Create new URL' ) );
		await flush();
		expect( requests ).toHaveLength( 1 );

		click( button( 'Show URL' ) );
		confirm.mockReturnValueOnce( true );
		click( button( 'Create new URL' ) );
		await flush();
		expect(
			rendered?.container.querySelector< HTMLInputElement >(
				'input[readonly]'
			)?.value
		).toBe( maskOverlayUrl( URL_B ) );

		click( button( 'Send test alert' ) );
		await flush();
		expect( requests.map( ( r ) => r.path ) ).toContain( `${ PATH }/test` );
		expect( rendered?.container.textContent ).toContain(
			'Test alert sent'
		);

		confirm.mockRestore();
	} );

	it( 'saves the settings and tells when alerts are switched off', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, body( URL_A, { alerts_enabled: false } ) ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		expect( rendered?.container.textContent ).toContain(
			'Purchase alerts are switched off'
		);

		click( button( 'Save overlay settings' ) );
		await flush();

		const patch = requests.find( ( r ) => r.method === 'PATCH' );
		expect( patch?.data ).toEqual( SETTINGS );
		expect( rendered?.container.textContent ).toContain(
			'Overlay settings saved.'
		);
	} );

	it( 'offers only approved fonts and keeps an unavailable font until another is chosen', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, {
					...body( URL_A ),
					overlay: {
						url: URL_A,
						settings: { ...SETTINGS, font_family: 'Lobster' },
						layout: LAYOUT,
						font_approved: false,
						audio: AUDIO,
					},
				} ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		expect( rendered?.container.textContent ).toContain(
			'Your font is no longer available'
		);
		expect(
			Array.from(
				rendered?.container.querySelectorAll< HTMLOptionElement >(
					'option'
				) ?? []
			)
				.filter( ( option ) => ! option.disabled )
				.map( ( option ) => option.value )
		).toEqual( expect.arrayContaining( [ 'inherit', 'Roboto' ] ) );

		click( button( 'Save overlay settings' ) );
		await flush();

		const patch = requests.find( ( r ) => r.method === 'PATCH' );
		expect( patch?.data ).not.toHaveProperty( 'font_family' );
	} );

	it( 'shows the secondary text box only when it is switched on', async () => {
		mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, {
					...body( URL_A ),
					overlay: {
						url: URL_A,
						settings: { ...SETTINGS, show_secondary: true },
						layout: LAYOUT,
						font_approved: true,
						audio: AUDIO,
					},
				} ),
		} );

		await show();

		expect(
			Array.from(
				rendered?.container.querySelectorAll(
					'.merchboard-designer__box'
				) ?? []
			).map( ( box ) => box.getAttribute( 'aria-label' ) )
		).toEqual( [ 'Product image', 'Main text', 'Secondary text' ] );
	} );

	it( 'saves the designer layout', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		// The secondary text is switched off, so its box is not shown.
		expect(
			Array.from(
				rendered?.container.querySelectorAll(
					'.merchboard-designer__box'
				) ?? []
			).map( ( box ) => box.getAttribute( 'aria-label' ) )
		).toEqual( [ 'Product image', 'Main text' ] );

		click( button( 'Save layout' ) );
		await flush();

		const patch = requests.find( ( r ) => r.method === 'PATCH' );
		expect( patch?.data ).toEqual( { layout: LAYOUT } );
		expect( rendered?.container.textContent ).toContain( 'Layout saved.' );
	} );

	const choose = ( input: HTMLInputElement, file: File ) => {
		Object.defineProperty( input, 'files', {
			configurable: true,
			value: [ file ],
		} );
		act( () => {
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
	};

	const fileInputs = () =>
		Array.from(
			rendered?.container.querySelectorAll< HTMLInputElement >(
				'input[type="file"]'
			) ?? []
		);

	it( 'switches sounds on and uploads a default sound', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `POST ${ PATH }/sounds/default` ]: () =>
				response( 200, body( URL_A ) ),
		} );

		await show();

		const toggle = Array.from(
			rendered?.container.querySelectorAll< HTMLInputElement >(
				'input[type="checkbox"]'
			) ?? []
		).find( ( input ) =>
			input.parentElement?.textContent?.includes( 'Play sounds' )
		);
		click( toggle ?? null );
		await flush();

		expect( requests.find( ( r ) => r.method === 'PATCH' )?.data ).toEqual(
			{
				audio_enabled: true,
			}
		);

		const file = new File( [ 'x' ], 'ding.mp3', { type: 'audio/mpeg' } );
		choose( fileInputs()[ 0 ] as HTMLInputElement, file );
		await flush();

		const upload = requests.find(
			( r ) => r.path === `${ PATH }/sounds/default`
		);
		expect( upload?.method ).toBe( 'POST' );
		expect( ( upload?.body as FormData ).get( 'file' ) ).toBe( file );
		expect( rendered?.container.textContent ).toContain( 'Sound saved.' );
		expect( rendered?.container.textContent ).toContain(
			'Category: Hoodies'
		);
		expect( rendered?.container.textContent ).toContain( 'ding.mp3 (3 s)' );
	} );

	it( 'explains a refused sound and checks the size before uploading', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `POST ${ PATH }/sounds/default` ]: () =>
				response( 400, {
					code: 'merchboard_invalid_request',
					data: { fields: { file: 'too_long' } },
				} ),
		} );

		await show();
		expect( rendered?.container.textContent ).toContain(
			'Contact us to ask for other limits.'
		);

		choose(
			fileInputs()[ 0 ] as HTMLInputElement,
			new File( [ 'x' ], 'long.wav', { type: 'audio/wav' } )
		);
		await flush();
		expect( rendered?.container.textContent ).toContain(
			'The sound is longer than 30 seconds.'
		);

		const big = new File( [ 'x' ], 'big.mp3', { type: 'audio/mpeg' } );
		Object.defineProperty( big, 'size', { value: 10485761 } );
		choose( fileInputs()[ 0 ] as HTMLInputElement, big );
		await flush();

		expect( rendered?.container.textContent ).toContain(
			'The file is larger than 10 MB.'
		);
		expect( requests.filter( ( r ) => r.method === 'POST' ) ).toHaveLength(
			1
		);
	} );

	it( 'deletes a category sound only after confirmation', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `DELETE ${ PATH }/sounds/12` ]: () =>
				response( 200, body( URL_A ) ),
		} );
		const confirm = jest
			.spyOn( window, 'confirm' )
			.mockReturnValueOnce( false )
			.mockReturnValueOnce( true );

		await show();

		click( button( 'Delete sound' ) );
		await flush();
		expect(
			requests.filter( ( r ) => r.method === 'DELETE' )
		).toHaveLength( 0 );

		click( button( 'Delete sound' ) );
		await flush();
		expect(
			requests.filter( ( r ) => r.method === 'DELETE' )
		).toHaveLength( 1 );
		expect( rendered?.container.textContent ).toContain( 'Sound deleted.' );
		confirm.mockRestore();
	} );

	it( 'chooses a library sound instead of uploading one', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `POST ${ PATH }/sounds/default` ]: () =>
				response( 200, body( URL_A ) ),
		} );

		await show();

		const select = rendered?.container.querySelector< HTMLSelectElement >(
			'.merchboard-sounds__library select'
		);
		expect( select ).not.toBeNull();
		expect(
			Array.from( select?.options ?? [] ).map( ( option ) => option.text )
		).toEqual( [ 'Choose a sound…', 'chime.wav (2 s)' ] );

		act( () => {
			if ( select ) {
				select.value = '40';
				select.dispatchEvent(
					new Event( 'change', { bubbles: true } )
				);
			}
		} );
		await flush();

		const chosen = requests.find(
			( r ) => r.path === `${ PATH }/sounds/default`
		);
		expect( chosen?.method ).toBe( 'POST' );
		expect( chosen?.data ).toEqual( { library: 40 } );
		expect( rendered?.container.textContent ).toContain( 'Sound saved.' );
	} );

	it( 'selects several boxes with Shift and aligns them to each other', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		const boxes = Array.from(
			rendered?.container.querySelectorAll< HTMLElement >(
				'.merchboard-designer__box'
			) ?? []
		);
		const image = boxes[ 0 ] as HTMLElement;

		act( () => {
			image.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: 'Enter',
					shiftKey: true,
					bubbles: true,
				} )
			);
		} );

		expect(
			boxes.map( ( box ) => box.getAttribute( 'aria-pressed' ) )
		).toEqual( [ 'true', 'true' ] );
		expect( rendered?.container.textContent ).toContain(
			'2 elements selected'
		);

		const target = rendered?.container.querySelector< HTMLSelectElement >(
			'.merchboard-designer__align select'
		);
		act( () => {
			if ( target ) {
				target.value = 'selection';
				target.dispatchEvent(
					new Event( 'change', { bubbles: true } )
				);
			}
		} );

		click( button( 'Align top' ) );
		click( button( 'Save layout' ) );
		await flush();

		const saved = requests.find( ( r ) => r.method === 'PATCH' )?.data as {
			layout: typeof LAYOUT;
		};
		expect( saved.layout.elements.main_text.y ).toBe(
			LAYOUT.elements.product_image.y
		);
		expect( saved.layout.elements.product_image.y ).toBe(
			LAYOUT.elements.product_image.y
		);
	} );

	it( 'undoes and redoes designer changes with the keyboard', async () => {
		mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		const leftField = () =>
			Array.from(
				rendered?.container.querySelectorAll< HTMLInputElement >(
					'.merchboard-designer__properties input[type="number"]'
				) ?? []
			)[ 0 ];
		const press = (
			init: KeyboardEventInit,
			target: EventTarget = window
		) =>
			act( () => {
				target.dispatchEvent(
					new KeyboardEvent( 'keydown', { bubbles: true, ...init } )
				);
			} );

		expect( button( 'Undo' )?.hasAttribute( 'disabled' ) ).toBe( true );
		expect( leftField()?.value ).toBe(
			String( LAYOUT.elements.main_text.x )
		);

		click( button( 'Align left' ) );
		expect( leftField()?.value ).toBe( '0' );
		expect( button( 'Undo' )?.hasAttribute( 'disabled' ) ).toBe( false );

		press( { key: 'z', ctrlKey: true } );
		expect( leftField()?.value ).toBe(
			String( LAYOUT.elements.main_text.x )
		);

		press( { key: 'z', ctrlKey: true, shiftKey: true } );
		expect( leftField()?.value ).toBe( '0' );

		// A text field of the settings form keeps the browser's own undo.
		const template = rendered?.container.querySelector( 'textarea' );
		expect( template ).not.toBeNull();
		press( { key: 'z', ctrlKey: true }, template as HTMLTextAreaElement );
		expect( leftField()?.value ).toBe( '0' );

		click( button( 'Undo' ) );
		expect( leftField()?.value ).toBe(
			String( LAYOUT.elements.main_text.x )
		);
	} );

	it( 'shows the save button right above the designer, which is last', async () => {
		mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		const save = button( 'Save overlay settings' );
		const designer = rendered?.container.querySelector(
			'.merchboard-designer'
		);
		const slot = save?.closest(
			'.merchboard-form__actions'
		)?.parentElement;

		expect( save?.closest( '.merchboard-alerts__columns' ) ).toBeNull();
		expect( save?.getAttribute( 'form' ) ).toBe(
			rendered?.container.querySelector( 'form' )?.id
		);
		expect( slot?.nextElementSibling?.contains( designer ?? null ) ).toBe(
			true
		);
		expect(
			slot?.parentElement?.lastElementChild?.contains( designer ?? null )
		).toBe( true );
	} );

	it( 'saves the text animations and offers to play them', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		const fieldset = rendered?.container.querySelector(
			'.merchboard-animation'
		);
		const inSelect = fieldset?.querySelector( 'select' ) ?? null;

		expect( inSelect ).not.toBeNull();
		act( () => {
			if ( inSelect ) {
				inSelect.value = 'typewriter';
				inSelect.dispatchEvent(
					new Event( 'change', { bubbles: true } )
				);
			}
		} );

		click( button( 'Save overlay settings' ) );
		await flush();

		const patch = requests.find( ( r ) => r.method === 'PATCH' )?.data as {
			animations: { main_text: { in: string } };
		};
		expect( patch.animations.main_text.in ).toBe( 'typewriter' );
		expect( button( 'Play animation' ) ).not.toBeNull();
		expect(
			rendered?.container.querySelectorAll( '.merchboard-animation' )
		).toHaveLength( 1 );
	} );

	it( 'loops a sample of the chosen animation, which can be paused', async () => {
		mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		const sample = () =>
			rendered?.container.querySelector(
				'.merchboard-animation__text'
			) ?? null;
		const inSelect =
			rendered?.container.querySelector(
				'.merchboard-animation select'
			) ?? null;

		expect( sample()?.classList.contains( 'merchboard-anim' ) ).toBe(
			true
		);

		act( () => {
			if ( inSelect instanceof HTMLSelectElement ) {
				inSelect.value = 'wave';
				inSelect.dispatchEvent(
					new Event( 'change', { bubbles: true } )
				);
			}
		} );

		const text = sample();
		expect( text instanceof HTMLElement && text.dataset.in ).toBe( 'wave' );
		expect(
			text?.querySelectorAll( '.merchboard-anim__letter' ).length
		).toBeGreaterThan( 0 );

		click( button( 'Pause example' ) );

		expect( sample()?.classList.contains( 'merchboard-anim' ) ).toBe(
			false
		);
		expect( button( 'Play example' ) ).not.toBeNull();
	} );

	it( 'plays an unsaved animation in the designer', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		const inSelect =
			rendered?.container.querySelector(
				'.merchboard-animation select'
			) ?? null;

		act( () => {
			if ( inSelect instanceof HTMLSelectElement ) {
				inSelect.value = 'typewriter';
				inSelect.dispatchEvent(
					new Event( 'change', { bubbles: true } )
				);
			}
		} );

		click( button( 'Play animation' ) );

		const text = rendered?.container.querySelector(
			'.merchboard-designer .merchboard-anim'
		);
		expect( text instanceof HTMLElement && text.dataset.in ).toBe(
			'typewriter'
		);
		expect( requests.some( ( r ) => r.method === 'PATCH' ) ).toBe( false );
	} );
	it( 'refuses text fill images of another site', () => {
		expect(
			parseAlertsOverlay( imageBody( [ IMAGE ] ) )?.overlay?.images?.own
		).toHaveLength( 1 );
		expect(
			parseAlertsOverlay(
				imageBody( [ { ...IMAGE, url: 'https://evil.example/a.gif' } ] )
			)
		).toBeNull();
	} );

	it( 'fills a text with a preset or an image, shows it and saves it', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, imageBody( [ IMAGE ] ) ),
			[ `PATCH ${ PATH }` ]: () =>
				response( 200, imageBody( [ IMAGE ] ) ),
		} );

		await show();

		const field = ( label: string ) => {
			const element = Array.from(
				rendered?.container.querySelectorAll( 'label' ) ?? []
			).find( ( item ) => item.textContent === label );
			const control = element
				? document.getElementById( element.htmlFor )
				: null;

			return control instanceof HTMLSelectElement ? control : null;
		};
		const change = ( select: HTMLSelectElement | null, value: string ) => {
			expect( select ).not.toBeNull();
			act( () => {
				if ( select ) {
					select.value = value;
					select.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
				}
			} );
		};
		const sample = () =>
			rendered?.container.querySelector< HTMLElement >(
				'.merchboard-animation__text'
			) ?? null;

		change( field( 'Text fill' ), 'preset' );
		change( field( 'Gradient or pattern' ), 'gold' );
		expect( sample()?.classList.contains( 'merchboard-fill' ) ).toBe(
			true
		);

		change( field( 'Text fill' ), 'image' );
		change( field( 'Image' ), '5' );
		change( field( 'Fill motion' ), 'pan' );
		expect(
			sample()?.style.getPropertyValue( '--merchboard-fill-image' )
		).toContain( 'stars.gif' );
		expect(
			rendered?.container
				.querySelector( '.merchboard-designer .merchboard-fill' )
				?.getAttribute( 'data-fill-motion' )
		).toBe( 'pan' );

		click( button( 'Save overlay settings' ) );
		await flush();

		const patch = requests.find( ( r ) => r.method === 'PATCH' )?.data as {
			text_fills: { main_text: unknown };
		};
		expect( patch.text_fills.main_text ).toEqual( {
			type: 'image',
			preset: 'gold',
			image: 5,
			motion: 'pan',
		} );
	} );

	it( 'uploads and deletes own images for text fills', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, imageBody( [] ) ),
			[ `POST ${ PATH }/images` ]: () =>
				response( 200, imageBody( [ IMAGE ] ) ),
			[ `DELETE ${ PATH }/images/5` ]: () =>
				response( 200, imageBody( [] ) ),
		} );
		const confirm = jest.spyOn( window, 'confirm' ).mockReturnValue( true );

		await show();

		const imageInput = () =>
			fileInputs().find( ( item ) =>
				item.accept.includes( 'image/gif' )
			);
		const card = () =>
			Array.from(
				rendered?.container.querySelectorAll( 'h2, h3' ) ?? []
			).some( ( heading ) => heading.textContent === 'Images for text' );

		expect( card() ).toBe( false );
		expect( imageInput() ).toBeUndefined();

		// The card appears right below the text fill once a text is filled with an image.
		const typeLabel = Array.from(
			rendered?.container.querySelectorAll( 'label' ) ?? []
		).find( ( item ) => item.textContent === 'Text fill' );
		const typeSelect = typeLabel
			? document.getElementById( typeLabel.htmlFor )
			: null;
		act( () => {
			if ( typeSelect instanceof HTMLSelectElement ) {
				typeSelect.value = 'image';
				typeSelect.dispatchEvent(
					new Event( 'change', { bubbles: true } )
				);
			}
		} );
		expect( card() ).toBe( true );

		const headings = Array.from(
			rendered?.container.querySelectorAll( 'h2, h3' ) ?? []
		).map( ( heading ) => heading.textContent );
		expect(
			headings.indexOf( 'Images for text' ) -
				headings.indexOf( 'Animations and text fill' )
		).toBe( 1 );
		const input = imageInput();
		const file = new File( [ 'x' ], 'stars.gif', { type: 'image/gif' } );
		choose( input as HTMLInputElement, file );
		await flush();

		const upload = requests.find( ( r ) => r.path === `${ PATH }/images` );
		expect( upload?.method ).toBe( 'POST' );
		expect( ( upload?.body as FormData ).get( 'file' ) ).toBe( file );
		expect( rendered?.container.textContent ).toContain( 'stars.gif' );

		click( button( 'Delete' ) );
		await flush();

		expect(
			requests.some(
				( r ) =>
					r.method === 'DELETE' && r.path === `${ PATH }/images/5`
			)
		).toBe( true );
		expect( rendered?.container.textContent ).toContain( 'Image deleted.' );
		confirm.mockRestore();
	} );

	it( 'runs the main text round the image in the example, the designer and the saved settings', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		const field = ( label: string ) => {
			const element = Array.from(
				rendered?.container.querySelectorAll( 'label' ) ?? []
			).find( ( item ) => item.textContent === label );
			const control = element
				? document.getElementById( element.htmlFor )
				: null;

			return control instanceof HTMLSelectElement ? control : null;
		};
		const change = ( select: HTMLSelectElement | null, value: string ) => {
			expect( select ).not.toBeNull();
			act( () => {
				if ( select ) {
					select.value = value;
					select.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
				}
			} );
		};
		const boxes = () =>
			Array.from(
				rendered?.container.querySelectorAll(
					'.merchboard-designer__box'
				) ?? []
			).map( ( box ) => box.getAttribute( 'aria-label' ) );

		expect( boxes() ).toContain( 'Main text' );
		expect( field( 'Direction' ) ).toBeNull();

		change( field( 'Placement' ), 'frame' );
		change( field( 'Direction' ), 'counterclockwise' );
		change( field( 'Glide speed' ), 'fast' );

		expect(
			rendered?.container.querySelector(
				'.merchboard-animation__ring .merchboard-frame'
			)
		).not.toBeNull();
		expect( boxes() ).not.toContain( 'Main text' );
		expect(
			rendered?.container
				.querySelector(
					'.merchboard-designer__frame .merchboard-frame'
				)
				?.getAttribute( 'data-frame-direction' )
		).toBe( 'counterclockwise' );

		click( button( 'Save overlay settings' ) );
		await flush();

		const patch = requests.find( ( r ) => r.method === 'PATCH' )?.data as {
			text_frame: unknown;
		};
		expect( patch.text_frame ).toEqual( {
			enabled: true,
			direction: 'counterclockwise',
			speed: 'fast',
			repeat: true,
		} );
	} );
} );
