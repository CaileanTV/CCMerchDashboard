/**
 * "You are not live yet" at the top of the dashboard (ADR-091): shown only
 * to a creator who signed up and is not live. They prepare their shop and
 * ask to go live once the agreement is accepted; then the request waits,
 * or a declined one shows the administrator's note and can be sent again.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { askGoLive, getGoLive } from '../api/goLive';
import type { GoLiveState } from '../api/goLive';
import { useApi } from '../hooks/useApi';
import { Alert } from './Alert';
import { Button } from './Button';
import { Card } from './Card';

/** Fired by the agreement when it was accepted, so asking becomes possible. */
export const AGREEMENT_ACCEPTED = 'merchboard:agreement-accepted';

/**
 * The message of a refused request.
 *
 * @param reason The server's reason.
 */
function refusal( reason: string | undefined ): string {
	switch ( reason ) {
		case 'agreement_required':
			return __(
				'Please accept our agreement first. You find it at the top of your dashboard.',
				'merchboard'
			);
		case 'request_pending':
			return __( 'Your request is waiting already.', 'merchboard' );
		default:
			return __(
				'The request could not be sent. Please try again.',
				'merchboard'
			);
	}
}

export function GoLiveNotice() {
	const { state, retry } = useApi( getGoLive );
	const [ sent, setSent ] = useState< GoLiveState | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		const refresh = () => {
			setSent( null );
			retry();
		};

		window.addEventListener( AGREEMENT_ACCEPTED, refresh );

		return () => window.removeEventListener( AGREEMENT_ACCEPTED, refresh );
	}, [ retry ] );

	const ask = useCallback( async () => {
		setBusy( true );
		setError( null );
		const result = await askGoLive();
		setBusy( false );

		if ( result.ok ) {
			setSent( result.data );
		} else {
			setError( refusal( result.error.reason ) );
		}
	}, [] );

	const current = sent ?? ( state.status === 'success' ? state.data : null );

	if ( current === null || current.live ) {
		return null;
	}

	const asked = current.request;
	const pending = asked?.status === 'pending';

	return (
		<div className="merchboard-go-live-notice">
			<Card title={ __( 'You are not live yet', 'merchboard' ) }>
				<p>
					{ __(
						'Prepare your shop: your profile, your category and your designs. Nothing is shown in the shop until we have approved your request to go live.',
						'merchboard'
					) }
				</p>
				{ pending && asked !== null && (
					<Alert tone="info">
						{ sprintf(
							/* translators: %s: date the request was sent. */
							__(
								'Your request to go live is waiting for our approval (sent %s).',
								'merchboard'
							),
							asked.requestedAt.slice( 0, 10 )
						) }
					</Alert>
				) }
				{ asked?.status === 'declined' && (
					<Alert tone="warning">
						{ __(
							'We could not approve your request yet:',
							'merchboard'
						) }{ ' ' }
						{ asked.note }
					</Alert>
				) }
				{ ! pending && (
					<p>
						<Button
							onClick={ ask }
							isBusy={ busy }
							disabled={ ! current.agreement }
						>
							{ asked?.status === 'declined'
								? __( 'Ask again to go live', 'merchboard' )
								: __( 'Ask to go live', 'merchboard' ) }
						</Button>
						{ ! current.agreement && (
							<span className="merchboard-go-live-notice__hint">
								{ ' ' }
								{ __(
									'Accept our agreement first.',
									'merchboard'
								) }
							</span>
						) }
					</p>
				) }
				{ error !== null && <Alert tone="danger">{ error }</Alert> }
			</Card>
		</div>
	);
}
