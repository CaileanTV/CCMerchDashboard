/**
 * Entry point of the global overlay tab (Merch Dashboard → Alerts → Global
 * overlay, step 25.9.4; build/alerts-global-admin.js). It mounts the creator
 * dashboard's overlay cards with the global overlay's own calls, so they
 * never touch a creator's overlay, plus the global overlay's switch and
 * "Replay latest order".
 */
import { createRoot, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	getGlobalOverlay,
	globalOverlayApi,
	replayLatestOrder,
	setGlobalOverlayEnabled,
} from '../dashboard/api/alerts-global';
import type { ApiError } from '../dashboard/api/client';
import { Alert } from '../dashboard/components/Alert';
import { ApiErrorState } from '../dashboard/components/ApiErrorState';
import { Button } from '../dashboard/components/Button';
import { Card } from '../dashboard/components/Card';
import { Skeleton } from '../dashboard/components/Skeleton';
import { useApi } from '../dashboard/hooks/useApi';
import { AlertsContent } from '../dashboard/sections/alerts/AlertsSection';
import { OverlayApiContext } from '../dashboard/sections/alerts/overlay-api-context';
import '../dashboard/styles/index.scss';

/**
 * The switch and "Replay latest order".
 *
 * @param props         Props.
 * @param props.initial Whether the overlay is switched on.
 */
export function GlobalControls( { initial }: { readonly initial: boolean } ) {
	const [ enabled, setEnabled ] = useState( initial );
	const [ busy, setBusy ] = useState< 'switch' | 'replay' | null >( null );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ message, setMessage ] = useState< string | null >( null );

	const toggle = async () => {
		setBusy( 'switch' );
		setError( null );
		setMessage( null );
		const result = await setGlobalOverlayEnabled( ! enabled );
		setBusy( null );

		if ( result.ok ) {
			setEnabled( result.data.enabled );
		} else {
			setError( result.error );
		}
	};

	const replay = async () => {
		setBusy( 'replay' );
		setError( null );
		setMessage( null );
		const result = await replayLatestOrder();
		setBusy( null );

		if ( result.ok ) {
			setMessage(
				sprintf(
					/* translators: %d: number of alerts. */
					_n(
						'The latest order was sent again (%d alert). It appears in the open overlay within a few seconds.',
						'The latest order was sent again (%d alerts). It appears in the open overlay within a few seconds.',
						result.data,
						'merchboard'
					),
					result.data
				)
			);
		} else {
			setError( result.error );
		}
	};

	return (
		<Card title={ __( 'Global overlay', 'merchboard' ) }>
			<p>
				{ enabled
					? __(
							'On. The overlay shows the alerts of every creator while purchase alerts are switched on.',
							'merchboard'
						)
					: __(
							'Off. The overlay URL shows nothing until you switch it on.',
							'merchboard'
						) }
			</p>
			{ error !== null && <ApiErrorState error={ error } /> }
			{ message !== null && <Alert tone="success">{ message }</Alert> }
			<div className="merchboard-alerts__actions">
				<Button onClick={ toggle } isBusy={ busy === 'switch' }>
					{ enabled
						? __( 'Switch off', 'merchboard' )
						: __( 'Switch on', 'merchboard' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ replay }
					isBusy={ busy === 'replay' }
				>
					{ __( 'Replay latest order', 'merchboard' ) }
				</Button>
			</div>
		</Card>
	);
}

/**
 * The tab's content.
 */
export function GlobalOverlayAdmin() {
	const { state, retry } = useApi( getGlobalOverlay );

	if ( state.status === 'loading' ) {
		return (
			<Skeleton
				label={ __( 'Loading the global overlay…', 'merchboard' ) }
				lines={ 4 }
			/>
		);
	}

	if ( state.status === 'error' ) {
		return <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	return (
		<AlertsContent
			initial={ state.data.state }
			variant="global"
			controls={ <GlobalControls initial={ state.data.enabled } /> }
		/>
	);
}

const root = document.getElementById( 'merchboard-global-overlay-root' );

if ( root !== null ) {
	createRoot( root ).render(
		<OverlayApiContext.Provider value={ globalOverlayApi }>
			<GlobalOverlayAdmin />
		</OverlayApiContext.Provider>
	);
}
