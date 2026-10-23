import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { DEFAULT_ANIMATION } from '../../overlay/animations';
import { DEFAULT_FILL } from '../../overlay/textfill';
import { DEFAULT_FRAME } from '../../overlay/frame';
import { PresetsManager } from '../../admin/alerts-presets-admin';
import { parsePresetList, parsePresets } from '../api/alerts-presets';
import { PresetPickerCard } from '../sections/alerts/PresetPickerCard';
import { mockApi, response } from '../testing/api';
import { ORIGIN, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const ME = '/merchboard/v1/me/alerts';
const ADMIN = '/merchboard/v1/admin/alerts/presets';
const ID = 'a1b2c3d4e5f60718';

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

const SETTINGS = {
	template: 'Neon $Product!',
	secondary_template: '',
	font_size: 32,
	font_weight: '700',
	text_color: '#ffffff',
	text_shadow: '2px 2px 4px rgba(0,0,0,0.8)',
	text_align: 'center',
	text_fit: 'fixed',
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
	text_fills: { main_text: DEFAULT_FILL, secondary_text: DEFAULT_FILL },
	text_frame: DEFAULT_FRAME,
	font_family: 'inherit',
};

const IMAGES = {
	own: [],
	library: [],
	max_bytes: 5242880,
	max_pixels: 4096,
	max_own: 0,
};

const PRESET = {
	id: ID,
	name: 'Neon',
	settings: SETTINGS,
	font_approved: true,
	layout: LAYOUT,
	images: IMAGES,
};

const OVERLAY = {
	overlay: {
		url: `${ ORIGIN }/merchboard-alerts/overlay/${ 'a'.repeat( 32 ) }/`,
		settings: SETTINGS,
		font_approved: true,
		layout: LAYOUT,
		audio: null,
		images: null,
	},
	alerts_enabled: true,
	merchalert_active: false,
	fonts: [],
};

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
	jest.restoreAllMocks();
} );

function button( label: string ): HTMLButtonElement | null {
	return (
		Array.from(
			rendered?.container.querySelectorAll( 'button' ) ?? []
		).find( ( candidate ) => candidate.textContent === label ) ?? null
	);
}

describe( 'standard overlays (step 25.9.5)', () => {
	it( 'validates the lists', () => {
		expect( parsePresets( { presets: [ PRESET ] } )?.[ 0 ]?.name ).toBe(
			'Neon'
		);
		expect(
			parsePresets( { presets: [ { ...PRESET, id: 'nope' } ] } )
		).toBeNull();
		expect(
			parsePresetList( {
				presets: [ { id: ID, name: 'Neon' } ],
				max: 20,
			} )
		).toEqual( { presets: [ { id: ID, name: 'Neon' } ], max: 20 } );
		expect( parsePresetList( { presets: [], max: '20' } ) ).toBeNull();
	} );

	it( 'shows nothing to a creator without standard overlays', async () => {
		mockApi( {
			[ `GET ${ ME }/presets` ]: () => response( 200, { presets: [] } ),
		} );
		rendered = render(
			<PresetPickerCard
				fonts={ [] }
				onApplied={ () => undefined }
				notify={ () => undefined }
			/>
		);
		await flush();

		expect( rendered.container.textContent ).toBe( '' );
	} );

	it( 'lets a creator preview and copy a standard overlay after confirmation', async () => {
		const requests = mockApi( {
			[ `GET ${ ME }/presets` ]: () =>
				response( 200, { presets: [ PRESET ] } ),
			[ `POST ${ ME }/overlay/preset` ]: () => response( 200, OVERLAY ),
		} );
		const applied = jest.fn();
		const confirm = jest.spyOn( window, 'confirm' );

		rendered = render(
			<PresetPickerCard
				fonts={ [] }
				onApplied={ applied }
				notify={ () => undefined }
			/>
		);
		await flush();

		expect( rendered.container.textContent ).toContain(
			'Start from a standard overlay'
		);
		expect( rendered.container.textContent ).toContain( 'Preview' );
		expect(
			rendered.container.querySelector( 'option' )?.textContent
		).toBe( 'Neon' );

		confirm.mockReturnValueOnce( false );
		click( button( 'Use this standard overlay' ) );
		await flush();
		expect( applied ).not.toHaveBeenCalled();

		confirm.mockReturnValueOnce( true );
		click( button( 'Use this standard overlay' ) );
		await flush();
		expect( applied ).toHaveBeenCalledTimes( 1 );
		expect( requests.find( ( r ) => r.method === 'POST' )?.data ).toEqual( {
			preset: ID,
		} );
	} );

	it( 'lets the administrator make, rename and delete standard overlays', async () => {
		const requests = mockApi( {
			[ `POST ${ ADMIN }` ]: () =>
				response( 200, {
					presets: [ { id: ID, name: 'Neon' } ],
					max: 20,
				} ),
			[ `GET ${ ADMIN }/${ ID }` ]: () =>
				response( 200, {
					...OVERLAY,
					overlay: {
						...OVERLAY.overlay,
						url: `${ ORIGIN }/wp-admin/admin.php?page=merchboard-alerts&tab=presets&preset=${ ID }`,
					},
				} ),
			[ `POST ${ ADMIN }/${ ID }/name` ]: () =>
				response( 200, {
					presets: [ { id: ID, name: 'Glow' } ],
					max: 20,
				} ),
			[ `DELETE ${ ADMIN }/${ ID }` ]: () =>
				response( 200, { presets: [], max: 20 } ),
		} );

		rendered = render(
			<PresetsManager initial={ { presets: [], max: 20 } } />
		);

		const input =
			rendered.container.querySelector< HTMLInputElement >(
				'input[type="text"]'
			);
		expect( input ).not.toBeNull();
		await act( async () => {
			if ( input !== null ) {
				const setter = Object.getOwnPropertyDescriptor(
					HTMLInputElement.prototype,
					'value'
				)?.set;
				setter?.call( input, 'Neon' );
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		} );
		click( button( 'Make standard overlay' ) );
		await flush();
		await flush();

		expect( requests.find( ( r ) => r.method === 'POST' )?.data ).toEqual( {
			name: 'Neon',
		} );
		expect( rendered.container.textContent ).toContain( 'Neon' );

		jest.spyOn( window, 'prompt' ).mockReturnValueOnce( 'Glow' );
		click( button( 'Rename' ) );
		await flush();
		expect( rendered.container.textContent ).toContain( 'Glow' );

		jest.spyOn( window, 'confirm' ).mockReturnValueOnce( true );
		click( button( 'Delete' ) );
		await flush();
		expect( rendered.container.textContent ).toContain(
			'No standard overlays yet.'
		);
		expect( requests.every( ( r ) => r.path.startsWith( ADMIN ) ) ).toBe(
			true
		);
	} );
} );
