import { afterEach, describe, expect, it } from '@jest/globals';
import { act } from 'react';

import { ToastStack, useToastStack } from '../components/ToastStack';
import { click, render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

let notify: ( text: string ) => void = () => undefined;
let rendered: Rendered | null = null;

function Harness() {
	const stack = useToastStack();
	notify = stack.notify;

	return <ToastStack toasts={ stack.toasts } onDismiss={ stack.dismiss } />;
}

const messages = () =>
	Array.from(
		rendered?.container.querySelectorAll( '.merchboard-toast__message' ) ??
			[]
	).map( ( element ) => element.textContent );

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

describe( 'ToastStack', () => {
	it( 'stacks different messages and shows the same message only once', () => {
		rendered = render( <Harness /> );

		act( () => notify( 'Settings saved.' ) );
		act( () => notify( 'URL copied.' ) );
		act( () => notify( 'Settings saved.' ) );

		expect( messages() ).toEqual( [ 'URL copied.', 'Settings saved.' ] );
		expect(
			rendered.container.querySelectorAll( '.merchboard-toast-region' )
		).toHaveLength( 1 );

		act( () => notify( 'A' ) );
		act( () => notify( 'B' ) );
		expect( messages() ).toEqual( [ 'Settings saved.', 'A', 'B' ] );

		click(
			rendered.container.querySelector( '.merchboard-toast__dismiss' )
		);
		expect( messages() ).toEqual( [ 'A', 'B' ] );
	} );
} );
