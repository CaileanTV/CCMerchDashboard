import { useId, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import type { ApiError } from '../../api/client';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import type { AlertsImages, AlertsOverlayState } from '../../types/alerts';
import { useOverlayApi } from './overlay-api-context';

/** File types the server accepts. */
export const IMAGE_ACCEPT =
	'.png,.jpg,.jpeg,.gif,.webp,image/png,image/jpeg,image/gif,image/webp';

/**
 * The message of a refused upload.
 *
 * @param code   Field error code of the server, or 'too_large' from the browser.
 * @param images The limits.
 */
export function imageErrorMessage(
	code: string,
	images: AlertsImages
): string {
	switch ( code ) {
		case 'too_large':
			return sprintf(
				/* translators: %s: size in MB, e.g. "5". */
				__( 'The file is larger than %s MB.', 'merchboard' ),
				( images.maxBytes / 1048576 ).toLocaleString( undefined, {
					maximumFractionDigits: 1,
				} )
			);
		case 'too_big':
			return sprintf(
				/* translators: %d: pixels. */
				__(
					'The image is wider or higher than %d pixels.',
					'merchboard'
				),
				images.maxPixels
			);
		case 'invalid_type':
			return __( 'Choose a PNG, JPG, GIF or WebP image.', 'merchboard' );
		case 'unreadable':
			return __(
				'The file could not be read as an image. Choose another file.',
				'merchboard'
			);
		case 'own_full':
			return __(
				'You have the most images allowed. Delete one first.',
				'merchboard'
			);
		case 'unknown_image':
			return __( 'This image no longer exists.', 'merchboard' );
		default:
			return __( 'No file was received. Try again.', 'merchboard' );
	}
}

interface Props {
	readonly images: AlertsImages;
	readonly onSaved: ( state: AlertsOverlayState ) => void;
	readonly notify: ( message: string ) => void;
}

/**
 * The creator's own images that can fill alert text (step 25.6.10):
 * upload and delete. Shown below "Animations and text fill" while a text
 * is filled with an image, where the image is chosen. The server checks the file again; the browser only checks the
 * size first.
 *
 * @param props         Props.
 * @param props.images  The images and limits.
 * @param props.onSaved Called with the answer after a change.
 * @param props.notify  Shows a short confirmation.
 */
export function AlertImagesCard( { images, onSaved, notify }: Props ) {
	const api = useOverlayApi();
	const id = useId();
	const [ busy, setBusy ] = useState< 'upload' | number | null >( null );
	const [ message, setMessage ] = useState< string | null >( null );
	const [ error, setError ] = useState< ApiError | null >( null );
	const full = images.own.length >= images.maxOwn;

	const upload = async ( file: File ) => {
		setMessage( null );
		setError( null );

		if ( file.size > images.maxBytes ) {
			setMessage( imageErrorMessage( 'too_large', images ) );
			return;
		}

		setBusy( 'upload' );
		const result = await api.uploadImage( file );
		setBusy( null );

		if ( result.ok ) {
			onSaved( result.data );
			notify(
				__(
					'Image saved. Choose it in the image list above.',
					'merchboard'
				)
			);
			return;
		}

		const code = result.error.fields.file;

		if ( result.error.kind === 'invalid_request' && code !== undefined ) {
			setMessage( imageErrorMessage( code, images ) );
		} else {
			setError( result.error );
		}
	};

	const remove = async ( imageId: number ) => {
		if (
			// eslint-disable-next-line no-alert -- Deleting a file needs an explicit confirmation.
			! window.confirm(
				__(
					'Delete this image? Texts it fills show their colour again.',
					'merchboard'
				)
			)
		) {
			return;
		}

		setBusy( imageId );
		setMessage( null );
		setError( null );
		const result = await api.removeImage( imageId );
		setBusy( null );

		if ( result.ok ) {
			onSaved( result.data );
			notify( __( 'Image deleted.', 'merchboard' ) );
			return;
		}

		const code = result.error.fields.image;

		if ( result.error.kind === 'invalid_request' && code !== undefined ) {
			setMessage( imageErrorMessage( code, images ) );
		} else {
			setError( result.error );
		}
	};

	return (
		<Card title={ __( 'Images for text', 'merchboard' ) }>
			<p>
				{ sprintf(
					/* translators: 1: size in MB, 2: pixels, 3: number of images. */
					__(
						'Upload images to fill your alert text with. PNG, JPG, GIF or WebP, at most %1$s MB and %2$d pixels wide and high; at most %3$d images. An animated GIF keeps moving.',
						'merchboard'
					),
					( images.maxBytes / 1048576 ).toLocaleString( undefined, {
						maximumFractionDigits: 1,
					} ),
					images.maxPixels,
					images.maxOwn
				) }
			</p>
			{ images.library.length > 0 && (
				<p className="merchboard-field__help">
					{ sprintf(
						/* translators: %d: number of images. */
						_n(
							'The image library has %d more image you can choose.',
							'The image library has %d more images you can choose.',
							images.library.length,
							'merchboard'
						),
						images.library.length
					) }
				</p>
			) }
			{ message !== null && <Alert tone="danger">{ message }</Alert> }
			{ error !== null && <ApiErrorState error={ error } /> }
			{ images.own.length > 0 && (
				<ul className="merchboard-images">
					{ images.own.map( ( image ) => (
						<li
							key={ image.id }
							className="merchboard-images__item"
						>
							<img
								className="merchboard-images__thumb"
								src={ image.url }
								alt=""
								loading="lazy"
							/>
							<span className="merchboard-images__name">
								{ image.name }
							</span>
							<Button
								variant="ghost"
								size="sm"
								onClick={ () => void remove( image.id ) }
								isBusy={ busy === image.id }
								disabled={ busy !== null }
								aria-label={ sprintf(
									/* translators: %s: file name. */
									__( 'Delete %s', 'merchboard' ),
									image.name
								) }
							>
								{ __( 'Delete', 'merchboard' ) }
							</Button>
						</li>
					) ) }
				</ul>
			) }
			<div className="merchboard-alerts__actions">
				<input
					id={ `${ id }-file` }
					type="file"
					className="merchboard-visually-hidden"
					accept={ IMAGE_ACCEPT }
					disabled={ busy !== null || full }
					onChange={ ( event ) => {
						const file = event.target.files?.[ 0 ];
						event.target.value = '';

						if ( file !== undefined ) {
							void upload( file );
						}
					} }
				/>
				<label
					className="merchboard-button merchboard-button--secondary merchboard-button--sm merchboard-sounds__upload"
					htmlFor={ `${ id }-file` }
					aria-busy={ busy === 'upload' }
					aria-disabled={ full }
				>
					{ busy === 'upload'
						? __( 'Uploading…', 'merchboard' )
						: __( 'Upload image', 'merchboard' ) }
				</label>
				{ full && (
					<span className="merchboard-field__help">
						{ __(
							'Delete an image to upload another.',
							'merchboard'
						) }
					</span>
				) }
			</div>
		</Card>
	);
}
