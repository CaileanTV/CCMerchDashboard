import { afterEach, describe, expect, it, jest } from '@jest/globals';

import { parseDiscountCode, parseResetDiscountCode } from '../api/discountCode';
import { ConfigContext } from '../app/config-context';
import { DiscountCodeCard } from '../sections/profile/DiscountCodeCard';
import { mockApi, response } from '../testing/api';
import { CONFIG, click, flush, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const PATH = 'GET /merchboard/v1/me/discount-code';
const CREATE = 'POST /merchboard/v1/me/discount-code';
const RESET = 'POST /merchboard/v1/me/discount-code/reset';

/**
 * A refusal from the server.
 *
 * @param reason Reason code.
 */
const refused = ( reason: string ) =>
	response( 400, {
		code: 'merchboard_code_refused',
		message: 'Server <b>detail</b>',
		data: { status: 400, reason },
	} );

describe( 'response validation', () => {
	it( 'accepts a code and null', () => {
		expect( parseDiscountCode( { code: 'nova10' } ) ).toEqual( {
			code: 'nova10',
		} );
		expect( parseDiscountCode( { code: null } ) ).toEqual( {
			code: null,
		} );
	} );

	it.each( [
		null,
		[],
		{},
		{ code: '' },
		{ code: 42 },
		{ code: 'x'.repeat( 192 ) },
	] )( 'rejects malformed bodies %#', ( body ) => {
		expect( parseDiscountCode( body ) ).toBeNull();
	} );

	it( 'requires a code after a reset', () => {
		expect( parseResetDiscountCode( { code: 'NOVA-7K3Q' } ) ).toEqual( {
			code: 'NOVA-7K3Q',
		} );
		expect( parseResetDiscountCode( { code: null } ) ).toBeNull();
		expect( parseResetDiscountCode( {} ) ).toBeNull();
	} );
} );

describe( 'DiscountCodeCard', () => {
	let rendered: Rendered | null = null;

	afterEach( () => {
		rendered?.unmount();
		rendered = null;
	} );

	const button = ( label: string ) =>
		Array.from(
			rendered?.container.querySelectorAll( 'button' ) ?? []
		).find( ( element ) => element.textContent === label ) ?? null;

	it( 'does not request or show the code until asked, and hides it again', async () => {
		const requests = mockApi( {
			[ PATH ]: () => response( 200, { code: 'nova10' } ),
		} );

		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<DiscountCodeCard />
			</ConfigContext.Provider>
		);
		await flush();

		expect( requests ).toHaveLength( 0 );
		expect( rendered.container.textContent ).not.toContain( 'nova10' );
		expect( rendered.container.textContent ).toContain(
			'do not count in your sales and your profit'
		);

		click( button( 'Show discount code' ) );
		await flush();

		expect( requests ).toHaveLength( 1 );
		expect( rendered.container.querySelector( 'code' )?.textContent ).toBe(
			'nova10'
		);

		click( button( 'Hide discount code' ) );
		await flush();

		expect( rendered.container.textContent ).not.toContain( 'nova10' );
		expect( button( 'Show discount code' ) ).not.toBeNull();
	} );

	it( 'creates and shows the first code when the creator has none', async () => {
		const requests = mockApi( {
			[ PATH ]: () => response( 200, { code: null } ),
			[ CREATE ]: () => response( 200, { code: 'nova-7k3qxp' } ),
		} );

		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<DiscountCodeCard />
			</ConfigContext.Provider>
		);
		click( button( 'Show discount code' ) );
		await flush();

		expect( requests ).toHaveLength( 2 );
		expect( rendered.container.querySelector( 'code' )?.textContent ).toBe(
			'nova-7k3qxp'
		);
	} );

	it( 'says so when an administrator ended the code', async () => {
		mockApi( {
			[ PATH ]: () => response( 200, { code: null } ),
			[ CREATE ]: () => refused( 'code_ended' ),
		} );

		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<DiscountCodeCard />
			</ConfigContext.Provider>
		);
		click( button( 'Show discount code' ) );
		await flush();

		expect( rendered.container.textContent ).toContain(
			'Your discount code has been ended.'
		);
		expect( rendered.container.querySelector( 'code' ) ).toBeNull();
	} );

	it( 'shows the standard error when the first code cannot be created', async () => {
		mockApi( {
			[ PATH ]: () => response( 200, { code: null } ),
			[ CREATE ]: () => refused( 'coupon_not_created' ),
		} );

		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<DiscountCodeCard />
			</ConfigContext.Provider>
		);
		click( button( 'Show discount code' ) );
		await flush();

		expect( button( 'Show discount code' ) ).toBeNull();
		expect( rendered.container.querySelector( 'code' ) ).toBeNull();
		expect( rendered.container.textContent ).not.toContain( 'detail' );
	} );

	it( 'shows the standard error with a retry and never the server message', async () => {
		let calls = 0;
		mockApi( {
			[ PATH ]: () => {
				calls++;
				return calls === 1
					? response( 500, { message: 'Internal <b>detail</b>' } )
					: response( 200, { code: 'nova10' } );
			},
		} );

		rendered = render(
			<ConfigContext.Provider value={ CONFIG }>
				<DiscountCodeCard />
			</ConfigContext.Provider>
		);
		click( button( 'Show discount code' ) );
		await flush();

		expect( rendered.container.textContent ).not.toContain( 'detail' );
		expect( rendered.container.querySelector( 'code' ) ).toBeNull();

		click( button( 'Try again' ) );
		await flush();

		expect( rendered.container.querySelector( 'code' )?.textContent ).toBe(
			'nova10'
		);
	} );

	const card = () =>
		render(
			<ConfigContext.Provider value={ CONFIG }>
				<DiscountCodeCard />
			</ConfigContext.Provider>
		);

	it( 'resets only after the confirmation and shows the new code when it was shown', async () => {
		const requests = mockApi( {
			[ PATH ]: () => response( 200, { code: 'NOVA-AAAA' } ),
			[ RESET ]: () => response( 200, { code: 'NOVA-BBBB' } ),
		} );

		rendered = card();
		click( button( 'Show discount code' ) );
		await flush();
		click( button( 'Reset and generate a new discount code' ) );
		await flush();

		expect( requests ).toHaveLength( 1 );
		expect( rendered.container.textContent ).toContain(
			'The old code stops working at once.'
		);

		click( button( 'Cancel' ) );
		await flush();

		expect( button( 'Reset discount code' ) ).toBeNull();

		click( button( 'Reset and generate a new discount code' ) );
		click( button( 'Reset discount code' ) );
		await flush();

		expect( requests ).toHaveLength( 2 );
		expect( rendered.container.querySelector( 'code' )?.textContent ).toBe(
			'NOVA-BBBB'
		);
		expect(
			rendered.container.querySelector( '[role="status"]' )?.textContent
		).toContain( 'The old code no longer works.' );
	} );

	it( 'resets a hidden code without showing the new one', async () => {
		const requests = mockApi( {
			[ RESET ]: () => response( 200, { code: 'NOVA-BBBB' } ),
		} );

		rendered = card();
		click( button( 'Reset and generate a new discount code' ) );
		click( button( 'Reset discount code' ) );
		await flush();

		expect( requests ).toHaveLength( 1 );
		expect( rendered.container.textContent ).not.toContain( 'NOVA-BBBB' );
		expect( rendered.container.textContent ).toContain(
			'when no one else can see your screen'
		);
	} );

	it( 'gives a creator without a code the first one instead of a reset', async () => {
		const requests = mockApi( {
			[ RESET ]: () => refused( 'no_active_code' ),
			[ CREATE ]: () => response( 200, { code: 'nova-7k3qxp' } ),
		} );

		rendered = card();
		click( button( 'Reset and generate a new discount code' ) );
		click( button( 'Reset discount code' ) );
		await flush();

		expect( requests ).toHaveLength( 2 );
		expect( rendered.container.textContent ).not.toContain( 'nova-7k3qxp' );
		expect(
			rendered.container.querySelector( '[role="status"]' )?.textContent
		).toContain( 'Your discount code is ready.' );
		expect(
			rendered.container.querySelector( '[role="status"]' )?.textContent
		).not.toContain( 'old code' );
	} );

	it( 'does not bring back a code an administrator ended', async () => {
		mockApi( {
			[ RESET ]: () => refused( 'no_active_code' ),
			[ CREATE ]: () => refused( 'code_ended' ),
		} );

		rendered = card();
		click( button( 'Reset and generate a new discount code' ) );
		click( button( 'Reset discount code' ) );
		await flush();

		expect(
			rendered.container.querySelector( '[role="alert"]' )?.textContent
		).toContain( 'Your discount code has been ended.' );
	} );

	it.each( [
		[ 'reset_limit', '3 times in the last 24 hours' ],
		[ 'busy', 'Try again in a moment.' ],
		[ 'coupon_not_created', 'Nothing was changed; try again later.' ],
	] )(
		'explains the refusal %s with a fixed text',
		async ( reason, text ) => {
			mockApi( { [ RESET ]: () => refused( reason ) } );

			rendered = card();
			click( button( 'Reset and generate a new discount code' ) );
			click( button( 'Reset discount code' ) );
			await flush();

			expect(
				rendered.container.querySelector( '[role="alert"]' )
					?.textContent
			).toContain( text );
			expect( rendered.container.textContent ).not.toContain( 'detail' );
			expect(
				button( 'Reset and generate a new discount code' )
			).not.toBeNull();
		}
	);
} );
