/**
 * The guides of a print around a product (ADR-122): where the lines
 * between its views lie.
 */

import type { DesignerGuide } from '../../api/designer';

/**
 * The lines between neighbouring views, as shares of the print area's
 * width: half way between their centres, also across the sheet's ends, as
 * the print goes around the product. No lines for fewer than two views.
 *
 * @param guides The views' centres, in any order.
 */
export function guideLines( guides: readonly DesignerGuide[] ): number[] {
	const centres = guides
		.map( ( guide ) => guide.x )
		.sort( ( a, b ) => a - b );

	if ( centres.length < 2 ) {
		return [];
	}

	// The first view again after the last, one round further on.
	const around = [ ...centres, ( centres[ 0 ] ?? 0 ) + 1 ];
	const lines = centres.map(
		( x, index ) =>
			( ( ( ( x + ( around[ index + 1 ] ?? x ) ) / 2 ) % 1 ) + 1 ) % 1
	);

	return [
		...new Set( lines.map( ( x ) => Math.round( x * 10000 ) / 10000 ) ),
	].sort( ( a, b ) => a - b );
}
