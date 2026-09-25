/**
 * Test double for `@wordpress/api-fetch`. Test files mock the module with
 * `jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn() } ) )`
 * and route requests here.
 */
import apiFetch from '@wordpress/api-fetch';
import type { jest } from '@jest/globals';

export interface FakeResponse {
	readonly status: number;
	readonly ok: boolean;
	json: () => Promise< unknown >;
}

export interface RecordedRequest {
	readonly path: string;
	readonly method: string;
	readonly data: unknown;
}

/**
 * A fetch Response stand-in.
 *
 * @param status HTTP status.
 * @param body   JSON body.
 */
export function response( status: number, body: unknown ): FakeResponse {
	return {
		status,
		ok: status >= 200 && status < 300,
		json: () => Promise.resolve( body ),
	};
}

export type Route = (
	request: RecordedRequest
) => FakeResponse | Promise< FakeResponse >;

/**
 * Routes mocked apiFetch calls by "METHOD path". Non-2xx responses reject,
 * like apiFetch with `parse: false`; unknown routes answer 404.
 *
 * @param routes Handlers by "GET /merchboard/v1/me".
 * @return Requests made, in order.
 */
export function mockApi(
	routes: Readonly< Record< string, Route > >
): RecordedRequest[] {
	const requests: RecordedRequest[] = [];
	const mocked = apiFetch as unknown as jest.Mock<
		( options: unknown ) => Promise< unknown >
	>;

	mocked.mockImplementation( async ( options: unknown ) => {
		const {
			path = '',
			method = 'GET',
			data,
		} = options as { path?: string; method?: string; data?: unknown };
		const recorded = { path, method, data };
		requests.push( recorded );

		const route = routes[ `${ method } ${ path }` ];
		const result =
			route === undefined
				? response( 404, { code: 'rest_no_route' } )
				: await route( recorded );

		if ( ! result.ok ) {
			throw result;
		}

		return result;
	} );

	return requests;
}

export const ME = { display_name: 'Nova', status: 'active' } as const;

export const PROFILE = {
	display_name: 'Nova',
	bio: 'Merch & music',
	social_links: {
		website: 'https://nova.example',
		instagram: '',
		tiktok: '',
		youtube: '',
		x: '',
		facebook: '',
	},
} as const;
