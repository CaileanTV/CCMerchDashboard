import { afterEach, describe, expect, it } from '@jest/globals';

import {
	OVERLAY_VARIABLES,
	TemplateVariables,
	describeVariable,
} from '../sections/alerts/TemplateVariables';
import { render } from '../testing/helpers';
import type { Rendered } from '../testing/helpers';

let rendered: Rendered | null = null;

afterEach( () => {
	rendered?.unmount();
	rendered = null;
} );

describe( 'TemplateVariables', () => {
	it( 'explains every overlay variable, folded up', () => {
		rendered = render(
			<TemplateVariables variables={ OVERLAY_VARIABLES } />
		);
		const details = rendered.container.querySelector( 'details' );

		expect( details?.open ).toBe( false );
		expect(
			Array.from( rendered.container.querySelectorAll( 'dt' ) ).map(
				( term ) => term.textContent
			)
		).toEqual( [ ...OVERLAY_VARIABLES ] );
		expect( describeVariable( '$Total' ) ).toContain(
			'everything from you in the order'
		);
	} );

	it( 'leaves out variables it cannot explain, and shows nothing for none', () => {
		rendered = render(
			<TemplateVariables variables={ [ '$Products', '$OrderNumber' ] } />
		);

		expect(
			Array.from( rendered.container.querySelectorAll( 'dt' ) ).map(
				( term ) => term.textContent
			)
		).toEqual( [ '$Products' ] );

		rendered.unmount();
		rendered = render( <TemplateVariables variables={ [ '$Unknown' ] } /> );
		expect( rendered.container.innerHTML ).toBe( '' );
	} );
} );
