import { afterEach, describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';

import {
	createPayout,
	parsePayout,
	parsePayoutBalance,
	parsePayoutGiftCard,
	parseSettlementDocument,
} from '../api/payouts';
import { ConfigContext } from '../app/config-context';
import { resolvePage } from '../app/pages';
import { PayoutsSection } from '../sections/payouts/PayoutsSection';
import { listResponse, mockApi, response } from '../testing/api';
import type { RecordedRequest, Route } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const LIST = '/merchboard/v1/me/payouts?page=1&per_page=20';
const BALANCE = '/merchboard/v1/me/payouts/balance';
const CREATE = '/merchboard/v1/me/payouts';

const PENDING = {
	id: 31,
	status: 'pending',
	currency: 'DKK',
	amount: '25.5000',
	method: 'bank',
	destination_hint: '•••• 5678',
	requested_at: '2026-11-30T12:00:00Z',
	decided_at: null,
	rejection_reason: null,
	gift_card: false,
	settlement_document: null,
};

const REJECTED = {
	...PENDING,
	id: 30,
	status: 'rejected',
	amount: '10.0000',
	method: 'paypal',
	destination_hint: 'j•••@g•••.com',
	requested_at: '2026-11-20T09:00:00Z',
	decided_at: '2026-11-21T10:00:00Z',
	rejection_reason: 'The account is closed.',
};

const DKK = {
	currency: 'DKK',
	status: 'complete',
	earned: '60.0000',
	completed: '0.0000',
	reserved: '25.5000',
	available: '34.5000',
	outstanding: '0.0000',
	unknown_rows: 0,
};

const BALANCE_BODY = {
	currencies: [ DKK ],
	holding_days: 14,
	payout_start: null,
	methods: [ 'bank', 'paypal', 'gift_card' ],
	methods_usable: true,
	gift_card_usable: false,
};

function money( amount: string ): string {
	return new Intl.NumberFormat( 'da-DK', {
		style: 'currency',
		currency: 'DKK',
	} ).format( Number( amount ) );
}

function plain( text: string | null | undefined ): string {
	return ( text ?? '' ).replace( /\s/gu, ' ' );
}

function refused( status: number, reason: string ) {
	return response( status, {
		code: 'merchboard_payout_refused',
		message: 'Server text that is never shown.',
		data: { status, reason },
	} );
}

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
		[ `GET ${ LIST }` ]: () => listResponse( [ PENDING, REJECTED ], 2, 1 ),
		...routes,
	} );
	rendered = render(
		<ConfigContext.Provider value={ CONFIG }>
			<PayoutsSection />
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

async function submit( container: HTMLElement ): Promise< void > {
	act( () => {
		container
			.querySelector( 'form' )
			?.dispatchEvent(
				new Event( 'submit', { bubbles: true, cancelable: true } )
			);
	} );
	await flush();
	await flush();
}

function fillBank( container: HTMLElement, amount: string ): void {
	type( input( container, 'Amount' ), amount );
	type( input( container, 'Registration number' ), '1234' );
	type( input( container, 'Account number' ), '0012345678' );
}

describe( 'parsePayout and parsePayoutBalance', () => {
	it( 'read the documented fields only', () => {
		expect( parsePayout( { ...PENDING, account: '0012345678' } ) ).toEqual(
			{
				id: 31,
				status: 'pending',
				currency: 'DKK',
				amount: '25.5000',
				method: 'bank',
				destinationHint: '•••• 5678',
				requestedAt: '2026-11-30T12:00:00Z',
				decidedAt: null,
				rejectionReason: null,
				giftCard: false,
				settlementDocument: null,
			}
		);
		expect( parsePayout( { ...PENDING, status: 'issuing' } ) ).toBeNull();
		expect( parsePayout( { ...PENDING, amount: 25.5 } ) ).toBeNull();
		expect( parsePayout( { ...PENDING, id: 0 } ) ).toBeNull();
		expect(
			parsePayout( { ...PENDING, gift_card: undefined } )
		).toBeNull();
	} );

	it( 'never accept an unknown balance with amounts or a known one without', () => {
		const unknown = {
			...DKK,
			status: 'unknown',
			earned: null,
			available: null,
			outstanding: null,
			unknown_rows: 2,
		};

		expect(
			parsePayoutBalance( { ...BALANCE_BODY, currencies: [ unknown ] } )
				?.currencies[ 0 ]?.available
		).toBeNull();
		expect(
			parsePayoutBalance( {
				...BALANCE_BODY,
				currencies: [ { ...unknown, available: '0.0000' } ],
			} )
		).toBeNull();
		expect(
			parsePayoutBalance( {
				...BALANCE_BODY,
				currencies: [ { ...DKK, available: null } ],
			} )
		).toBeNull();
		expect(
			parsePayoutBalance( { ...BALANCE_BODY, methods_usable: 'yes' } )
		).toBeNull();
		expect(
			parsePayoutBalance( { ...BALANCE_BODY, gift_card_usable: null } )
		).toBeNull();
	} );

	it( 'reads a gift card with its documented fields only', () => {
		const card = {
			code: 'MB-T23-CODE',
			amount: '150.0000',
			balance: '50.0000',
			currency: 'DKK',
			status: 'active',
			expires_at: null,
		};

		expect( parsePayoutGiftCard( { ...card, id: 9 } ) ).toEqual( {
			code: 'MB-T23-CODE',
			amount: '150.0000',
			balance: '50.0000',
			currency: 'DKK',
			status: 'active',
			expiresAt: null,
		} );
		expect( parsePayoutGiftCard( { ...card, code: '' } ) ).toBeNull();
		expect( parsePayoutGiftCard( { ...card, balance: 50 } ) ).toBeNull();
		expect( parsePayoutGiftCard( { ...card, balance: null } ) ).toBeNull();
		expect(
			parsePayoutGiftCard( { ...card, status: 'issuing' } )
		).toBeNull();
	} );

	it( 'passes the refusal reason, never the server message', async () => {
		mockApi( {
			[ `POST ${ CREATE }` ]: () =>
				refused( 409, 'insufficient_balance' ),
		} );

		const result = await createPayout( {
			currency: 'DKK',
			amount: '1',
			details: { method: 'paypal', email: 'a@b.dk' },
			idempotencyKey: 'key-0001',
		} );

		expect( result ).toEqual( {
			ok: false,
			error: {
				kind: 'server',
				status: 409,
				code: 'merchboard_payout_refused',
				fields: {},
				reason: 'insufficient_balance',
			},
		} );
	} );
} );

describe( 'PayoutsSection', () => {
	it( 'is the section of the payouts path', () => {
		expect(
			resolvePage( 'payouts', [
				{ id: 'payouts', label: 'Payouts', path: 'payouts' },
			] ).title
		).toBe( 'Payouts' );
	} );

	it( 'shows the balance, the facts and the creator’s own requests with masked details only', async () => {
		const { container } = await show( {} );
		const text = plain( container.textContent );

		expect(
			Array.from( container.querySelectorAll( '.merchboard-kpi' ) ).map(
				( kpi ) => [
					plain(
						kpi.querySelector( '.merchboard-kpi__label' )
							?.textContent
					),
					plain(
						kpi.querySelector( '.merchboard-kpi__value' )
							?.textContent
					),
				]
			)
		).toEqual( [
			[ 'Available', plain( money( '34.5' ) ) ],
			[ 'Requested, pending', plain( money( '25.5' ) ) ],
			[ 'Paid out', plain( money( '0' ) ) ],
			[ 'Earned after the holding period', plain( money( '60' ) ) ],
		] );
		expect( text ).toContain( 'holding period of 14 days' );
		expect( text ).toContain( 'All your sales count.' );
		expect( text ).toContain( 'Payout methods: bank transfer and PayPal.' );

		const rows = Array.from( container.querySelectorAll( 'tbody tr' ) ).map(
			( tr ) => plain( tr.textContent )
		);
		expect( rows[ 0 ] ).toContain( 'Bank transfer •••• 5678' );
		expect( rows[ 0 ] ).toContain( 'Pending' );
		expect( rows[ 0 ] ).toContain( 'Cancel request' );
		expect( rows[ 1 ] ).toContain( 'PayPal j•••@g•••.com' );
		expect( rows[ 1 ] ).toContain( 'Rejected The account is closed.' );
		expect( rows[ 1 ] ).not.toContain( 'Cancel request' );
		expect( text ).not.toContain( '0012345678' );
	} );

	it( 'shows an unknown balance as unknown, never as 0', async () => {
		const { container } = await show( {
			[ `GET ${ BALANCE }` ]: () =>
				response( 200, {
					...BALANCE_BODY,
					currencies: [
						{
							...DKK,
							status: 'unknown',
							earned: null,
							available: null,
							outstanding: null,
							unknown_rows: 2,
						},
					],
				} ),
		} );
		const available = container.querySelector(
			'.merchboard-kpi--available .merchboard-kpi__value'
		);

		expect( available?.textContent ).toBe( 'Unknown' );
		expect( plain( container.textContent ) ).toContain(
			'Your balance is unknown: 2 sales lack the data'
		);
		expect( plain( container.textContent ) ).toContain(
			'There is nothing to request yet.'
		);
		expect( container.querySelector( 'form' ) ).toBeNull();
	} );

	it( 'offers no form when bank and PayPal are unavailable', async () => {
		const { container } = await show( {
			[ `GET ${ BALANCE }` ]: () =>
				response( 200, { ...BALANCE_BODY, methods_usable: false } ),
		} );

		expect( container.querySelector( 'form' ) ).toBeNull();
		expect( plain( container.textContent ) ).toContain(
			'Bank transfer and PayPal are not available right now'
		);
	} );

	it( 'offers a gift card only when it is usable, and requests it without details', async () => {
		const { container, requests } = await show( {
			[ `GET ${ BALANCE }` ]: () =>
				response( 200, {
					...BALANCE_BODY,
					methods_usable: false,
					gift_card_usable: true,
				} ),
			[ `POST ${ CREATE }` ]: () =>
				response( 201, { ...PENDING, method: 'gift_card' } ),
		} );
		const radios = Array.from(
			container.querySelectorAll( 'input[type="radio"]' )
		).map( ( radio ) => ( radio as HTMLInputElement ).value );

		expect( radios ).toEqual( [ 'gift_card' ] );
		expect( input( container, 'Account number' ) ).toBeNull();
		expect( plain( container.textContent ) ).toContain(
			'You can also choose a gift card for the shop.'
		);

		type( input( container, 'Amount' ), '20' );
		await submit( container );

		expect( requests.find( ( r ) => r.method === 'POST' )?.data ).toEqual(
			expect.objectContaining( {
				amount: '20',
				method: 'gift_card',
				details: {},
			} )
		);
	} );

	it( 'shows the gift card of a completed payout only on request, masked until shown', async () => {
		const completed = {
			...PENDING,
			id: 32,
			status: 'completed',
			method: 'gift_card',
			destination_hint: '',
			decided_at: '2026-12-01T10:00:00Z',
			gift_card: true,
		};
		let answer = response( 200, {
			code: 'MB-T23-SECRET',
			amount: '25.5000',
			balance: '25.5000',
			currency: 'DKK',
			status: 'active',
			expires_at: null,
		} );
		const { container, requests } = await show( {
			[ `GET ${ LIST }` ]: () =>
				listResponse( [ completed, PENDING ], 2, 1 ),
			[ `GET /merchboard/v1/me/payouts/32/gift-card` ]: () => answer,
		} );
		const button = ( label: string ) =>
			Array.from( container.querySelectorAll( 'button' ) ).find(
				( candidate ) => candidate.textContent === label
			) ?? null;
		const cardRequests = () =>
			requests.filter( ( r ) => r.path.endsWith( '/gift-card' ) );

		expect( cardRequests() ).toHaveLength( 0 );
		expect( button( 'Show gift card' ) ).not.toBeNull();
		expect(
			Array.from( container.querySelectorAll( 'button' ) ).filter(
				( candidate ) => candidate.textContent === 'Show gift card'
			)
		).toHaveLength( 1 );

		click( button( 'Show gift card' ) );
		await flush();
		await flush();

		expect( cardRequests() ).toHaveLength( 1 );
		expect( plain( container.textContent ) ).not.toContain(
			'MB-T23-SECRET'
		);
		expect( plain( container.textContent ) ).toContain( '••••••••' );

		click( button( 'Show code' ) );
		expect( plain( container.textContent ) ).toContain( 'MB-T23-SECRET' );
		expect( JSON.stringify( { ...window.localStorage } ) ).not.toContain(
			'MB-T23-SECRET'
		);

		click( button( 'Hide gift card' ) );
		expect( plain( container.textContent ) ).not.toContain(
			'MB-T23-SECRET'
		);

		answer = refused( 503, 'gift_cards_unavailable' );
		click( button( 'Show gift card' ) );
		await flush();
		await flush();

		expect( plain( container.textContent ) ).toContain(
			'The gift card cannot be shown right now.'
		);
	} );

	it( 'shows the settlement document only on request, as issued and marked preliminary', async () => {
		const completed = {
			...PENDING,
			id: 33,
			status: 'completed',
			decided_at: '2026-12-01T10:00:00Z',
			settlement_document: 'AFR-000001',
		};
		const document = {
			number: 'AFR-000001',
			type: 'settlement',
			preliminary: true,
			issued_at: '2026-12-01T10:00:00Z',
			period_from: null,
			period_to: '2026-12-01T10:00:00Z',
			currency: 'DKK',
			profit: '60.0000',
			commission: '60.0000',
			opening_balance: '0.0000',
			payout: '25.5000',
			closing_balance: '34.5000',
			vat_registered: true,
			creator_name: 'Mine',
			company_name: 'CCMerch',
			company_address: [ 'Street 1', '1000 København' ],
			method: 'bank',
			destination: '•••• 5678',
			rows: [
				{
					order_id: 101,
					item_name: 'Tee',
					type: 'sale',
					quantity: 1,
					net: '80.0000',
					tax: '20.0000',
					payout: '20.0000',
				},
			],
			corrections: [
				{
					number: 'AFR-000002',
					issued_at: '2026-12-02T10:00:00Z',
					reason: 'Name misspelled.',
				},
			],
		};
		const { container, requests } = await show( {
			[ `GET ${ LIST }` ]: () => listResponse( [ completed ], 1, 1 ),
			[ `GET /merchboard/v1/me/payouts/33/settlement-document` ]: () =>
				response( 200, document ),
		} );
		const button = ( label: string ) =>
			Array.from( container.querySelectorAll( 'button' ) ).find(
				( candidate ) => candidate.textContent === label
			) ?? null;

		expect(
			requests.filter( ( r ) =>
				r.path.endsWith( '/settlement-document' )
			)
		).toHaveLength( 0 );

		click( button( 'Settlement document AFR-000001' ) );
		await flush();
		await flush();

		const text = plain( container.textContent );
		expect( text ).toContain(
			'Preliminary, not approved by an auditor or SKAT.'
		);
		expect( text ).toContain(
			'Self-billing: issued by CCMerch on behalf of Mine.'
		);
		expect( text ).toContain( plain( money( '34.5' ) ) );
		expect( text ).toContain( 'Corrected by AFR-000002' );
		expect(
			container.querySelectorAll(
				'.merchboard-settlement__rows tbody tr'
			)
		).toHaveLength( 1 );
		expect( text ).not.toContain( 'Tax and reporting' );
		expect(
			parseSettlementDocument( { ...document, preliminary: 'yes' } )
		).toBeNull();
		expect(
			parseSettlementDocument( { ...document, payout: 25.5 } )
		).toBeNull();

		click( button( 'Close' ) );
		expect(
			container.querySelector( '.merchboard-settlement' )
		).toBeNull();
	} );

	it( 'shows a new document with the note on tax and reporting and downloads its PDF', async () => {
		const completed = {
			...PENDING,
			id: 34,
			status: 'completed',
			decided_at: '2026-12-01T10:00:00Z',
			settlement_document: 'AFR-000003',
		};
		const document = {
			number: 'AFR-000003',
			type: 'settlement',
			preliminary: false,
			issued_at: '2026-12-01T10:00:00Z',
			period_from: '2026-11-01T00:00:00Z',
			period_to: '2026-12-01T10:00:00Z',
			currency: 'DKK',
			profit: '20.0000',
			commission: '20.0000',
			opening_balance: '0.0000',
			payout: '20.0000',
			closing_balance: '0.0000',
			vat_registered: null,
			creator_name: 'Mine',
			company_name: 'CCMerch',
			company_address: [],
			method: 'paypal',
			destination: 'j•••@g•••.com',
			rows: [],
			corrections: [],
		};
		const pdf = new Blob( [ '%PDF-1.4' ], { type: 'application/pdf' } );
		let pdfType = 'application/pdf';
		const createObjectURL = jest.fn< ( file: Blob ) => string >(
			() => 'blob:merchboard/1'
		);
		const revokeObjectURL = jest.fn< ( url: string ) => void >();
		const seen: { attached: boolean; href: string; name: string }[] = [];
		const clicked = jest
			.spyOn( HTMLAnchorElement.prototype, 'click' )
			.mockImplementation( function ( this: HTMLAnchorElement ) {
				seen.push( {
					attached: this.isConnected,
					href: this.getAttribute( 'href' ) ?? '',
					name: this.download,
				} );
			} );
		Object.assign( window.URL, { createObjectURL, revokeObjectURL } );

		try {
			const { container, requests } = await show( {
				[ `GET ${ LIST }` ]: () => listResponse( [ completed ], 1, 1 ),
				[ `GET /merchboard/v1/me/payouts/34/settlement-document` ]:
					() => response( 200, document ),
				[ `GET /merchboard/v1/me/payouts/34/settlement-document/pdf` ]:
					() => ( {
						...response( 200, null, { 'Content-Type': pdfType } ),
						blob: () => Promise.resolve( pdf ),
					} ),
			} );
			const button = ( label: string ) =>
				Array.from( container.querySelectorAll( 'button' ) ).find(
					( candidate ) => candidate.textContent === label
				) ?? null;

			click( button( 'Settlement document AFR-000003' ) );
			await flush();
			await flush();

			const text = plain( container.textContent );
			expect( text ).toContain( 'Tax and reporting' );
			expect( text ).toContain(
				'You are responsible for finding out about and meeting your own tax and reporting obligations to the Danish Tax Agency.'
			);
			expect( text ).toContain( 'It is not tax advice' );
			expect( text ).not.toContain( 'Preliminary' );

			jest.useFakeTimers();
			click( button( 'Download PDF' ) );
			await flush();
			await flush();

			expect(
				requests.filter( ( r ) =>
					r.path.endsWith( '/settlement-document/pdf' )
				)
			).toHaveLength( 1 );
			expect( createObjectURL ).toHaveBeenCalledWith( pdf );
			expect( seen ).toEqual( [
				{
					attached: true,
					href: 'blob:merchboard/1',
					name: 'AFR-000003.pdf',
				},
			] );
			expect( window.document.querySelector( 'a[download]' ) ).toBeNull();
			expect( revokeObjectURL ).not.toHaveBeenCalled();

			jest.advanceTimersByTime( 1000 );

			expect( revokeObjectURL ).toHaveBeenCalledWith(
				'blob:merchboard/1'
			);
			jest.useRealTimers();
			expect( text ).not.toContain( 'The PDF cannot be downloaded' );

			// Anything but a PDF is never saved.
			pdfType = 'text/html';
			click( button( 'Download PDF' ) );
			await flush();
			await flush();

			expect( clicked ).toHaveBeenCalledTimes( 1 );
			expect( plain( container.textContent ) ).toContain(
				'The PDF cannot be downloaded right now. Please try again later.'
			);
		} finally {
			// Also when an expectation failed while the fake timers were on.
			jest.useRealTimers();
			clicked.mockRestore();
		}
	} );

	it( 'sends a request once with a key, empties the details and reloads balance and list', async () => {
		const { container, requests } = await show( {
			[ `POST ${ CREATE }` ]: () => response( 201, PENDING ),
		} );
		fillBank( container, '25,50' );

		await submit( container );

		const post = requests.find( ( r ) => r.method === 'POST' );
		expect( post?.data ).toEqual( {
			currency: 'DKK',
			amount: '25.50',
			method: 'bank',
			details: { registration: '1234', account: '0012345678' },
			idempotency_key: expect.stringMatching( /^[A-Za-z0-9-]{8,64}$/ ),
		} );
		expect( plain( container.textContent ) ).toContain(
			'Your request has been sent.'
		);
		expect(
			( input( container, 'Account number' ) as HTMLInputElement ).value
		).toBe( '' );
		expect( requests.filter( ( r ) => r.path === BALANCE ) ).toHaveLength(
			2
		);
		expect( requests.filter( ( r ) => r.path === LIST ) ).toHaveLength( 2 );
	} );

	it( 'sends "all" for the full amount and PayPal with an email address', async () => {
		const { container, requests } = await show( {
			[ `POST ${ CREATE }` ]: () => response( 201, REJECTED ),
		} );
		click( input( container, 'Request the full available amount' ) );
		click( input( container, 'PayPal' ) );
		type( input( container, 'PayPal email address' ), ' jane@example.dk ' );

		await submit( container );

		expect( requests.find( ( r ) => r.method === 'POST' )?.data ).toEqual(
			expect.objectContaining( {
				amount: 'all',
				method: 'paypal',
				details: { email: 'jane@example.dk' },
			} )
		);
	} );

	it( 'shows refusals as fixed texts and uses a new key after one', async () => {
		let answer = refused( 409, 'insufficient_balance' );
		const { container, requests } = await show( {
			[ `POST ${ CREATE }` ]: () => answer,
		} );
		fillBank( container, '999' );

		await submit( container );

		const text = plain( container.textContent );
		expect( text ).toContain(
			'The amount is more than you can request right now.'
		);
		expect( text ).not.toContain( 'Server text' );

		answer = refused( 429, 'too_many_pending' );
		await submit( container );
		expect( plain( container.textContent ) ).toContain(
			'You already have 3 pending requests.'
		);

		const keys = requests
			.filter( ( r ) => r.method === 'POST' )
			.map(
				( r ) =>
					( r.data as { idempotency_key: string } ).idempotency_key
			);
		expect( keys ).toHaveLength( 2 );
		expect( keys[ 0 ] ).not.toBe( keys[ 1 ] );
	} );

	it( 'keeps the key after a failure that may have been stored', async () => {
		let answer = response( 500, { code: 'merchboard_internal_error' } );
		const { container, requests } = await show( {
			[ `POST ${ CREATE }` ]: () => answer,
		} );
		fillBank( container, '5' );

		await submit( container );
		expect( plain( container.textContent ) ).toContain(
			'Something went wrong.'
		);

		answer = response( 201, PENDING );
		await submit( container );

		const keys = requests
			.filter( ( r ) => r.method === 'POST' )
			.map(
				( r ) =>
					( r.data as { idempotency_key: string } ).idempotency_key
			);
		expect( keys[ 0 ] ).toBe( keys[ 1 ] );
	} );

	it( 'cancels a pending request in two steps and shows a refusal', async () => {
		let answer = response( 200, { ...PENDING, status: 'cancelled' } );
		const { container, requests } = await show( {
			[ `POST /merchboard/v1/me/payouts/31/cancel` ]: () => answer,
		} );
		const button = () =>
			Array.from( container.querySelectorAll( 'button' ) ).find(
				( candidate ) => candidate.textContent === 'Cancel request'
			) ?? null;
		const confirm = () =>
			Array.from( container.querySelectorAll( 'button' ) ).find(
				( candidate ) =>
					candidate.textContent === 'Confirm cancellation'
			) ?? null;

		click( button() );
		expect(
			requests.filter( ( r ) => r.path.endsWith( '/cancel' ) )
		).toHaveLength( 0 );
		click( confirm() );
		await flush();
		await flush();

		expect(
			requests.filter( ( r ) => r.path.endsWith( '/cancel' ) )
		).toHaveLength( 1 );
		expect( plain( container.textContent ) ).toContain(
			'The request has been cancelled.'
		);

		answer = refused( 409, 'not_pending' );
		click( button() );
		click( confirm() );
		await flush();
		await flush();

		expect( plain( container.textContent ) ).toContain(
			'The request has already been handled'
		);
	} );
} );
