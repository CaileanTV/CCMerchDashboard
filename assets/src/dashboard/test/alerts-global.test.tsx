import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { DEFAULT_ANIMATION } from '../../overlay/animations';
import { DEFAULT_FILL } from '../../overlay/textfill';
import { DEFAULT_FRAME } from '../../overlay/frame';
import { GlobalOverlayAdmin } from '../../admin/alerts-global-admin';
import { parseGlobalOverlay, globalOverlayApi } from '../api/alerts-global';
import { OverlayApiContext } from '../sections/alerts/overlay-api-context';
import { mockApi, response } from '../testing/api';
import { ORIGIN, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PATH = '/merchboard/v1/admin/alerts/global-overlay';
const URL_G = `${ ORIGIN }/merchboard-alerts/global-overlay/${ 'c'.repeat(
	32
) }/`;

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
	template: '$Product was purchased by $UserName!',
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
	text_fills: {
		main_text: DEFAULT_FILL,
		secondary_text: DEFAULT_FILL,
	},
	text_frame: DEFAULT_FRAME,
	font_family: 'inherit',
};

function body( enabled: boolean | null ) {
	return {
		overlay:
			enabled === null
				? null
				: {
						url: URL_G,
						settings: SETTINGS,
						font_approved: true,
						layout: LAYOUT,
						audio: null,
						images: null,
						enabled,
					},
		alerts_enabled: true,
		merchalert_active: false,
		fonts: [],
	};
}

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

describe( 'the global overlay (step 25.9.4)', () => {
	it( 'reads the creator overlay shape plus whether it is on', () => {
		const state = parseGlobalOverlay( body( true ) );

		expect( state?.enabled ).toBe( true );
		expect( state?.state.overlay?.url ).toBe( URL_G );

		expect( parseGlobalOverlay( body( null ) )?.enabled ).toBe( false );
		expect(
			parseGlobalOverlay( {
				...body( true ),
				overlay: { ...body( true ).overlay, enabled: 'yes' },
			} )
		).toBeNull();
	} );

	it( 'uses its own routes, never the creator overlay', async () => {
		const requests = mockApi( {
			[ `POST ${ PATH }/test` ]: () => response( 200, { sent: true } ),
		} );

		const result = await globalOverlayApi.sendTest();

		expect( result.ok ).toBe( true );
		expect( requests.map( ( r ) => r.path ) ).toEqual( [
			`${ PATH }/test`,
		] );
	} );

	it( 'switches the overlay and replays the latest order', async () => {
		const requests = mockApi( {
			[ `GET ${ PATH }` ]: () => response( 200, body( false ) ),
			[ `POST ${ PATH }/enabled` ]: () => response( 200, body( true ) ),
			[ `POST ${ PATH }/replay` ]: () => response( 200, { sent: 2 } ),
		} );

		rendered = render(
			<OverlayApiContext.Provider value={ globalOverlayApi }>
				<GlobalOverlayAdmin />
			</OverlayApiContext.Provider>
		);
		await flush();
		await flush();

		const container = rendered.container;

		expect( container.textContent ).toContain( 'Global overlay' );
		expect( container.textContent ).toContain( 'Off.' );
		click(
			Array.from( container.querySelectorAll( 'button' ) ).find(
				( button ) => button.textContent === 'Switch on'
			) ?? null
		);
		await flush();
		expect( container.textContent ).toContain( 'On.' );
		expect(
			requests.find( ( r ) => r.path === `${ PATH }/enabled` )?.data
		).toEqual( {
			enabled: true,
		} );

		await act( async () => {
			Array.from( container.querySelectorAll( 'button' ) )
				.find(
					( button ) => button.textContent === 'Replay latest order'
				)
				?.click();
			await Promise.resolve();
		} );
		await flush();
		expect( container.textContent ).toContain( '2 alerts' );
		expect( requests.every( ( r ) => r.path.startsWith( PATH ) ) ).toBe(
			true
		);
	} );
} );
