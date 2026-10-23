import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getAlertApi } from '../../api/alertApi';
import type { AlertApiState } from '../../api/alertApi';
import { useConfig } from '../../app/config-context';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Skeleton } from '../../components/Skeleton';
import { Toast } from '../../components/Toast';
import { useApi } from '../../hooks/useApi';
import { apiTime } from './AlertsApiSection';
import { WebhooksCard } from './WebhooksCard';

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
	const dismissToast = useCallback( () => setToast( null ), [] );

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
			{ toast !== null && (
				<Toast message={ toast } onDismiss={ dismissToast } />
			) }
		</div>
	);
}
