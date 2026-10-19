import { useCallback, useEffect, useId, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	createAlertsOverlay,
	getAlertsOverlay,
	regenerateAlertsOverlay,
	sendTestAlert,
} from '../../api/alerts';
import type { ApiError } from '../../api/client';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Skeleton } from '../../components/Skeleton';
import { Toast } from '../../components/Toast';
import { useApi } from '../../hooks/useApi';
import type { AlertsOverlayState } from '../../types/alerts';
import { AlertSoundsCard } from './AlertSoundsCard';
import { OverlayDesigner } from './OverlayDesigner';
import { OverlaySettingsForm } from './OverlaySettingsForm';
import { useFontStylesheet } from './useFontStylesheet';

function SetupCard( {
	onCreated,
}: {
	readonly onCreated: ( state: AlertsOverlayState ) => void;
} ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< ApiError | null >( null );

	const create = async () => {
		setBusy( true );
		setError( null );
		const result = await createAlertsOverlay();
		setBusy( false );

		if ( result.ok ) {
			onCreated( result.data );
		} else {
			setError( result.error );
		}
	};

	return (
		<Card title={ __( 'Your stream overlay', 'merchboard' ) }>
			<p>
				{ __(
					'Show an alert on your stream when someone buys your merch. Set up your overlay to get the URL for a browser source in your streaming software.',
					'merchboard'
				) }
			</p>
			{ error !== null && <ApiErrorState error={ error } /> }
			<Button onClick={ create } isBusy={ busy }>
				{ __( 'Set up overlay', 'merchboard' ) }
			</Button>
		</Card>
	);
}

/**
 * The overlay URL with its secret id masked, for showing on a stream.
 *
 * @param url Overlay URL.
 */
export function maskOverlayUrl( url: string ): string {
	return url.replace( /[0-9a-f]{32}/g, '•'.repeat( 12 ) );
}

function BrowserSourceCard( {
	url,
	onRegenerated,
	notify,
}: {
	readonly url: string;
	readonly onRegenerated: ( state: AlertsOverlayState ) => void;
	readonly notify: ( message: string ) => void;
} ) {
	const id = useId();
	const [ busy, setBusy ] = useState< 'test' | 'regenerate' | null >( null );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ shown, setShown ] = useState( false );

	// A new URL starts hidden again.
	useEffect( () => setShown( false ), [ url ] );

	const copy = async () => {
		try {
			await window.navigator.clipboard.writeText( url );
			notify( __( 'URL copied.', 'merchboard' ) );
		} catch {
			// Without clipboard access the URL must be shown to be copied by hand.
			setShown( true );
			window.setTimeout( () => {
				const input = document.getElementById( `${ id }-url` );

				if ( input instanceof HTMLInputElement ) {
					input.select();
				}
			}, 0 );

			notify( __( 'Copy the selected URL with Ctrl+C.', 'merchboard' ) );
		}
	};

	const test = async () => {
		setBusy( 'test' );
		setError( null );
		const result = await sendTestAlert();
		setBusy( null );

		if ( result.ok ) {
			notify(
				__(
					'Test alert sent. It appears in your open overlay within a few seconds.',
					'merchboard'
				)
			);
		} else {
			setError( result.error );
		}
	};

	const regenerate = async () => {
		if (
			// eslint-disable-next-line no-alert -- A deliberate, destructive action needs an explicit confirmation.
			! window.confirm(
				__(
					'Create a new overlay URL? The current URL stops working at once, and you must paste the new one into your browser source.',
					'merchboard'
				)
			)
		) {
			return;
		}

		setBusy( 'regenerate' );
		setError( null );
		const result = await regenerateAlertsOverlay();
		setBusy( null );

		if ( result.ok ) {
			onRegenerated( result.data );
			notify(
				__(
					'New overlay URL created. Paste it into your browser source.',
					'merchboard'
				)
			);
		} else {
			setError( result.error );
		}
	};

	return (
		<Card title={ __( 'Browser source', 'merchboard' ) }>
			<p>
				{ __(
					'Add a browser source in your streaming software with this URL, 1920 × 1080 pixels. Keep the URL secret: anyone with it can show your alerts. It stays hidden until you show it, so it is safe on stream; copying and opening work without showing it.',
					'merchboard'
				) }
			</p>
			{ error !== null && <ApiErrorState error={ error } /> }
			<div className="merchboard-alerts__url">
				<label
					className="merchboard-visually-hidden"
					htmlFor={ `${ id }-url` }
				>
					{ __( 'Overlay URL', 'merchboard' ) }
				</label>
				<input
					id={ `${ id }-url` }
					type="text"
					className="merchboard-input"
					readOnly
					value={ shown ? url : maskOverlayUrl( url ) }
					onFocus={ ( event ) => event.target.select() }
				/>
			</div>
			<div className="merchboard-alerts__actions">
				<Button variant="secondary" onClick={ copy }>
					{ __( 'Copy URL', 'merchboard' ) }
				</Button>
				<Button
					variant="secondary"
					aria-pressed={ shown }
					onClick={ () => setShown( ! shown ) }
				>
					{ shown
						? __( 'Hide URL', 'merchboard' )
						: __( 'Show URL', 'merchboard' ) }
				</Button>
				{ /* A button, not a link: hovering a link would show the URL in the status bar. */ }
				<Button
					variant="secondary"
					onClick={ () =>
						window.open( url, '_blank', 'noopener,noreferrer' )
					}
				>
					{ __( 'Open overlay', 'merchboard' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ test }
					isBusy={ busy === 'test' }
				>
					{ __( 'Send test alert', 'merchboard' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ regenerate }
					isBusy={ busy === 'regenerate' }
				>
					{ __( 'Create new URL', 'merchboard' ) }
				</Button>
			</div>
		</Card>
	);
}

function Notices( { state }: { readonly state: AlertsOverlayState } ) {
	if ( state.merchalertActive ) {
		return (
			<Alert tone="warning">
				{ __(
					'Alerts are being moved from the old alert plugin. New purchases do not show up here yet; test alerts work.',
					'merchboard'
				) }
			</Alert>
		);
	}

	if ( ! state.alertsEnabled ) {
		return (
			<Alert tone="info">
				{ __(
					'Purchase alerts are switched off for now. Test alerts work, so you can set up your overlay already.',
					'merchboard'
				) }
			</Alert>
		);
	}

	return null;
}

function AlertsContent( {
	initial,
}: {
	readonly initial: AlertsOverlayState;
} ) {
	const [ state, setState ] = useState( initial );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );

	useEffect( () => setState( initial ), [ initial ] );

	const family = state.overlay?.settings.font_family ?? 'inherit';
	const font = state.fonts.find( ( option ) => option.family === family );

	useFontStylesheet( font?.url ?? null );

	return (
		<>
			<Notices state={ state } />
			{ state.overlay === null ? (
				<SetupCard onCreated={ setState } />
			) : (
				<div className="merchboard-alerts">
					<BrowserSourceCard
						url={ state.overlay.url }
						onRegenerated={ setState }
						notify={ setToast }
					/>
					{ state.overlay.audio !== null && (
						<AlertSoundsCard
							audio={ state.overlay.audio }
							onSaved={ setState }
							notify={ setToast }
						/>
					) }
					<OverlaySettingsForm
						key={ `form-${ state.overlay.url }` }
						settings={ state.overlay.settings }
						fonts={ state.fonts }
						fontApproved={ state.overlay.fontApproved }
						onSaved={ setState }
					/>
					<div className="merchboard-alerts__wide">
						<OverlayDesigner
							key={ `designer-${ state.overlay.url }` }
							layout={ state.overlay.layout }
							settings={ state.overlay.settings }
							fontFamily={
								font === undefined
									? 'inherit'
									: `"${ font.family }", sans-serif`
							}
							onSaved={ setState }
						/>
					</div>
				</div>
			) }
			<Toast message={ toast } onDismiss={ dismissToast } />
		</>
	);
}

/**
 * "Alerts" (ADR-065): the creator's OBS overlay. Set up, browser source URL
 * (copy, open, replace), test alert, the overlay's look and timing, the
 * designer and the sounds.
 */
export function AlertsSection() {
	const { state, retry } = useApi( getAlertsOverlay );

	if ( state.status === 'loading' ) {
		return (
			<Skeleton
				label={ __( 'Loading your overlay…', 'merchboard' ) }
				lines={ 4 }
			/>
		);
	}

	if ( state.status === 'error' ) {
		return <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	return <AlertsContent initial={ state.data } />;
}
