import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { DEFAULT_ANIMATION } from '../../overlay/animations';
import { DEFAULT_FILL } from '../../overlay/textfill';
import { DEFAULT_FRAME } from '../../overlay/frame';
import {
	PageSetupEditor,
	moveCard,
	moveField,
} from '../../admin/alerts-page-admin';
import { parseAlertsOverlay } from '../api/alerts';
import { ConfigContext } from '../app/config-context';
import { AlertsSection } from '../sections/alerts/AlertsSection';
import {
	DEFAULT_PAGE_LAYOUT,
	pageLayoutBody,
	parsePageLayout,
} from '../sections/alerts/page-layout';
import type { PageLayout } from '../sections/alerts/page-layout';
import { mockApi, response } from '../testing/api';
import { CONFIG, ORIGIN, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const ADMIN = '/merchboard/v1/admin/alerts/creator-page';

const SETTINGS = {
	template: '$Product was purchased by $UserName!',
	secondary_template: '',
	font_size: 32,
	font_weight: '700',
	text_color: '#ffffff',
	text_shadow: '',
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

const BOX = { visible: true, lock_aspect: false, border_radius: 0 };

const LAYOUT = {
	elements: {
		product_image: { x: 40, y: 880, w: 120, h: 120, ...BOX },
		main_text: { x: 176, y: 895, w: 700, h: 80, ...BOX },
		secondary_text: { x: 176, y: 985, w: 700, h: 50, ...BOX },
	},
	canvas: { snap_grid: false, grid_size: 10 },
};

const SETUP = {
	version: 1,
	cards: {
		full: [ 'timing' ],
		left: [ 'alert_text', 'designs' ],
		right: [
			'browser_source',
			'sounds',
			'text_style',
			'box_image',
			'animations',
		],
	},
	fields: {
		alert_text: [ 'show_secondary', 'template', 'secondary_template' ],
		text_style: [ ...DEFAULT_PAGE_LAYOUT.fields.text_style ],
		box_image: [
			'bg_color',
			'bg_opacity',
			'border_radius',
			'padding',
			'image_size',
		],
		animations: [ 'main_text', 'secondary_text' ],
		timing: [ 'anim_duration', 'display_duration', 'display_from_sound' ],
	},
	designs_compact: true,
};

const overlay = ( page: unknown ) => ( {
	overlay: {
		url: `${ ORIGIN }/merchboard-alerts/overlay/${ 'a'.repeat( 32 ) }/`,
		settings: SETTINGS,
		layout: LAYOUT,
		font_approved: true,
		audio: null,
		images: null,
	},
	alerts_enabled: true,
	merchalert_active: false,
	fonts: [],
	page,
} );

const DESIGN = {
	id: 'abcdefabcdefabcd',
	name: 'Gaming',
	saved_at: '2026-10-23 10:00:00',
	settings: SETTINGS,
	font_approved: true,
	layout: LAYOUT,
	images: null,
};

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
	jest.useRealTimers();
} );

const titles = ( selector: string ) =>
	Array.from(
		rendered?.container.querySelectorAll(
			`${ selector } > .merchboard-card .merchboard-card__title, ${ selector } > section.merchboard-card > .merchboard-card__title`
		) ?? []
	).map( ( element ) => element.textContent );

describe( 'the creator page setup', () => {
	it( 'is always complete, like the server', () => {
		expect( parsePageLayout( null ) ).toEqual( DEFAULT_PAGE_LAYOUT );

		const layout = parsePageLayout( {
			cards: {
				full: [ 'timing', 'unknown', 7 ],
				left: [ 'designs', 'timing', 'images' ],
				right: [ 'alert_text', 'box_image' ],
			},
			fields: {
				timing: [ 'anim_duration', 'anim_duration', 'nope' ],
				alert_text: 'not a list',
			},
			designs_compact: 'yes',
		} );

		// The same answer as CreatorPageLayoutTest (PHP).
		expect( layout.cards ).toEqual( {
			full: [ 'timing' ],
			left: [ 'browser_source', 'sounds', 'designs', 'text_style' ],
			right: [ 'alert_text', 'animations', 'box_image' ],
		} );
		expect( layout.fields.timing ).toEqual( [
			'display_from_sound',
			'display_duration',
			'anim_duration',
		] );
		expect( layout.fields.alert_text ).toEqual(
			DEFAULT_PAGE_LAYOUT.fields.alert_text
		);
		expect( layout.designsCompact ).toBe( false );
		expect( parsePageLayout( pageLayoutBody( layout ) ) ).toEqual( layout );
	} );

	it( 'is read with the overlay only when the server sends it', () => {
		const state = parseAlertsOverlay( overlay( SETUP ) );
		expect( state?.page?.cards.full ).toEqual( [ 'timing' ] );

		const { page: _page, ...withoutPage } = overlay( SETUP );
		expect( parseAlertsOverlay( withoutPage ) ).not.toHaveProperty(
			'page'
		);
	} );

	it( "places the creator's cards and fields as set up, the designer last, and keeps Designs compact", async () => {
		mockApi( {
			'GET /merchboard/v1/me/alerts/overlay': () =>
				response( 200, overlay( SETUP ) ),
			'GET /merchboard/v1/me/alerts/designs': () =>
				response( 200, { designs: [ DESIGN ], max: 10 } ),
			'GET /merchboard/v1/me/alerts/presets': () =>
				response( 200, { presets: [] } ),
		} );
		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<AlertsSection />
			</ConfigContext.Provider>
		);
		await flush();
		await flush();

		const container = rendered.container;
		expect( titles( '.merchboard-alerts__full' ) ).toEqual( [ 'Timing' ] );
		const [ left, right ] = Array.from(
			container.querySelectorAll( '.merchboard-alerts__column' )
		);
		const cardTitles = ( column: Element | undefined ) =>
			Array.from(
				column?.querySelectorAll( '.merchboard-card__title' ) ?? []
			).map( ( element ) => element.textContent );
		expect( cardTitles( left ) ).toEqual( [ 'Alert text', 'Designs' ] );
		expect( cardTitles( right ) ).toEqual( [
			'Browser source',
			'Text style',
			'Box and image',
			'Animations and text fill',
		] );

		// The fields in the set-up order.
		const timing = container.querySelector( '.merchboard-alerts__full' );
		expect(
			Array.from(
				timing?.querySelectorAll(
					'.merchboard-field__label, .merchboard-alerts__check'
				) ?? []
			).map( ( element ) => element.textContent?.trim() )
		).toEqual( [
			'Animation time (ms)',
			'Display time (ms)',
			'Show the alert as long as its sound plays',
		] );
		const alertText = left?.querySelector( '.merchboard-card' );
		expect(
			alertText?.firstElementChild?.nextElementSibling?.className
		).toBe( 'merchboard-alerts__check' );

		// The designer stays last, after every card.
		const form = container.querySelector( '.merchboard-alerts > form' );
		const designer = container.querySelector( '.merchboard-designer' );
		expect( form?.contains( designer ) ).toBe( false );
		expect(
			designer === null
				? 0
				: ( form?.compareDocumentPosition( designer ) ?? 0 )
		).toBe( Node.DOCUMENT_POSITION_FOLLOWING );

		// Compact: the preview opens on request.
		const toggle = Array.from(
			left?.querySelectorAll( 'button' ) ?? []
		).find( ( button ) => button.textContent === 'Show preview' );
		expect( toggle?.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		const preview = container.querySelector(
			`[id="${ toggle?.getAttribute( 'aria-controls' ) }"]`
		);
		expect( preview?.hasAttribute( 'hidden' ) ).toBe( true );
		click( toggle ?? null );
		expect( toggle?.textContent ).toBe( 'Hide preview' );
		expect( preview?.hasAttribute( 'hidden' ) ).toBe( false );
	} );
} );

describe( 'the creator page tab', () => {
	it( 'moves cards and fields', () => {
		const moved = moveCard( DEFAULT_PAGE_LAYOUT, 'timing', 'full', 0 );
		expect( moved.cards.full ).toEqual( [ 'timing', 'designs' ] );
		expect( moved.cards.right ).toEqual( [ 'box_image', 'animations' ] );
		expect(
			moveField( DEFAULT_PAGE_LAYOUT, 'timing', 'anim_duration', 0 )
				.fields.timing
		).toEqual( [
			'anim_duration',
			'display_from_sound',
			'display_duration',
		] );
	} );

	it( 'saves every move made with the buttons or by dragging, and resets', async () => {
		const saved: unknown[] = [];
		const requests = mockApi( {
			[ `POST ${ ADMIN }` ]: ( request ) => {
				saved.push( request.data );

				return response( 200, {
					version: 1,
					...( request.data as object ),
				} );
			},
			[ `DELETE ${ ADMIN }` ]: () =>
				response( 200, pageLayoutBody( DEFAULT_PAGE_LAYOUT ) ),
		} );
		rendered = render(
			<PageSetupEditor initial={ DEFAULT_PAGE_LAYOUT } />
		);
		const container = rendered.container;
		const item = ( name: string ) =>
			Array.from(
				container.querySelectorAll( '.merchboard-page-setup__card' )
			).find(
				( element ) =>
					element.querySelector( '.merchboard-page-setup__name' )
						?.textContent === name
			);
		const button = ( parent: Element | undefined, text: string ) =>
			Array.from( parent?.querySelectorAll( 'button' ) ?? [] ).find(
				( element ) => element.textContent === text
			) ?? null;

		// Move down in the left column.
		click( button( item( 'Browser source' ), 'Move down' ) );
		await flush();
		expect(
			( saved[ 0 ] as { cards: PageLayout[ 'cards' ] } ).cards
		).toEqual( {
			full: [ 'designs' ],
			left: [ 'sounds', 'browser_source', 'alert_text', 'text_style' ],
			right: [ 'box_image', 'animations', 'timing' ],
		} );
		expect(
			container.querySelector( '[role="status"]' )?.textContent
		).toBe( 'Browser source: Left column, position 2.' );

		// Another place.
		const select = item( 'Timing' )?.querySelector( 'select' );
		act( () => {
			if ( select ) {
				select.value = 'full';
				select.dispatchEvent(
					new Event( 'change', { bubbles: true } )
				);
			}
		} );
		await flush();
		expect(
			( saved[ 1 ] as { cards: PageLayout[ 'cards' ] } ).cards.full
		).toEqual( [ 'designs', 'timing' ] );

		// A field, after opening the card's fields.
		click( button( item( 'Timing' ), 'Fields' ) );
		const fields = item( 'Timing' )?.querySelectorAll(
			'.merchboard-page-setup__fields li'
		);
		expect( fields ).toHaveLength( 3 );
		click( button( fields?.[ 2 ], 'Move up' ) );
		await flush();
		expect(
			( saved[ 2 ] as { fields: PageLayout[ 'fields' ] } ).fields.timing
		).toEqual( [
			'display_from_sound',
			'anim_duration',
			'display_duration',
		] );

		// Dragging a card onto another one.
		const dragEvent = ( type: string ) => {
			const event = new Event( type, {
				bubbles: true,
				cancelable: true,
			} );
			Object.defineProperty( event, 'dataTransfer', {
				value: { setData: () => undefined },
			} );

			return event;
		};
		act( () => {
			item( 'Text style' )?.dispatchEvent( dragEvent( 'dragstart' ) );
		} );
		act( () => {
			item( 'Sounds' )?.dispatchEvent( dragEvent( 'dragover' ) );
			item( 'Sounds' )?.dispatchEvent( dragEvent( 'drop' ) );
		} );
		await flush();
		expect(
			( saved[ 3 ] as { cards: PageLayout[ 'cards' ] } ).cards.left
		).toEqual( [ 'text_style', 'sounds', 'browser_source', 'alert_text' ] );

		// Compact Designs.
		const compact = item( 'Designs' )?.querySelector(
			'input[type="checkbox"]'
		);
		click( compact ?? null );
		await flush();
		expect(
			( saved[ 4 ] as { designs_compact: boolean } ).designs_compact
		).toBe( true );

		jest.spyOn( window, 'confirm' ).mockReturnValue( true );
		click(
			button(
				container.querySelector( '.merchboard-page-setup' ) ??
					undefined,
				'Reset to default'
			)
		);
		await flush();
		await flush();
		expect( requests.at( -1 )?.method ).toBe( 'DELETE' );
		expect( item( 'Timing' )?.closest( 'section' )?.textContent ).toContain(
			'Right column'
		);
	} );
} );
