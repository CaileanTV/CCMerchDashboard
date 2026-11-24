/**
 * The guides of a print around a product (ADR-122): where the lines
 * between its views lie.
 */

import type { DesignerGuide, DesignerTrim } from '../../api/designer';

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

/** A view's image under the print area. */
export interface GuideFigure {
	readonly key: string;
	readonly label: string;
	/** The image (an asset), 0 for none. */
	readonly figure: number;
	/** Where the image's anchor lies, as a share of the print area's width. */
	readonly x: number;
	/** The anchor, as a share of the image's width: its middle or the seam. */
	readonly anchor: number;
	/** The part of the print it shows, as shares of the image; null on a photo. */
	readonly box: DesignerTrim | null;
}

/**
 * The images under a print around the product (step 57.19), as a print
 * editor shows them: each view under its centre, and the view where the
 * sheet's ends meet at both ends, its seam on the edge, each with the half
 * of its part of the print that belongs to that end. The sheet runs
 * rightwards on the drawing, so the part after the seam is where it starts.
 *
 * @param guides The views, in any order.
 */
export function guideFigures(
	guides: readonly DesignerGuide[]
): GuideFigure[] {
	return guides
		.flatMap( ( guide ): GuideFigure[] => {
			const { box, seam } = guide;

			if ( box === null || seam === null ) {
				return [
					{
						key: `${ guide.label }-${ guide.x }`,
						label: guide.label,
						figure: guide.figure,
						x: guide.x,
						anchor: 0.5,
						box,
					},
				];
			}

			const right = box.x + box.width;
			const after = Math.min( right, Math.max( box.x, seam ) );
			const before = Math.max( box.x, Math.min( right, seam ) );

			return [
				{
					key: `${ guide.label }-start`,
					label: guide.label,
					figure: guide.figure,
					x: 0,
					anchor: seam,
					box: { ...box, x: after, width: right - after },
				},
				{
					key: `${ guide.label }-end`,
					label: guide.label,
					figure: guide.figure,
					x: 1,
					anchor: seam,
					box: { ...box, width: before - box.x },
				},
			];
		} )
		.sort( ( a, b ) => a.x - b.x );
}
