/**
 * The views of a print around the product under its print area (ADR-122):
 * each view's image under where it faces. A view drawn by the import (step
 * 57.19) is shown as in a print editor: the drawing with the part of the
 * print it shows framed, and no name (it is read out and shown on hover);
 * a photo keeps its name below it.
 */
import { __ } from '@wordpress/i18n';
import type { CSSProperties } from 'react';

import type { DesignerView } from '../../api/designer';
import { guideFigures } from './guides';

/** Tallest a drawing is shown, in pixels. */
const DRAWING_HEIGHT = 88;

/** Least a drawing is shown, in pixels. */
const DRAWING_MIN = 24;

/**
 * A share as a CSS percentage, to a thousandth of a percent.
 *
 * @param share Share, e.g. 0.446.
 */
const percent = ( share: number ): string =>
	`${ Math.round( share * 100000 ) / 1000 }%`;

export interface GuideViewsProps {
	readonly view: DesignerView;
	/** `data:` URL by asset id. */
	readonly images: ReadonlyMap< number, string >;
	/** Where the list lies on the stage: under the print area, as wide. */
	readonly style: CSSProperties;
	/** The room under the print area on the stage, in pixels. */
	readonly room: number;
}

/**
 * The list of the views' images.
 *
 * @param props Props.
 */
export function GuideViews( props: GuideViewsProps ) {
	const { view, images, style, room } = props;
	const height = Math.max(
		DRAWING_MIN,
		Math.min( DRAWING_HEIGHT, Math.floor( room ) - 12 )
	);

	return (
		<ul
			className="merchboard-product-designer__views"
			aria-label={ __(
				'Where the design sits on the product',
				'merchboard'
			) }
			style={ style }
		>
			{ guideFigures( view.guides ).map( ( guide ) => {
				const image = images.get( guide.figure );
				// A drawing of the view where the sheet meets keeps its look without a frame.
				const drawn =
					image !== undefined &&
					view.guides.some(
						( own ) => own.label === guide.label && own.box !== null
					);

				return (
					<li
						key={ guide.key }
						className={ drawn ? 'is-drawing' : undefined }
						style={ {
							left: percent( guide.x ),
							transform: `translateX(-${ percent( guide.anchor ) })`,
						} }
						title={ guide.label }
						aria-hidden={ guide.repeat ? true : undefined }
					>
						{ image === undefined ? null : (
							<span className="merchboard-product-designer__figure">
								<img
									src={ image }
									alt=""
									style={ drawn ? { height } : undefined }
								/>
								{ guide.box === null ? null : (
									<span
										className="merchboard-product-designer__seen"
										style={ {
											left: percent( guide.box.x ),
											top: percent( guide.box.y ),
											width: percent( guide.box.width ),
											height: percent( guide.box.height ),
										} }
									/>
								) }
							</span>
						) }
						<span
							className={
								drawn ? 'merchboard-visually-hidden' : undefined
							}
						>
							{ guide.label }
						</span>
					</li>
				);
			} ) }
		</ul>
	);
}
