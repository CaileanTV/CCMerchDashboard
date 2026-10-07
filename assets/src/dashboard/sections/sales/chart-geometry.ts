/**
 * Geometry of the net sales chart (ADR-041): columns from a zero baseline,
 * one per period, in a fixed viewBox. Pure and deterministic.
 *
 * Amounts are decimal strings and are never turned into JavaScript numbers:
 * they are parsed to BigInt units of 1/10000 and scaled with integer
 * arithmetic. Only the resulting viewBox coordinates (bounded by the viewBox)
 * are numbers, so large amounts cannot overflow or lose precision in the
 * layout, a series of zeros never divides by zero, and a single period is one
 * column.
 */

/** Width of one period's slot in viewBox units. */
export const SLOT = 10;

/** Width of a column in viewBox units (the rest of the slot is the gap). */
export const COLUMN = 7;

/** Height of the plot in viewBox units. */
export const HEIGHT = 100;

/** Smallest drawn height of a non-zero column, so it stays visible. */
export const MIN_COLUMN = 0.75;

/** Coordinates are rounded to 1/100 viewBox unit. */
const PRECISION = 100n;

const AMOUNT = /^(-?)(\d+)\.(\d{4})$/;

export interface ChartColumn {
	/** Index of the period. */
	readonly index: number;
	readonly x: number;
	readonly y: number;
	readonly width: number;
	readonly height: number;
	/** The amount is below zero (drawn below the baseline). */
	readonly negative: boolean;
}

export interface ChartGeometry {
	/** viewBox width: one slot per period. */
	readonly width: number;
	readonly height: number;
	/** y of the zero baseline. */
	readonly baseline: number;
	/** Columns of the non-zero periods. */
	readonly columns: readonly ChartColumn[];
	/** Index of the period with the largest amount, if above zero. */
	readonly maxIndex: number | null;
	/** Index of the period with the smallest amount, if below zero. */
	readonly minIndex: number | null;
}

/**
 * A four-decimal amount in BigInt units of 1/10000.
 *
 * @param  amount Decimal string, e.g. "-12.5000".
 * @throws {Error} When the string is not a four-decimal amount.
 */
export function toUnits( amount: string ): bigint {
	const match = AMOUNT.exec( amount );

	if ( match === null ) {
		throw new Error( 'Not a four-decimal amount.' );
	}

	const units = BigInt( `${ match[ 2 ] }${ match[ 3 ] }` );

	return match[ 1 ] === '-' ? -units : units;
}

/**
 * `numerator / denominator` of BigInts as a number with two decimals; the
 * caller guarantees the result lies within the viewBox.
 *
 * @param numerator   Numerator.
 * @param denominator Denominator (> 0).
 */
function ratio( numerator: bigint, denominator: bigint ): number {
	return (
		Number( ( numerator * PRECISION ) / denominator ) / Number( PRECISION )
	);
}

/**
 * The columns of a series of amounts.
 *
 * @param amounts Net sales per period, as decimal strings.
 */
export function chartGeometry( amounts: readonly string[] ): ChartGeometry {
	const units = amounts.map( toUnits );
	let top = 0n;
	let bottom = 0n;
	let maxIndex: number | null = null;
	let minIndex: number | null = null;

	units.forEach( ( value, index ) => {
		if ( value > top ) {
			top = value;
			maxIndex = index;
		}

		if ( value < bottom ) {
			bottom = value;
			minIndex = index;
		}
	} );

	const span = top - bottom;
	const height = BigInt( HEIGHT );
	// All zeros: no span to divide by; the baseline is the bottom edge.
	const baseline = span === 0n ? HEIGHT : ratio( top * height, span );
	const columns: ChartColumn[] = [];

	units.forEach( ( value, index ) => {
		if ( value === 0n ) {
			return;
		}

		const negative = value < 0n;
		const magnitude = ratio( ( negative ? -value : value ) * height, span );
		const drawn = Math.max( magnitude, MIN_COLUMN );

		columns.push( {
			index,
			x: index * SLOT + ( SLOT - COLUMN ) / 2,
			// Rounded like every coordinate, so float subtraction leaves no artefacts.
			y: negative
				? baseline
				: Math.round( ( baseline - drawn ) * 100 ) / 100,
			width: COLUMN,
			height: drawn,
			negative,
		} );
	} );

	return {
		width: Math.max( units.length, 1 ) * SLOT,
		height: HEIGHT,
		baseline,
		columns,
		maxIndex,
		minIndex,
	};
}
