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
import { OverlaySettingsForm } from './OverlaySettingsForm';

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
					'Show an alert on your stream when someone buys your merch. Set up your overlay to get a browser source URL for OBS.',
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

	const copy = async () => {
		try {
			await window.navigator.clipboard.writeText( url );
			notify( __( 'URL copied.', 'merchboard' ) );
		} catch {
			const input = document.getElementById( `${ id }-url` );

			if ( input instanceof HTMLInputElement ) {
				input.select();
			}

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
					'Create a new overlay URL? The current URL stops working at once, and you must paste the new one into OBS.',
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
					'New overlay URL created. Paste it into OBS.',
					'merchboard'
				)
			);
		} else {
			setError( result.error );
		}
	};

	return (
		<Card title={ __( 'Browser source for OBS', 'merchboard' ) }>
			<p>
				{ __(
					'Add a browser source in OBS with this URL, 1920 × 1080 pixels. Keep the URL secret: anyone with it can show your alerts.',
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
					value={ url }
					onFocus={ ( event ) => event.target.select() }
				/>
			</div>
			<div className="merchboard-alerts__actions">
				<Button variant="secondary" onClick={ copy }>
					{ __( 'Copy URL', 'merchboard' ) }
				</Button>
				<a
					className="merchboard-button merchboard-button--secondary merchboard-button--md"
					href={ url }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Open overlay', 'merchboard' ) }
				</a>
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

	return (
		<>
			<Notices state={ state } />
			{ state.overlay === null ? (
				<SetupCard onCreated={ setState } />
			) : (
				<>
					<BrowserSourceCard
						url={ state.overlay.url }
						onRegenerated={ setState }
						notify={ setToast }
					/>
					<OverlaySettingsForm
						settings={ state.overlay.settings }
						onSaved={ setState }
					/>
				</>
			) }
			<Toast message={ toast } onDismiss={ dismissToast } />
		</>
	);
}

/**
 * "Alerts" (ADR-065): the creator's OBS overlay. Set up, browser source URL
 * (copy, open, replace), test alert and the overlay's look and timing.
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
