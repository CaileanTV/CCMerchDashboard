/**
 * The finished product before a design is sent: real photos of the saved
 * draft, so the creator can judge sizes and placement. The print files are
 * sent first, then the photos are polled. Who makes them is never named here.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { fetchPreview, startPreview } from '../../api/designer';
import type { DesignerPreview, DesignerView } from '../../api/designer';
import { Alert } from '../../components/Alert';
import { Dialog } from '../../components/Dialog';

/** Milliseconds between two looks at the photos. */
const POLL_MS = 3000;

/** Looks before giving up. */
const MAX_POLLS = 40;

export interface FinishedProductProps {
	readonly product: number;
	readonly views: readonly DesignerView[];
	/** Saves the draft and sends its files; a reason when it fails. */
	readonly prepare: () => Promise< string | null >;
	readonly onClose: () => void;
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
	const { product, views, prepare, onClose } = props;
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
						setImages( state.data.images );
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
					<div className="merchboard-product-designer__finished">
						{ images.map( ( image ) => (
							<figure key={ `${ image.side }-${ image.color }` }>
								<img
									src={ image.url }
									alt={
										name( image.side ) ??
										__(
											'Your finished product',
											'merchboard'
										)
									}
								/>
								{ name( image.side ) === null ? null : (
									<figcaption>
										{ name( image.side ) }
									</figcaption>
								) }
							</figure>
						) ) }
					</div>
				</>
			) : null }
		</Dialog>
	);
}
