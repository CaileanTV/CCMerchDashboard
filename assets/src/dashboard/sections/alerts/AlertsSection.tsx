import {
	useCallback,
	useContext,
	useEffect,
	useId,
	useState,
} from '@wordpress/element';
import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import type { ApiError } from '../../api/client';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Skeleton } from '../../components/Skeleton';
import { ToastStack, useToastStack } from '../../components/ToastStack';
import { ConfigContext } from '../../app/config-context';
import { useApi } from '../../hooks/useApi';
import type { AlertsOverlayState, OverlaySettings } from '../../types/alerts';
import { AlertSoundsCard } from './AlertSoundsCard';
import { OverlayDesigner } from './OverlayDesigner';
import { OverlaySettingsForm } from './OverlaySettingsForm';
import { DesignsCard } from './DesignsCard';
import { DEFAULT_PAGE_LAYOUT } from './page-layout';
import type { PageCard } from './page-layout';
import { useFontStylesheet } from './useFontStylesheet';
import { useOverlayApi } from './overlay-api-context';

/**
 * Whose overlay the cards show: the creator's own, the global overlay (step
 * 25.9.4) or a standard overlay (step 25.9.5, no browser source).
 */
export type OverlayVariant = 'creator' | 'global' | 'preset';

function SetupCard( {
	onCreated,
	variant,
}: {
	readonly onCreated: ( state: AlertsOverlayState ) => void;
	readonly variant: OverlayVariant;
} ) {
	const [ busy, setBusy ] = useState( false );
	const api = useOverlayApi();
	const [ error, setError ] = useState< ApiError | null >( null );

	const create = async () => {
		setBusy( true );
		setError( null );
		const result = await api.create();
		setBusy( false );

		if ( result.ok ) {
			onCreated( result.data );
		} else {
			setError( result.error );
		}
	};

	return (
		<Card
			title={
				variant === 'global'
					? __( 'Global overlay', 'merchboard' )
					: __( 'Your stream overlay', 'merchboard' )
			}
		>
			<p>
				{ variant === 'global'
					? __(
							'An overlay of its own that shows the alerts of every creator. Set it up to get the URL for a browser source; it is switched off until you switch it on.',
							'merchboard'
						)
					: __(
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
	const api = useOverlayApi();
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ shown, setShown ] = useState( false );
	// While an administrator views as the creator, the server never sends the secret URL (ADR-075).
	// Outside the dashboard (the admin's global overlay) there is no session.
	const hidden = useContext( ConfigContext )?.impersonating === true;

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
		const result = await api.sendTest();
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
		const result = await api.regenerate();
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
			{ hidden && (
				<Alert>
					{ __(
						'The overlay URL is hidden while you view as the creator. Only the creator can copy or open it.',
						'merchboard'
					) }
				</Alert>
			) }
			{ ! hidden && (
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
			) }
			<div className="merchboard-alerts__actions">
				{ ! hidden && (
					<>
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
								window.open(
									url,
									'_blank',
									'noopener,noreferrer'
								)
							}
						>
							{ __( 'Open overlay', 'merchboard' ) }
						</Button>
					</>
				) }
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

function Notices( {
	state,
	variant,
}: {
	readonly state: AlertsOverlayState;
	readonly variant: OverlayVariant;
} ) {
	if ( variant === 'preset' ) {
		return null;
	}

	if ( variant === 'global' ) {
		return state.merchalertActive || ! state.alertsEnabled ? (
			<Alert tone="warning">
				{ __(
					'Purchase alerts are not being sent (switched off in Settings → Alerts, or MerchAlert is active), so the global overlay is not shown. Its settings can be changed already.',
					'merchboard'
				) }
			</Alert>
		) : null;
	}

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

/**
 * The overlay's cards: browser source, sounds, settings and designer. The
 * global overlay (step 25.9.4) shows its own controls above them.
 *
 * @param props          Props.
 * @param props.initial  The overlay as loaded.
 * @param props.variant  Whose overlay it is.
 * @param props.controls Cards shown above the overlay's cards (the global overlay's switch and replay).
 */
export function AlertsContent( {
	initial,
	variant = 'creator',
	controls,
}: {
	readonly initial: AlertsOverlayState;
	readonly variant?: OverlayVariant;
	readonly controls?: ReactNode;
} ) {
	const [ state, setState ] = useState( initial );
	// One stack for every card's messages, so they never overlap.
	const { toasts, notify, dismiss } = useToastStack();
	const [ saveSlot, setSaveSlot ] = useState< HTMLDivElement | null >( null );
	// The settings form's unsaved values, previewed by the designer.
	const [ draft, setDraft ] = useState< OverlaySettings | null >( null );
	// A design or standard overlay taken into use changes the saved settings: the form and the designer start again.
	const [ copies, setCopies ] = useState( 0 );
	const applied = useCallback( ( next: AlertsOverlayState ) => {
		setState( next );
		setDraft( null );
		setCopies( ( count ) => count + 1 );
	}, [] );

	useEffect( () => setState( initial ), [ initial ] );

	const family =
		( draft ?? state.overlay?.settings )?.font_family ?? 'inherit';
	const font = state.fonts.find( ( option ) => option.family === family );
	// The creator's page follows the administrator's setup as loaded (step
	// 25.9.6); the global overlay and the standard overlays keep the default.
	const layout =
		variant === 'creator'
			? ( initial.page ?? DEFAULT_PAGE_LAYOUT )
			: DEFAULT_PAGE_LAYOUT;

	useFontStylesheet( font?.url ?? null );

	const extraCards: Partial< Record< PageCard, ReactNode > > = {};

	if ( state.overlay !== null ) {
		if ( variant === 'creator' ) {
			extraCards.designs = (
				<DesignsCard
					fonts={ state.fonts }
					onApplied={ applied }
					notify={ notify }
					compact={ layout.designsCompact }
				/>
			);
		}

		if ( variant !== 'preset' ) {
			extraCards.browser_source = (
				<BrowserSourceCard
					url={ state.overlay.url }
					onRegenerated={ setState }
					notify={ notify }
				/>
			);
		}

		if ( state.overlay.audio !== null ) {
			extraCards.sounds = (
				<AlertSoundsCard
					audio={ state.overlay.audio }
					onSaved={ setState }
					notify={ notify }
				/>
			);
		}
	}

	return (
		<>
			<Notices state={ state } variant={ variant } />
			{ state.overlay === null ? (
				<SetupCard onCreated={ setState } variant={ variant } />
			) : (
				<div className="merchboard-alerts">
					{ controls }
					<OverlaySettingsForm
						key={ `form-${ state.overlay.url }` }
						settings={ state.overlay.settings }
						fonts={ state.fonts }
						fontApproved={ state.overlay.fontApproved }
						onSaved={ setState }
						saveSlot={ saveSlot }
						onDraft={ setDraft }
						images={ state.overlay.images }
						onNotify={ notify }
						layout={ layout }
						extraCards={ extraCards }
						revision={ copies }
					/>
					{ /* The settings' save status, right above the designer, which is always last. */ }
					<div ref={ setSaveSlot } />
					<div>
						<OverlayDesigner
							key={ `designer-${ state.overlay.url }-${ copies }` }
							layout={ state.overlay.layout }
							settings={ state.overlay.settings }
							preview={ draft }
							images={ state.overlay.images }
							fontFamily={
								font === undefined
									? 'inherit'
									: `"${ font.family }", sans-serif`
							}
							onSaved={ setState }
							onNotify={ notify }
						/>
					</div>
				</div>
			) }
			<ToastStack toasts={ toasts } onDismiss={ dismiss } />
		</>
	);
}

/**
 * "Alerts" (ADR-065): the creator's OBS overlay. Set up, browser source URL
 * (copy, open, replace), test alert, the overlay's look and timing, the
 * designer and the sounds.
 */
export function AlertsSection() {
	const api = useOverlayApi();
	const { state, retry } = useApi( api.get );

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
