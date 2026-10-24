import { useCallback, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { getAlertApi, testAllWebhooks } from '../../api/alertApi';
import type { AlertApiState, WebhookDeliveryItem } from '../../api/alertApi';
import type { ApiError } from '../../api/client';
import { useConfig } from '../../app/config-context';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Skeleton } from '../../components/Skeleton';
import { Toast } from '../../components/Toast';
import { useApi } from '../../hooks/useApi';
import { apiTime } from './AlertsApiSection';
import { deliveryOutcome, WebhooksCard } from './WebhooksCard';

/**
 * "Webhooks" under Alerts (step 25.12.2, ADR-073): the shop sends each
 * purchase to the creator's own bot or a Discord channel. Its own page,
 * apart from the API keys (the bot asks); both read `/me/alerts/api`.
 */
export function AlertsWebhooksSection() {
	const { state, retry } = useApi( getAlertApi );

	if ( state.status === 'loading' ) {
		return (
			<Skeleton
				label={ __( 'Loading the webhooks…', 'merchboard' ) }
				lines={ 4 }
			/>
		);
	}

	if ( state.status === 'error' ) {
		return <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	return <WebhooksPanel initial={ state.data } />;
}

/**
 * The page with its data.
 *
 * @param props         Props.
 * @param props.initial The first answer.
 */
function WebhooksPanel( { initial }: { readonly initial: AlertApiState } ) {
	const { locale } = useConfig();
	const [ api, setApi ] = useState< AlertApiState >( initial );
	const [ toast, setToast ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ tested, setTested ] = useState<
		readonly WebhookDeliveryItem[] | null
	>( null );
	const dismissToast = useCallback( () => setToast( null ), [] );
	const switchedOn = api.webhooks.filter( ( webhook ) => webhook.enabled );
	const names = new Map(
		api.webhooks.map( ( webhook ) => [ webhook.id, webhook.name ] )
	);

	const testAll = async () => {
		setBusy( true );
		setError( null );
		const answer = await testAllWebhooks();
		setBusy( false );

		if ( ! answer.ok ) {
			setError( answer.error );

			return;
		}

		setApi( answer.data );
		setTested( answer.data.tested ?? [] );
	};

	return (
		<div className="merchboard-history merchboard-alert-api">
			{ ! api.enabled && (
				<Alert tone="warning">
					{ __(
						'The alert API is switched off by the shop. Your webhooks are kept, but nothing is sent until it is switched on again.',
						'merchboard'
					) }
				</Alert>
			) }
			<WebhooksCard
				api={ api }
				onChange={ setApi }
				notify={ setToast }
				when={ ( iso ) => apiTime( iso, locale ) }
			/>
			<Card
				title={ __( 'Test', 'merchboard' ) }
				actions={
					<Button
						variant="secondary"
						isBusy={ busy }
						disabled={ ! api.enabled || switchedOn.length === 0 }
						onClick={ () => void testAll() }
					>
						{ __( 'Send test to all webhooks', 'merchboard' ) }
					</Button>
				}
			>
				<p>
					{ switchedOn.length === 0
						? __(
								'Add a webhook, or switch one on, to send a test.',
								'merchboard'
							)
						: __(
								'Sends a test purchase, marked "test", to each switched-on webhook at once and shows the answer of each. Tests never count towards a pause.',
								'merchboard'
							) }
				</p>
				{ error !== null && <ApiErrorState error={ error } /> }
				{ tested !== null && (
					<ul
						className="merchboard-alert-api__deliveries"
						role="status"
					>
						{ tested.map( ( delivery ) => (
							<li key={ delivery.id }>
								{ sprintf(
									/* translators: 1: webhook name, 2: outcome. */
									__( '%1$s: %2$s', 'merchboard' ),
									names.get( delivery.webhookId ) ?? '',
									deliveryOutcome( delivery )
								) }
							</li>
						) ) }
					</ul>
				) }
			</Card>
			{ toast !== null && (
				<Toast message={ toast } onDismiss={ dismissToast } />
			) }
		</div>
	);
}
