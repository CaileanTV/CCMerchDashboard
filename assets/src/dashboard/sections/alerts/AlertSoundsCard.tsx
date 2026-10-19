import { useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	removeAlertSound,
	updateAlertsOverlay,
	uploadAlertSound,
} from '../../api/alerts';
import type { ApiError } from '../../api/client';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import type {
	AlertSoundFile,
	AlertsAudio,
	AlertsOverlayState,
	SoundSlot,
} from '../../types/alerts';

/** File types the server accepts. */
export const SOUND_ACCEPT =
	'.mp3,.wav,.ogg,.oga,audio/mpeg,audio/wav,audio/ogg';

/**
 * The message of a refused upload.
 *
 * @param code       Field error code of the server, or 'too_large' from the browser.
 * @param maxBytes   Largest file.
 * @param maxSeconds Longest sound.
 */
export function soundErrorMessage(
	code: string,
	maxBytes: number,
	maxSeconds: number
): string {
	switch ( code ) {
		case 'too_large':
			return sprintf(
				/* translators: %s: size in MB, e.g. "10". */
				__( 'The file is larger than %s MB.', 'merchboard' ),
				( maxBytes / 1048576 ).toLocaleString( undefined, {
					maximumFractionDigits: 1,
				} )
			);
		case 'too_long':
			return sprintf(
				/* translators: %d: seconds. */
				__( 'The sound is longer than %d seconds.', 'merchboard' ),
				maxSeconds
			);
		case 'invalid_type':
			return __( 'Choose an MP3, WAV or OGG file.', 'merchboard' );
		case 'unreadable':
			return __(
				'The file could not be read as a sound. Choose another file.',
				'merchboard'
			);
		case 'unknown_category':
			return __( 'This category is no longer yours.', 'merchboard' );
		default:
			return __( 'No file was received. Try again.', 'merchboard' );
	}
}

interface RowProps {
	readonly slot: SoundSlot;
	readonly label: string;
	readonly sound: AlertSoundFile | null;
	readonly audio: AlertsAudio;
	readonly onSaved: ( state: AlertsOverlayState ) => void;
	readonly notify: ( message: string ) => void;
}

function SoundRow( { slot, label, sound, audio, onSaved, notify }: RowProps ) {
	const id = useId();
	const [ busy, setBusy ] = useState< 'upload' | 'remove' | null >( null );
	const [ message, setMessage ] = useState< string | null >( null );
	const [ saved, setSaved ] = useState< string | null >( null );
	const [ error, setError ] = useState< ApiError | null >( null );

	const upload = async ( file: File ) => {
		setMessage( null );
		setSaved( null );
		setError( null );

		if ( file.size > audio.maxBytes ) {
			setMessage(
				soundErrorMessage(
					'too_large',
					audio.maxBytes,
					audio.maxSeconds
				)
			);
			return;
		}

		setBusy( 'upload' );
		const result = await uploadAlertSound( slot, file );
		setBusy( null );

		if ( result.ok ) {
			onSaved( result.data );
			notify( __( 'Sound saved.', 'merchboard' ) );
			setSaved(
				result.data.overlay?.audio?.enabled === true
					? __(
							'Sound saved. It plays with the next alert.',
							'merchboard'
						)
					: __(
							'Sound saved. Switch on "Play sounds" to hear it in the overlay.',
							'merchboard'
						)
			);
			return;
		}

		const code = result.error.fields.file ?? result.error.fields.slot;

		if ( result.error.kind === 'invalid_request' && code !== undefined ) {
			setMessage(
				soundErrorMessage( code, audio.maxBytes, audio.maxSeconds )
			);
		} else {
			setError( result.error );
		}
	};

	const remove = async () => {
		if (
			// eslint-disable-next-line no-alert -- Deleting a file needs an explicit confirmation.
			! window.confirm( __( 'Delete this sound?', 'merchboard' ) )
		) {
			return;
		}

		setBusy( 'remove' );
		setMessage( null );
		setError( null );
		const result = await removeAlertSound( slot );
		setBusy( null );

		if ( result.ok ) {
			onSaved( result.data );
			notify( __( 'Sound deleted.', 'merchboard' ) );
		} else {
			setError( result.error );
		}
	};

	let uploadLabel: string = __( 'Replace sound', 'merchboard' );

	if ( busy === 'upload' ) {
		uploadLabel = __( 'Uploading…', 'merchboard' );
	} else if ( sound === null ) {
		uploadLabel = __( 'Upload sound', 'merchboard' );
	}

	return (
		<div className="merchboard-sounds__row">
			<h4 className="merchboard-sounds__label">{ label }</h4>
			{ sound === null ? (
				<p className="merchboard-sounds__none">
					{ slot === 'default'
						? __( 'No sound.', 'merchboard' )
						: __(
								'No sound: the default sound plays.',
								'merchboard'
							) }
				</p>
			) : (
				<div className="merchboard-sounds__file">
					<audio controls preload="none" src={ sound.url } />
					<span>
						{ sprintf(
							/* translators: 1: file name, 2: length in seconds. */
							__( '%1$s (%2$d s)', 'merchboard' ),
							sound.name,
							sound.seconds
						) }
					</span>
				</div>
			) }
			{ saved !== null && <Alert tone="success">{ saved }</Alert> }
			{ message !== null && <Alert tone="danger">{ message }</Alert> }
			{ error !== null && <ApiErrorState error={ error } /> }
			<div className="merchboard-alerts__actions">
				<input
					id={ `${ id }-file` }
					type="file"
					className="merchboard-visually-hidden"
					accept={ SOUND_ACCEPT }
					disabled={ busy !== null }
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
				>
					{ uploadLabel }
				</label>
				{ sound !== null && (
					<Button
						variant="ghost"
						size="sm"
						onClick={ remove }
						isBusy={ busy === 'remove' }
						disabled={ busy !== null }
					>
						{ __( 'Delete sound', 'merchboard' ) }
					</Button>
				) }
			</div>
		</div>
	);
}

interface Props {
	readonly audio: AlertsAudio;
	readonly onSaved: ( state: AlertsOverlayState ) => void;
	readonly notify: ( message: string ) => void;
}

/**
 * The overlay's sounds (ADR-065 pkt. 3): switched on or off, a default
 * sound and a sound per category of the creator, each with upload, replace,
 * play and delete. The server checks the file again; the browser only
 * checks the size first.
 *
 * @param props         Props.
 * @param props.audio   The sounds.
 * @param props.onSaved Called with the answer after a change.
 * @param props.notify  Shows a short confirmation.
 */
export function AlertSoundsCard( { audio, onSaved, notify }: Props ) {
	const id = useId();
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< ApiError | null >( null );

	const toggle = async ( enabled: boolean ) => {
		setBusy( true );
		setError( null );
		const result = await updateAlertsOverlay( { audio_enabled: enabled } );
		setBusy( false );

		if ( result.ok ) {
			onSaved( result.data );
		} else {
			setError( result.error );
		}
	};

	const hasSound =
		audio.defaultSound !== null ||
		audio.categories.some( ( category ) => category.sound !== null );

	return (
		<Card title={ __( 'Sounds', 'merchboard' ) }>
			<p>
				{ sprintf(
					/* translators: 1: size in MB, 2: seconds. */
					__(
						'Play a sound with each alert. MP3, WAV or OGG, at most %1$s MB and %2$d seconds. Products in a category with its own sound play that sound; all others play the default sound.',
						'merchboard'
					),
					( audio.maxBytes / 1048576 ).toLocaleString( undefined, {
						maximumFractionDigits: 1,
					} ),
					audio.maxSeconds
				) }
			</p>
			<p>
				{ __(
					"The browser source plays the sounds automatically, also after a restart. The sound goes to the browser source's audio in the audio mixer of your streaming software; turn on audio monitoring there to hear it yourself. A normal browser tab may block sounds unless you allow autoplay for this site in the browser's site settings.",
					'merchboard'
				) }
			</p>
			{ ! audio.enabled && hasSound && (
				<Alert tone="warning">
					{ __(
						'Sounds are off, so your overlay plays no sound. Switch on "Play sounds".',
						'merchboard'
					) }
				</Alert>
			) }
			{ error !== null && <ApiErrorState error={ error } /> }
			<label
				className="merchboard-alerts__check"
				htmlFor={ `${ id }-on` }
			>
				<input
					id={ `${ id }-on` }
					type="checkbox"
					checked={ audio.enabled }
					disabled={ busy }
					onChange={ ( event ) =>
						void toggle( event.target.checked )
					}
				/>{ ' ' }
				{ __( 'Play sounds', 'merchboard' ) }
			</label>
			<SoundRow
				slot="default"
				label={ __( 'Default sound', 'merchboard' ) }
				sound={ audio.defaultSound }
				audio={ audio }
				onSaved={ onSaved }
				notify={ notify }
			/>
			{ audio.categories.map( ( category ) => (
				<SoundRow
					key={ category.termId }
					slot={ category.termId }
					label={ sprintf(
						/* translators: %s: product category. */
						__( 'Category: %s', 'merchboard' ),
						category.name
					) }
					sound={ category.sound }
					audio={ audio }
					onSaved={ onSaved }
					notify={ notify }
				/>
			) ) }
		</Card>
	);
}
