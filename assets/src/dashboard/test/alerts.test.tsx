import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { DEFAULT_ANIMATION } from '../../overlay/animations';
import { ALERT_ANIMATION, DESIGNER_DEFAULTS } from '../testing/alert-settings';
import { DEFAULT_FILL } from '../../overlay/textfill';
import { DEFAULT_FRAME } from '../../overlay/frame';
import { parseAlertsOverlay } from '../api/alerts';
import { ConfigContext } from '../app/config-context';
import {
	AlertsSection,
	maskOverlayUrl,
} from '../sections/alerts/AlertsSection';
import {
	AUTOSAVE_DELAY,
	validateOverlaySettings,
} from '../sections/alerts/editor/AlertEditor';
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
		alert: ALERT_ANIMATION,
		main_text: DEFAULT_ANIMATION,
		secondary_text: DEFAULT_ANIMATION,
	},
	text_fills: {
		main_text: DEFAULT_FILL,
		secondary_text: DEFAULT_FILL,
	},
	text_frame: DEFAULT_FRAME,
	font_family: 'inherit',
	...DESIGNER_DEFAULTS,
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

/**
 * The requests to the overlay routes (not the standard overlays, step 25.9.5).
 *
 * @param requests Recorded requests.
 */
function overlayRequests< T extends { readonly path: string } >(
	requests: readonly T[]
): T[] {
	return requests.filter( ( r ) => r.path.startsWith( PATH ) );
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
		).find(
			( element ) =>
				element.textContent === label ||
				element.getAttribute( 'aria-label' ) === label
		) ?? null;

	const show = async () => {
		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<AlertsSection />
			</ConfigContext.Provider>
		);
		await flush();
	};

	// Waits for the automatic save.
	const waitForAutosave = async () => {
		await act( async () => {
			await new Promise( ( resolve ) =>
				window.setTimeout( resolve, AUTOSAVE_DELAY + 50 )
			);
		} );
		await flush();
	};

	const typeInto = (
		element: HTMLTextAreaElement | HTMLInputElement | null | undefined,
		value: string
	) => {
		act( () => {
			if ( element ) {
				Object.getOwnPropertyDescriptor(
					Object.getPrototypeOf( element ),
					'value'
				)?.set?.call( element, value );
				element.dispatchEvent(
					new Event( 'input', { bubbles: true } )
				);
			}
		} );
	};

	const field = ( label: string ) => {
		const element = Array.from(
			rendered?.container.querySelectorAll( 'label' ) ?? []
		).find( ( candidate ) => candidate.textContent === label );
		const id = element?.getAttribute( 'for' );

		const input = id ? document.getElementById( id ) : null;

		return input instanceof HTMLInputElement ? input : null;
	};

	const patches = < T extends { readonly method: string } >(
		requests: readonly T[]
	): T[] => requests.filter( ( r ) => r.method === 'PATCH' );

	it( 'sets the overlay up on request and shows the OBS URL in the browser source', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( null ) ),
			[ `POST ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();
		click( button( 'Set up overlay' ) );
		await flush();

		expect( overlayRequests( requests ).map( ( r ) => r.method ) ).toEqual(
			[ 'GET', 'POST' ]
		);
		expect( rendered?.container.textContent ).toContain( 'Alert designer' );
		expect(
			rendered?.container.querySelector( 'input[readonly]' )
		).toBeNull();

		click( button( 'Browser source' ) );
		const url = () =>
			rendered?.container.querySelector< HTMLInputElement >(
				'input[readonly]'
			)?.value;

		expect( url() ).toBe( maskOverlayUrl( URL_A ) );
		expect( rendered?.container.innerHTML ).not.toContain(
			'a'.repeat( 32 )
		);
		click( button( 'Show URL' ) );
		expect( url() ).toBe( URL_A );

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

	it( 'replaces the URL only after confirmation and sends test alerts from the canvas', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `POST ${ PATH }/regenerate` ]: () =>
				response( 200, body( URL_B ) ),
			[ `POST ${ PATH }/test` ]: () => response( 200, { sent: true } ),
		} );
		const confirm = jest.spyOn( window, 'confirm' );

		await show();
		click( button( 'Browser source' ) );

		confirm.mockReturnValueOnce( false );
		click( button( 'Create new URL' ) );
		await flush();
		expect( overlayRequests( requests ) ).toHaveLength( 1 );

		confirm.mockReturnValueOnce( true );
		click( button( 'Create new URL' ) );
		await flush();
		expect(
			rendered?.container.querySelector< HTMLInputElement >(
				'input[readonly]'
			)?.value
		).toBe( maskOverlayUrl( URL_B ) );

		click( button( 'Test alert' ) );
		await flush();
		expect( requests.map( ( r ) => r.path ) ).toContain( `${ PATH }/test` );
		expect( rendered?.container.textContent ).toContain(
			'Test alert sent'
		);
		confirm.mockRestore();
	} );

	it( 'opens with "Merch alert" open and "Position, size and style" folded at the bottom', async () => {
		mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		const toggles = Array.from(
			rendered?.container.querySelectorAll< HTMLButtonElement >(
				'.merchboard-alert-panel--panel > .merchboard-alert-panel__heading > .merchboard-alert-panel__toggle'
			) ?? []
		);
		const state = Object.fromEntries(
			toggles.map( ( toggle ) => [
				toggle.textContent,
				toggle.getAttribute( 'aria-expanded' ),
			] )
		);

		expect( toggles.map( ( toggle ) => toggle.textContent ) ).toEqual( [
			'Merch alert',
			'Variations',
			'Read-out (TTS)',
			'Text',
			'Animation',
			'Position, size and style',
		] );
		expect( state ).toEqual( {
			'Merch alert': 'true',
			Variations: 'false',
			'Read-out (TTS)': 'false',
			Text: 'false',
			Animation: 'false',
			'Position, size and style': 'false',
		} );

		// Every group of fields folds open and shut too.
		const image = button( 'Product image' );
		expect( image?.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		click( image );
		expect( image?.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect( button( 'Message' )?.getAttribute( 'aria-expanded' ) ).toBe(
			'true'
		);
		click( button( 'Message' ) );
		expect( button( 'Message' )?.getAttribute( 'aria-expanded' ) ).toBe(
			'false'
		);
	} );

	it( 'saves every change once, a moment after the last one, with the layout and without whether sounds play', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, body( URL_A, { alerts_enabled: false } ) ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();
		expect( rendered?.container.textContent ).toContain(
			'Purchase alerts are switched off'
		);
		expect( rendered?.container.textContent ).toContain(
			'Changes are saved automatically.'
		);

		const template = rendered?.container.querySelector( 'textarea' );
		typeInto( template, 'Thanks' );
		typeInto( template, 'Thanks $UserName!' );
		expect( rendered?.container.textContent ).toContain( 'Saving…' );
		await waitForAutosave();

		expect( patches( requests ) ).toHaveLength( 1 );
		const { audio_enabled: audioEnabled, ...expected } = SETTINGS;
		void audioEnabled;
		expect( patches( requests )[ 0 ]?.data ).toEqual( {
			...expected,
			template: 'Thanks $UserName!',
			layout: LAYOUT,
		} );
		expect( rendered?.container.textContent ).toContain(
			'All changes are saved.'
		);
	} );

	it( 'keeps an empty text unsaved and says why', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();
		typeInto( rendered?.container.querySelector( 'textarea' ), '  ' );
		await waitForAutosave();

		expect( patches( requests ) ).toHaveLength( 0 );
		expect( rendered?.container.textContent ).toContain(
			'Enter a text for the alert.'
		);
	} );

	it( 'keeps an unavailable font until another is chosen', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () =>
				response( 200, {
					...body( URL_A ),
					overlay: {
						...body( URL_A ).overlay,
						settings: { ...SETTINGS, font_family: 'Lobster' },
						font_approved: false,
					},
				} ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();
		expect( rendered?.container.textContent ).toContain(
			'Your font is no longer available.'
		);

		typeInto( rendered?.container.querySelector( 'textarea' ), 'Hi' );
		await waitForAutosave();

		expect( patches( requests )[ 0 ]?.data ).not.toHaveProperty(
			'font_family'
		);
	} );

	it( 'chooses the layout and the extra line, and shows the alert on the canvas', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		const alert = rendered?.container.querySelector< HTMLElement >(
			'.merchboard-alert-canvas .merchboard-alert'
		);
		expect( alert?.dataset.layout ).toBe( 'free' );
		expect( alert?.textContent ).toContain(
			'Hoodie was purchased by TestFan!'
		);

		click( button( 'Image on the left' ) );
		click( field( 'Show an extra line of text' ) );
		expect( alert?.dataset.layout ).toBe( 'left' );
		await waitForAutosave();

		const data = patches( requests )[ 0 ]?.data as Record<
			string,
			unknown
		>;
		expect( data.layout_mode ).toBe( 'left' );
		expect( data.show_secondary ).toBe( true );
		expect(
			( data.layout as typeof LAYOUT ).elements.secondary_text.visible
		).toBe( true );
	} );

	it( 'centres the alert, moving the free elements with it, and undoes it', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();
		click( button( 'Position, size and style' ) );
		click( button( 'Centre the alert' ) );

		expect( field( 'X' )?.value ).toBe( '542' );
		expect( field( 'Y' )?.value ).toBe( '480' );

		click( button( 'Undo' ) );
		expect( field( 'X' )?.value ).toBe( '40' );
		click( button( 'Redo' ) );
		await waitForAutosave();

		const data = patches( requests ).at( -1 )?.data as Record<
			string,
			unknown
		>;
		expect( data.widget ).toEqual( {
			x: 542,
			y: 480,
			w: 836,
			h: 120,
			opacity: 100,
			rotation: 0,
		} );
		expect(
			( data.layout as typeof LAYOUT ).elements.product_image.x
		).toBe( 542 );
	} );

	it( 'shows the whole 1920 × 1080 screen, one box for image and texts, and boxes of their own in "free" (ADR-109)', async () => {
		mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();

		const box = rendered?.container.querySelector< HTMLElement >(
			'.merchboard-alert-canvas__box'
		);
		const width = Number.parseFloat( box?.style.width ?? '' );
		const height = Number.parseFloat( box?.style.height ?? '' );
		expect( width ).toBeGreaterThan( 0 );
		expect( width / height ).toBeCloseTo( 1920 / 1080, 3 );

		// The fixture is "free": the image and the main text, no box around them.
		expect(
			rendered?.container.querySelectorAll(
				'.merchboard-alert-canvas__element'
			).length
		).toBe( 2 );
		expect(
			rendered?.container.querySelector(
				'.merchboard-alert-canvas__frame'
			)
		).toBeNull();

		click( button( 'Image on the left' ) );
		expect(
			rendered?.container.querySelectorAll(
				'.merchboard-alert-canvas__element'
			).length
		).toBe( 0 );
		expect(
			rendered?.container.querySelector(
				'.merchboard-alert-canvas__frame'
			)
		).not.toBeNull();
	} );

	it( 'keeps the layout Free while the main text runs around the image', async () => {
		const framed = {
			...body( URL_A ),
			overlay: {
				...( body( URL_A ).overlay as Record< string, unknown > ),
				settings: {
					...SETTINGS,
					text_frame: { ...DEFAULT_FRAME, enabled: true },
				},
			},
		};
		mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, framed ),
			[ `PATCH ${ PATH }` ]: () => response( 200, framed ),
		} );

		await show();
		click( button( 'Layout' ) );

		expect(
			rendered?.container.querySelector< HTMLButtonElement >(
				'[aria-label="Image on the left"]'
			)?.disabled
		).toBe( true );
		expect(
			rendered?.container.querySelector< HTMLButtonElement >(
				'[aria-label="Free"]'
			)?.disabled
		).toBe( false );
		expect( rendered?.container.textContent ).toContain(
			'The main text runs around the image, so the layout is Free.'
		);
	} );

	it( 'highlights the inserted values on the canvas', async () => {
		mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
		} );

		await show();
		click( button( 'Text' ) );
		click( field( 'Highlight the inserted values' ) );

		const marked = Array.from(
			rendered?.container.querySelectorAll(
				'.merchboard-alert-canvas .merchboard-hl'
			) ?? [],
			( letter ) => letter.textContent
		).join( '' );

		expect( marked ).toBe( 'HoodieTestFan' );
	} );

	it( 'adds a variation, edits its own text and conditions, and tests it', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `PATCH ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			[ `POST ${ PATH }/test` ]: () => response( 200, { sent: true } ),
		} );

		await show();
		click( button( 'Variations' ) );
		click( button( '+ New variation' ) );

		expect( rendered?.container.textContent ).toContain(
			'Editing: Variation 1'
		);
		expect( button( '+ New variation' ) ).toBeNull();

		typeInto(
			rendered?.container.querySelector< HTMLTextAreaElement >(
				'.merchboard-alert-panel textarea'
			),
			'Big one from $UserName'
		);
		typeInto( field( 'Least amount' ), '500' );
		expect(
			rendered?.container.querySelector(
				'.merchboard-alert-canvas .merchboard-alert'
			)?.textContent
		).toContain( 'Big one from TestFan' );

		click( button( 'Test alert' ) );
		await flush();
		expect(
			requests.find( ( r ) => r.path === `${ PATH }/test` )?.data
		).toEqual( { look: expect.stringMatching( /^v[0-9a-f]{12}$/ ) } );

		click( button( 'Back to the default' ) );
		expect( rendered?.container.querySelector( 'textarea' )?.value ).toBe(
			SETTINGS.template
		);
		await waitForAutosave();

		const data = patches( requests ).at( -1 )?.data as {
			template: string;
			variations: Array< {
				name: string;
				conditions: { min_amount: number };
				look: { template: string; audio_default_sound: number };
			} >;
		};
		expect( data.template ).toBe( SETTINGS.template );
		expect( data.variations ).toHaveLength( 1 );
		expect( data.variations[ 0 ]?.name ).toBe( 'Variation 1' );
		expect( data.variations[ 0 ]?.conditions.min_amount ).toBe( 500 );
		expect( data.variations[ 0 ]?.look.template ).toBe(
			'Big one from $UserName'
		);
		expect( data.variations[ 0 ]?.look.audio_default_sound ).toBe( 0 );
	} );

	it( 'shows the saved designs in the top bar', async () => {
		mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( URL_A ) ),
			'GET /merchboard/v1/me/alerts/designs': () =>
				response( 200, { designs: [], max: 10 } ),
			'GET /merchboard/v1/me/alerts/presets': () =>
				response( 200, { presets: [] } ),
		} );

		await show();
		expect( rendered?.container.textContent ).not.toContain(
			'Save current design'
		);

		click( button( 'Designs' ) );
		await flush();
		expect( rendered?.container.textContent ).toContain(
			'Save current design'
		);
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
			'at most 10 MB and 30 seconds'
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
} );
