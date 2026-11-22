/**
 * How far a baked clipart is from the PSD's own composite (phase 56, step
 * 56.3, ADR-121 pkt. 8): what ag-psd left out without saying so shows here.
 */
import type { Rgba } from './psd-template';

/** A channel difference that is seen at once. */
const SEEN = 128;

export interface Difference {
	/** The mean difference per channel, 0–255, on the worse ground. */
	readonly mean: number;
	/** The share of pixels seen at once, 0–1, on the worse ground. */
	readonly seen: number;
}

/**
 * Two pictures compared on white and on black, so see-through pixels count
 * by what they show.
 *
 * @param drawn The picture drawn.
 * @param file  The file's own.
 * @return The difference, or null when the sizes are not the same.
 */
export function differenceOf( drawn: Rgba, file: Rgba ): Difference | null {
	if ( drawn.width !== file.width || drawn.height !== file.height ) {
		return null;
	}

	const pixels = drawn.width * drawn.height;
	let mean = 0;
	let seen = 0;

	for ( const ground of [ 0, 255 ] ) {
		let sum = 0;
		let far = 0;

		for ( let at = 0; at < pixels * 4; at += 4 ) {
			const one = ( drawn.data[ at + 3 ] as number ) / 255;
			const other = ( file.data[ at + 3 ] as number ) / 255;
			let most = 0;

			for ( let channel = 0; channel < 3; channel++ ) {
				const apart = Math.abs(
					( drawn.data[ at + channel ] as number ) * one +
						ground * ( 1 - one ) -
						( file.data[ at + channel ] as number ) * other -
						ground * ( 1 - other )
				);

				sum += apart;
				most = Math.max( most, apart );
			}

			if ( most > SEEN ) {
				far++;
			}
		}

		mean = Math.max( mean, sum / ( pixels * 3 ) );
		seen = Math.max( seen, far / pixels );
	}

	return { mean, seen };
}
