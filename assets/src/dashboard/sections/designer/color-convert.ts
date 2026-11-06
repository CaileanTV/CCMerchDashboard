/**
 * Colour formats for the background colour (ADR-095). The formulas are the
 * owner's AOP background module (Lumise snippet 31658); CMYK has no colour
 * profile (ICC), so a printed colour can differ a little.
 */

/** A triple of 0–255. */
export type Rgb = readonly [ number, number, number ];

/** Four percentages of 0–100. */
export type Cmyk = readonly [ number, number, number, number ];

/**
 * A number in a range; not a number gives the lowest.
 *
 * @param value Value.
 * @param lo    Lowest.
 * @param hi    Highest.
 */
function clamp( value: number | string, lo: number, hi: number ): number {
	const number = Number( value );

	return Number.isNaN( number ) ? lo : Math.max( lo, Math.min( hi, number ) );
}

/**
 * "#abc", "abc" or "#AABBCC" as "#aabbcc", or null.
 *
 * @param value Value.
 */
export function normHex( value: string ): string | null {
	let hex = value.trim().replace( /^#/, '' );

	if ( /^[0-9a-f]{3}$/i.test( hex ) ) {
		hex = hex.replace( /(.)/g, '$1$1' );
	}

	return /^[0-9a-f]{6}$/i.test( hex ) ? `#${ hex.toLowerCase() }` : null;
}

/**
 * "#05707a" as [ 5, 112, 122 ], or null.
 *
 * @param hex Colour.
 */
export function hexToRgb( hex: string ): Rgb | null {
	const normal = normHex( hex );

	return normal === null
		? null
		: [
				parseInt( normal.slice( 1, 3 ), 16 ),
				parseInt( normal.slice( 3, 5 ), 16 ),
				parseInt( normal.slice( 5, 7 ), 16 ),
			];
}

/**
 * Red, green and blue (0–255) as "#rrggbb".
 *
 * @param red   Red.
 * @param green Green.
 * @param blue  Blue.
 */
export function rgbToHex(
	red: number | string,
	green: number | string,
	blue: number | string
): string {
	return `#${ [ red, green, blue ]
		.map( ( value ) =>
			Math.round( clamp( value, 0, 255 ) )
				.toString( 16 )
				.padStart( 2, '0' )
		)
		.join( '' ) }`;
}

/**
 * CMYK in percent as RGB (0–255), without a colour profile.
 *
 * @param cyan    Cyan.
 * @param magenta Magenta.
 * @param yellow  Yellow.
 * @param black   Black (key).
 */
export function cmykToRgb(
	cyan: number | string,
	magenta: number | string,
	yellow: number | string,
	black: number | string
): Rgb {
	const [ c, m, y, k ] = [ cyan, magenta, yellow, black ].map(
		( value ) => clamp( value, 0, 100 ) / 100
	) as [ number, number, number, number ];

	return [
		255 * ( 1 - c ) * ( 1 - k ),
		255 * ( 1 - m ) * ( 1 - k ),
		255 * ( 1 - y ) * ( 1 - k ),
	];
}

/**
 * RGB (0–255) as CMYK in whole percent.
 *
 * @param red   Red.
 * @param green Green.
 * @param blue  Blue.
 */
export function rgbToCmyk( red: number, green: number, blue: number ): Cmyk {
	const [ r, g, b ] = [ red, green, blue ].map(
		( value ) => clamp( value, 0, 255 ) / 255
	) as [ number, number, number ];
	const k = 1 - Math.max( r, g, b );

	if ( k >= 1 ) {
		return [ 0, 0, 0, 100 ];
	}

	return [
		Math.round( ( ( 1 - r - k ) / ( 1 - k ) ) * 100 ),
		Math.round( ( ( 1 - g - k ) / ( 1 - k ) ) * 100 ),
		Math.round( ( ( 1 - b - k ) / ( 1 - k ) ) * 100 ),
		Math.round( k * 100 ),
	];
}
