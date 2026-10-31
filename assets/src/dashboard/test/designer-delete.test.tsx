/**
 * Tests for the confirmation before a design is deleted.
 */
import { describe, expect, it, jest } from '@jest/globals';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { ConfirmDelete } from '../sections/designer/ConfirmDelete';

/**
 * Renders the dialog.
 *
 * @param onConfirm Deletes.
 * @param permanent Whether it is for good.
 */
function render(
	onConfirm: () => Promise< boolean >,
	permanent = false
): HTMLButtonElement {
	const container = document.createElement( 'div' );

	document.body.append( container );
	act( () => {
		createRoot( container ).render(
			<ConfirmDelete
				title="Stream tee"
				inShop
				permanent={ permanent }
				onConfirm={ onConfirm }
				onClose={ jest.fn() }
			/>
		);
	} );

	return Array.from( document.querySelectorAll( 'button' ) ).find(
		( button ) => button.className.includes( 'danger' )
	) as HTMLButtonElement;
}

/**
 * Types in the dialog's field.
 *
 * @param value The text.
 */
function type( value: string ): void {
	const input = document.querySelector< HTMLInputElement >(
		'.merchboard-dialog input'
	);

	act( () => {
		Object.getOwnPropertyDescriptor(
			HTMLInputElement.prototype,
			'value'
		)?.set?.call( input, value );
		input?.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

describe( 'the confirmation of a deletion', () => {
	it( 'needs the word', async () => {
		const onConfirm = jest.fn( () => Promise.resolve( true ) );
		const button = render( onConfirm );

		expect( button.disabled ).toBe( true );

		type( 'dele' );
		expect( button.disabled ).toBe( true );

		type( 'delete' );
		expect( button.disabled ).toBe( false );

		await act( async () => {
			button.click();
			await Promise.resolve();
		} );

		expect( onConfirm ).toHaveBeenCalledTimes( 1 );
		document.body.replaceChildren();
	} );

	it( 'says that for good is for good', () => {
		render( () => Promise.resolve( true ), true );

		expect( document.body.textContent ).toContain(
			'removed for good and cannot be brought back'
		);
		document.body.replaceChildren();
	} );
} );
