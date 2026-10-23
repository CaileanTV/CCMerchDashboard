import {
	afterEach,
	beforeEach,
	describe,
	expect,
	it,
	jest,
} from '@jest/globals';
import { act } from 'react';

import { LEAVE_MS, ToastStack, useToastStack } from '../components/ToastStack';
import { click, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

let notify: ( text: string ) => void = () => undefined;
let rendered: Rendered | null = null;

function Harness() {
	const stack = useToastStack();
	notify = stack.notify;

	return <ToastStack toasts={ stack.toasts } onDismiss={ stack.dismiss } />;
}

const messages = ( selector = '.merchboard-toast' ) =>
	Array.from( rendered?.container.querySelectorAll( selector ) ?? [] ).map(
		( element ) =>
			element.querySelector( '.merchboard-toast__message' )?.textContent
	);

const fadeOut = () => act( () => jest.advanceTimersByTime( LEAVE_MS ) );

beforeEach( () => {
	jest.useFakeTimers();
} );

afterEach( () => {
	rendered?.unmount();
	rendered = null;
	jest.useRealTimers();
} );

describe( 'ToastStack', () => {
	it( 'stacks every message, also the same one again, and a fourth visibly closes the oldest', () => {
		rendered = render( <Harness /> );

		act( () => notify( 'Settings saved.' ) );
		act( () => notify( 'URL copied.' ) );
		act( () => notify( 'Settings saved.' ) );

		expect( messages() ).toEqual( [
			'Settings saved.',
			'URL copied.',
			'Settings saved.',
		] );
		expect(
			rendered.container.querySelectorAll( '.merchboard-toast-region' )
		).toHaveLength( 1 );

		// The fourth: the oldest fades out while the newest comes in.
		act( () => notify( 'Settings saved.' ) );
		expect( messages( '.merchboard-toast--leaving' ) ).toEqual( [
			'Settings saved.',
		] );
		expect(
			messages( '.merchboard-toast:not(.merchboard-toast--leaving)' )
		).toEqual( [ 'URL copied.', 'Settings saved.', 'Settings saved.' ] );
		expect(
			rendered.container
				.querySelector( '.merchboard-toast--leaving' )
				?.getAttribute( 'aria-hidden' )
		).toBe( 'true' );

		fadeOut();
		expect( messages() ).toEqual( [
			'URL copied.',
			'Settings saved.',
			'Settings saved.',
		] );

		click(
			rendered.container.querySelector( '.merchboard-toast__dismiss' )
		);
		fadeOut();
		expect( messages() ).toEqual( [
			'Settings saved.',
			'Settings saved.',
		] );
	} );

	it( 'closes a message after its time', () => {
		rendered = render( <Harness /> );

		act( () => notify( 'Settings saved.' ) );
		act( () => jest.advanceTimersByTime( 6000 ) );
		expect( messages( '.merchboard-toast--leaving' ) ).toHaveLength( 1 );

		fadeOut();
		expect( messages() ).toEqual( [] );
	} );
} );
