import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import { parseBillingDetails, parseCvrValues } from '../api/billing';
import { ConfigContext } from '../app/config-context';
import { RouterProvider } from '../app/router';
import { BillingDetailsNotice } from '../components/BillingDetailsNotice';
import { PayoutsSection } from '../sections/payouts/PayoutsSection';
import { listResponse, mockApi, response } from '../testing/api';
import type { RecordedRequest, Route } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const DETAILS = '/merchboard/v1/me/billing-details';
const LIST = '/merchboard/v1/me/payouts?page=1&per_page=20';
const BALANCE = '/merchboard/v1/me/payouts/balance';

const EMPTY = {
	legal_name: '',
	address_1: '',
	address_2: '',
	postcode: '',
	city: '',
	country: '',
	cvr: '',
	type: '',
};

const MISSING = {
	status: 'missing',
	confirmed: null,
	confirmed_at: null,
	required_for_payout: true,
	payout_waiting: false,
	cpr_required: false,
	cpr_saved: false,
	cvr_lookup: true,
};

const NOVA = {
	...EMPTY,
	legal_name: 'Nova ApS',
	address_1: 'Nørregade 2',
	postcode: '8000',
	city: 'Aarhus C',
	country: 'Danmark',
	cvr: '12345678',
	type: 'business',
};

const SAVED = {
	...MISSING,
	status: 'confirmed',
	confirmed: NOVA,
	confirmed_at: '2026-11-09T12:00:00Z',
	required_for_payout: false,
};

const BALANCE_BODY = {
	currencies: [
		{
			currency: 'DKK',
			status: 'complete',
			earned: '60.0000',
			completed: '0.0000',
			reserved: '0.0000',
			available: '60.0000',
			outstanding: '0.0000',
			unknown_rows: 0,
		},
	],
	holding_days: 14,
	payout_start: null,
	methods: [ 'bank', 'paypal', 'gift_card' ],
	methods_usable: true,
	gift_card_usable: false,
};

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

async function show(
	routes: Readonly< Record< string, Route > >
): Promise< { container: HTMLElement; requests: RecordedRequest[] } > {
	const requests = mockApi( {
		[ `GET ${ BALANCE }` ]: () => response( 200, BALANCE_BODY ),
		[ `GET ${ LIST }` ]: () => listResponse( [], 0, 0 ),
		...routes,
	} );
	rendered = render(
		<ConfigContext.Provider value={ CONFIG }>
			<RouterProvider>
				<PayoutsSection />
			</RouterProvider>
		</ConfigContext.Provider>
	);
	await flush();
	await flush();

	return { container: rendered.container, requests };
}

function type( element: Element | null, value: string ): void {
	if ( ! ( element instanceof HTMLInputElement ) ) {
		throw new Error( 'Input not found.' );
	}

	const setter = Object.getOwnPropertyDescriptor(
		Object.getPrototypeOf( element ),
		'value'
	)?.set;

	act( () => {
		setter?.call( element, value );
		element.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

function input( container: HTMLElement, label: string ): Element | null {
	const found = Array.from( container.querySelectorAll( 'label' ) ).find(
		( candidate ) => candidate.textContent?.trim() === label
	);

	return found === undefined
		? null
		: container.querySelector( `[id="${ found.htmlFor }"]` );
}

function billingForm( container: HTMLElement ): HTMLFormElement {
	const form = container.querySelector( '.merchboard-billing-form' );

	if ( ! ( form instanceof HTMLFormElement ) ) {
		throw new Error( 'No master data form.' );
	}

	return form;
}

async function submit( form: Element | null ): Promise< void > {
	act( () => {
		form?.dispatchEvent(
			new Event( 'submit', { bubbles: true, cancelable: true } )
		);
	} );
	await flush();
	await flush();
}

describe( 'parseBillingDetails', () => {
	it( 'reads the documented fields', () => {
		expect( parseBillingDetails( SAVED )?.confirmed ).toEqual( NOVA );
		expect( parseBillingDetails( SAVED )?.cprSaved ).toBe( false );
		expect( parseBillingDetails( MISSING )?.cvrLookup ).toBe( true );
		expect(
			parseBillingDetails( { ...MISSING, cvr_lookup: undefined } )
				?.cvrLookup
		).toBe( false );
	} );

	it.each( [
		null,
		{ ...MISSING, status: 'submitted' },
		{ ...MISSING, cpr_required: 'yes' },
		{ ...MISSING, cpr_saved: undefined },
		{ ...MISSING, confirmed: { ...NOVA, type: 'company' } },
	] )( 'rejects %p', ( body ) => {
		expect( parseBillingDetails( body ) ).toBeNull();
	} );

	it( 'reads a CVR lookup answer', () => {
		expect(
			parseCvrValues( {
				values: {
					legal_name: 'Nova ApS',
					address_1: 'Nørregade 2',
					postcode: '8000',
					city: 'Aarhus C',
					country: 'Danmark',
				},
			} )?.legal_name
		).toBe( 'Nova ApS' );
		expect( parseCvrValues( { values: { legal_name: 1 } } ) ).toBeNull();
	} );
} );

describe( 'Master data for settlement documents (ADR-104)', () => {
	it( 'explains the CPR number and recommends a PMV for a private person', async () => {
		const { container } = await show( {
			[ `GET ${ DETAILS }` ]: () => response( 200, MISSING ),
		} );
		const text = container.textContent ?? '';

		expect( text ).toContain( 'Why do we need your CPR number?' );
		expect( text ).toContain( 'personligt ejet mindre virksomhed, PMV' );
		expect( text ).toContain( 'It is free' );
		expect( text ).toContain( 'stored encrypted' );
		expect( input( container, 'CVR number' ) ).toBeNull();
		expect( input( container, 'CPR number' ) ).not.toBeNull();
	} );

	it( 'keeps the CVR number of master data registered before the type existed', async () => {
		const { container } = await show( {
			[ `GET ${ DETAILS }` ]: () =>
				response( 200, {
					...SAVED,
					confirmed: { ...NOVA, type: '' },
				} ),
		} );

		expect(
			(
				input(
					container,
					'I am a registered business with a CVR number'
				) as HTMLInputElement
			 ).checked
		).toBe( true );
		expect(
			( input( container, 'CVR number' ) as HTMLInputElement ).value
		).toBe( '12345678' );
		expect( input( container, 'CPR number' ) ).toBeNull();
	} );

	it( 'checks the form first and saves every field with the type at once', async () => {
		const { container, requests } = await show( {
			[ `GET ${ DETAILS }` ]: () => response( 200, MISSING ),
			[ `POST ${ DETAILS }` ]: () => response( 200, SAVED ),
		} );

		click(
			input( container, 'I am a registered business with a CVR number' )
		);
		await submit( billingForm( container ) );
		expect(
			requests.filter( ( request ) => request.method === 'POST' )
		).toHaveLength( 0 );
		expect( container.textContent ).toContain(
			'Please fill in this field.'
		);

		type( input( container, 'CVR number' ), '1234 5678' );
		type( input( container, 'Name or company name' ), 'Nova ApS' );
		type( input( container, 'Address' ), 'Nørregade 2' );
		type( input( container, 'Postcode' ), '8000' );
		type( input( container, 'City' ), 'Aarhus C' );
		await submit( billingForm( container ) );

		const post = requests.find( ( request ) => request.method === 'POST' );
		expect( post?.data ).toEqual( {
			...NOVA,
			cvr: '1234 5678',
			country: 'Denmark',
		} );
		expect( container.textContent ).toContain( 'Master data saved.' );
		expect( container.textContent ).toContain(
			'Your master data are saved.'
		);
		expect( container.textContent ).not.toContain( 'confirm' );
	} );

	it( 'asks a private person for the CPR number in the master data', async () => {
		const { container, requests } = await show( {
			[ `GET ${ DETAILS }` ]: () => response( 200, MISSING ),
			[ `POST ${ DETAILS }` ]: () =>
				response( 200, {
					...SAVED,
					confirmed: { ...NOVA, type: 'private', cvr: '' },
					cpr_required: true,
					cpr_saved: true,
				} ),
		} );

		type( input( container, 'Name or company name' ), 'Nova Jensen' );
		type( input( container, 'Address' ), 'Nørregade 2' );
		type( input( container, 'Postcode' ), '8000' );
		type( input( container, 'City' ), 'Aarhus C' );
		await submit( billingForm( container ) );
		expect(
			requests.filter( ( request ) => request.method === 'POST' )
		).toHaveLength( 0 );

		type( input( container, 'CPR number' ), '12345' );
		await submit( billingForm( container ) );
		expect( container.textContent ).toContain(
			'Please enter a valid CPR number'
		);

		type( input( container, 'CPR number' ), '010190-1234' );
		await submit( billingForm( container ) );

		const post = requests.find( ( request ) => request.method === 'POST' );
		expect( post?.data ).toMatchObject( {
			type: 'private',
			cvr: '',
			legal_name: 'Nova Jensen',
			cpr: '010190-1234',
		} );
		expect(
			( input( container, 'CPR number' ) as HTMLInputElement ).value
		).toBe( '' );
		expect( container.textContent ).toContain(
			'Your CPR number is stored encrypted and is not shown.'
		);
	} );

	it( 'saves a private person again without typing a stored CPR number', async () => {
		const { container, requests } = await show( {
			[ `GET ${ DETAILS }` ]: () =>
				response( 200, {
					...SAVED,
					confirmed: { ...NOVA, type: 'private', cvr: '' },
					cpr_required: true,
					cpr_saved: true,
				} ),
			[ `POST ${ DETAILS }` ]: () => response( 200, SAVED ),
		} );

		type( input( container, 'City' ), 'Aarhus N' );
		await submit( billingForm( container ) );

		const post = requests.find( ( request ) => request.method === 'POST' );
		expect( post?.data ).toMatchObject( {
			type: 'private',
			city: 'Aarhus N',
		} );
		expect( post?.data ).not.toHaveProperty( 'cpr' );
	} );

	it( 'fills the form from the CVR register', async () => {
		const { container, requests } = await show( {
			[ `GET ${ DETAILS }` ]: () => response( 200, MISSING ),
			[ `GET ${ DETAILS }/cvr/12345678` ]: () =>
				response( 200, {
					values: {
						legal_name: 'Nova ApS',
						address_1: 'Nørregade 2',
						postcode: '8000',
						city: 'Aarhus C',
						country: 'Danmark',
					},
				} ),
		} );

		click(
			input( container, 'I am a registered business with a CVR number' )
		);
		type( input( container, 'CVR number' ), '12345678' );
		const button = Array.from(
			container.querySelectorAll( 'button' )
		).find(
			( candidate ) =>
				candidate.textContent === 'Fill in from the CVR number'
		);
		click( button ?? null );
		await flush();
		await flush();

		expect(
			requests.some(
				( request ) => request.path === `${ DETAILS }/cvr/12345678`
			)
		).toBe( true );
		expect(
			( input( container, 'Name or company name' ) as HTMLInputElement )
				.value
		).toBe( 'Nova ApS' );
	} );

	it( 'never asks for the CPR number with a payout', async () => {
		const { container } = await show( {
			[ `GET ${ DETAILS }` ]: () =>
				response( 200, {
					...SAVED,
					confirmed: { ...NOVA, type: 'private', cvr: '' },
					cpr_required: true,
					cpr_saved: true,
				} ),
		} );

		const payoutForm = container.querySelector(
			'form:not(.merchboard-billing-form)'
		);
		expect( payoutForm ).not.toBeNull();
		expect( payoutForm?.querySelector( '[name="cpr"]' ) ).toBeNull();
	} );
} );

describe( 'BillingDetailsNotice', () => {
	async function notice( body: unknown ): Promise< string > {
		mockApi( {
			[ `GET ${ DETAILS }` ]: () => response( 200, body ),
		} );
		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<RouterProvider>
					<BillingDetailsNotice />
				</RouterProvider>
			</ConfigContext.Provider>
		);
		await flush();
		await flush();

		return rendered.container.textContent ?? '';
	}

	it( 'asks for master data before the first payout', async () => {
		expect( await notice( MISSING ) ).toContain(
			'Please fill in your master data'
		);
	} );

	it( 'is hidden once they are saved or not needed', async () => {
		expect( await notice( SAVED ) ).toBe( '' );
		rendered?.unmount();
		expect(
			await notice( { ...MISSING, required_for_payout: false } )
		).toBe( '' );
	} );
} );
