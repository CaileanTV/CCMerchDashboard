/**
 * The mockup template around the design (phase 30, step 30.8): the
 * background and the colour mask, tinted in the chosen product colour, under
 * the design; the shading (multiplied) and the highlight (screened) over it,
 * so the design follows the folds. A flat side's shading is its sewing
 * pattern, drawn normally: its veil covers what falls outside the part. Only
 * `data:` images (the dashboard's CSP); the layers above never take the
 * pointer.
 */
import { useEffect, useState } from '@wordpress/element';

import type { DesignerView } from '../../api/designer';

/**
 * A colour mask tinted in a colour, as a `data:` URL.
 *
 * @param mask  The mask's `data:` URL.
 * @param color `#rrggbb`.
 */
export function tint( mask: string, color: string ): Promise< string | null > {
	return new Promise( ( resolve ) => {
		const image = new Image();
		image.onload = () => {
			const canvas = document.createElement( 'canvas' );
			canvas.width = image.naturalWidth;
			canvas.height = image.naturalHeight;
			const context = canvas.getContext( '2d' );

			if ( context === null ) {
				resolve( null );

				return;
			}

			context.drawImage( image, 0, 0 );
			context.globalCompositeOperation = 'source-in';
			context.fillStyle = color;
			context.fillRect( 0, 0, canvas.width, canvas.height );
			resolve( canvas.toDataURL( 'image/png' ) );
		};
		image.onerror = () => resolve( null );
		image.src = mask;
	} );
}

export interface TemplateLayersProps {
	readonly view: DesignerView;
	/** `data:` URL by asset id. */
	readonly images: ReadonlyMap< number, string >;
	readonly color: string;
	/** The colour's key: its own background, when the side has one, replaces the background and the mask. */
	readonly colorKey?: string;
	readonly part: 'under' | 'over';
}

/**
 * One part of the template, filling its positioned parent.
 *
 * @param props Props.
 */
export function TemplateLayers( props: TemplateLayersProps ) {
	const { view, images, color, colorKey, part } = props;
	const own =
		colorKey === undefined ? undefined : view.backgrounds[ colorKey ];
	const mask =
		own !== undefined || view.layers.color === undefined
			? undefined
			: images.get( view.layers.color );
	const [ tinted, setTinted ] = useState< string | null >( null );

	useEffect( () => {
		let current = true;

		if ( part !== 'under' || mask === undefined ) {
			setTinted( null );

			return;
		}

		void tint( mask, color ).then( ( url ) => {
			if ( current ) {
				setTinted( url );
			}
		} );

		return () => {
			current = false;
		};
	}, [ mask, color, part ] );

	const layer = ( id: number | undefined, blend?: 'multiply' | 'screen' ) => {
		const url = id === undefined ? undefined : images.get( id );

		return url === undefined ? null : (
			<img
				className="merchboard-product-designer__layer"
				src={ url }
				alt=""
				style={
					blend === undefined ? undefined : { mixBlendMode: blend }
				}
			/>
		);
	};

	return part === 'under' ? (
		<>
			{ layer( own ?? view.layers.background ) }
			{ tinted === null || own !== undefined ? null : (
				<img
					className="merchboard-product-designer__layer"
					src={ tinted }
					alt=""
				/>
			) }
		</>
	) : (
		<>
			{ layer( view.layers.shading, view.flat ? undefined : 'multiply' ) }
			{ layer( view.layers.highlight, 'screen' ) }
		</>
	);
}
