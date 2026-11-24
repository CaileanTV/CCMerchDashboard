/**
 * Tests for the angle a design object is stored with (2.5.2): the server
 * takes -360 to 360, and an object turned a little anticlockwise was read
 * back as -384°, so the design could not be saved.
 */
import { describe, expect, it, jest } from '@jest/globals';

import { uprightAngle } from '../sections/designer/design-model';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

describe( 'the stored angle', () => {
	it.each( [
		[ -24, -24 ],
		[ -384, -24 ],
		[ 24, 24 ],
		[ 336, -24 ],
		[ 180, 180 ],
		[ -180, 180 ],
		[ 181, -179 ],
		[ 360, 0 ],
		[ -360, 0 ],
		[ 720.5, 0.5 ],
		[ -90, -90 ],
		[ 270, -90 ],
	] )( 'keeps %p° as %p°, within -180 to 180', ( angle, stored ) => {
		const upright = uprightAngle( angle );

		expect( upright ).toBeCloseTo( stored, 6 );
		expect( upright ).toBeGreaterThan( -180.0001 );
		expect( upright ).toBeLessThanOrEqual( 180 );
	} );
} );
