/**
 * The finished product before a design is sent: real photos of the saved
 * draft, so the creator can judge sizes and placement. The print files are
 * sent first, then the photos are polled. Who makes them is never named here.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { fetchPreview, startPreview } from '../../api/designer';
import type { DesignerPreview, DesignerView } from '../../api/designer';
import { Alert } from '../../components/Alert';
import { Dialog } from '../../components/Dialog';
import { shifted } from './design-model';
import { IconButton } from './LayerList';

/** Milliseconds between two looks at the photos. */
const POLL_MS = 3000;

/** Looks before giving up. */
const MAX_POLLS = 40;

/** What starts the side of a photo the administrator chose. */
const PHOTO = '_photo-';

export interface FinishedProductProps {
	readonly product: number;
	readonly views: readonly DesignerView[];
	/** Saves the draft and sends its files; a reason when it fails. */
	readonly prepare: () => Promise< string | null >;
	/** The creator's order of the photos, first the main image. */
	readonly order: readonly number[];
	readonly onOrder: ( order: readonly number[] ) => void;
	readonly onClose: () => void;
}

/**
 * The photo's id, or null for an image of a side.
 *
 * @param side The image's side.
 */
function photoOf( side: string ): number | null {
	return side.startsWith( PHOTO )
		? Number( side.slice( PHOTO.length ) )
		: null;
}

/**
 * The images in the creator's order; the rest keep theirs after them.
 *
 * @param images The images.
 * @param order  The creator's order.
 */
function arranged(
	images: DesignerPreview[ 'images' ],
	order: readonly number[]
): DesignerPreview[ 'images' ] {
	const place = ( side: string ) => {
		const index = order.indexOf( photoOf( side ) ?? -1 );

		return index === -1 ? order.length : index;
	};

	return [ ...images ].sort( ( a, b ) => place( a.side ) - place( b.side ) );
}

/**
 * The text of a failed preview.
 *
 * @param reason The refusal's reason.
 */
function failure( reason: string | undefined ): string {
	switch ( reason ) {
		case 'design_empty':
			return __( 'Add something to your product first.', 'merchboard' );
		case 'preview_busy':
			return __(
				'We can only make a limited number of photos a minute. Please try again in a minute.',
				'merchboard'
			);
		default:
			return __(
				'The photos could not be made. You can still send your design, and we will look at it.',
				'merchboard'
			);
	}
}

/**
 * The dialog with the photos.
 *
 * @param props Props.
 */
export function FinishedProduct( props: FinishedProductProps ) {
	const { product, views, prepare, order, onOrder, onClose } = props;
	const [ images, setImages ] = useState< DesignerPreview[ 'images' ] >( [] );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( true );

	useEffect( () => {
		let current = true;
		const wait = () =>
			new Promise( ( resolve ) => setTimeout( resolve, POLL_MS ) );
		const fail = ( reason: string | undefined ) => {
			if ( current ) {
				setError( failure( reason ) );
				setBusy( false );
			}
		};
		const run = async () => {
			const refused = await prepare();

			if ( refused !== null ) {
				fail( refused );

				return;
			}

			const started = current ? await startPreview( product ) : null;

			if ( started !== null && ! started.ok ) {
				fail( started.error.reason );

				return;
			}

			for ( let poll = 0; current && poll < MAX_POLLS; poll++ ) {
				await wait();

				if ( ! current ) {
					return;
				}

				const state = await fetchPreview( product );

				if ( ! state.ok ) {
					fail( state.error.reason );

					return;
				}

				if ( state.data.status === 'done' ) {
					if ( current ) {
						setImages( arranged( state.data.images, order ) );
						setBusy( false );
					}

					return;
				}

				if ( state.data.status !== 'pending' ) {
					fail( undefined );

					return;
				}
			}

			fail( undefined );
		};

		void run();

		return () => {
			current = false;
		};
		// Runs once for each time the dialog is opened.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// A photo the administrator chose belongs to the product, not to a side.
	const name = ( side: string ) =>
		views.find( ( view ) => view.key === side )?.name ?? null;

	const ordered = images.filter(
		( image ) => photoOf( image.side ) !== null
	);
	const move = ( index: number, step: -1 | 1 ) => {
		const next = shifted( images, index, step );

		setImages( [ ...next ] );
		onOrder( next.flatMap( ( image ) => photoOf( image.side ) ?? [] ) );
	};
	const caption = ( side: string, index: number ) =>
		name( side ) ??
		( index === 0
			? __( 'Main image', 'merchboard' )
			: sprintf(
					/* translators: %d: the photo's place. */ __(
						'Photo %d',
						'merchboard'
					),
					index + 1
				) );

	return (
		<Dialog
			title={ __( 'Your finished product', 'merchboard' ) }
			onClose={ onClose }
			closeLabel={ __( 'Close', 'merchboard' ) }
		>
			{ busy ? (
				<p
					className="merchboard-product-designer__working"
					role="status"
				>
					<span
						className="merchboard-product-designer__spinner"
						aria-hidden="true"
					/>
					{ __(
						'We are making photos of your finished product. It usually takes less than a minute…',
						'merchboard'
					) }
				</p>
			) : null }
			{ error === null ? null : <Alert tone="danger">{ error }</Alert> }
			{ ! busy && error === null ? (
				<>
					<p>
						{ __(
							'These are photos of your saved design in its first colour. Check sizes and placement before you send it. A part without a design is white.',
							'merchboard'
						) }
					</p>
					{ ordered.length > 1 ? (
						<p>
							{ __(
								'Use the arrows to choose the order of the photos in the shop. The first is the main image of your product.',
								'merchboard'
							) }
						</p>
					) : null }
					<div className="merchboard-product-designer__finished">
						{ images.map( ( image, index ) => (
							<figure key={ `${ image.side }-${ image.color }` }>
								<img
									src={ image.url }
									alt={ caption( image.side, index ) }
								/>
								<figcaption>
									<span>
										{ caption( image.side, index ) }
									</span>
									{ ordered.length > 1 ? (
										<span className="merchboard-product-designer__move">
											<IconButton
												icon="up"
												label={ __(
													'Earlier',
													'merchboard'
												) }
												disabled={ index === 0 }
												onClick={ () =>
													move( index, -1 )
												}
											/>
											<IconButton
												icon="down"
												label={ __(
													'Later',
													'merchboard'
												) }
												disabled={
													index === images.length - 1
												}
												onClick={ () =>
													move( index, 1 )
												}
											/>
										</span>
									) : null }
								</figcaption>
							</figure>
						) ) }
					</div>
				</>
			) : null }
		</Dialog>
	);
}
