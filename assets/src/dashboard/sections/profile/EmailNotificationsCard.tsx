import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	getEmailPreferences,
	updateEmailPreferences,
} from '../../api/notifications';
import type { EmailPreferences } from '../../api/notifications';
import type { ApiError } from '../../api/client';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Card } from '../../components/Card';
import { Skeleton } from '../../components/Skeleton';
import { Toast } from '../../components/Toast';
import { useApi } from '../../hooks/useApi';

/**
 * The creator's e-mail choices (phase 29, ADR-079): one switch per
 * notification e-mail the creator may turn off. Important e-mails (payouts,
 * settlement documents, security and the account) are always sent and are
 * not listed. A change is saved at once with
 * `PATCH /me/notification-preferences`; a failed save restores the saved
 * choices. The bell in the dashboard is not affected.
 */
export function EmailNotificationsCard() {
	const { state, retry } = useApi( getEmailPreferences );
	const [ saved, setSaved ] = useState< EmailPreferences | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );

	useEffect( () => {
		if ( state.status === 'success' ) {
			setSaved( state.data );
		}
	}, [ state ] );

	const title = __( 'E-mail notifications', 'merchboard' );

	if (
		state.status === 'loading' ||
		( state.status === 'success' && saved === null )
	) {
		return (
			<Card title={ title }>
				<Skeleton
					label={ __(
						'Loading e-mail notifications…',
						'merchboard'
					) }
					lines={ 2 }
				/>
			</Card>
		);
	}

	if ( state.status === 'error' || saved === null ) {
		return (
			<Card title={ title }>
				{ state.status === 'error' && (
					<ApiErrorState error={ state.error } onRetry={ retry } />
				) }
			</Card>
		);
	}

	const toggle = async ( key: string, enabled: boolean ) => {
		const previous = saved;
		const off = previous.items
			.filter( ( item ) =>
				item.key === key ? ! enabled : ! item.enabled
			)
			.map( ( item ) => item.key );

		setSaved( {
			...previous,
			items: previous.items.map( ( item ) =>
				item.key === key ? { ...item, enabled } : item
			),
		} );
		setBusy( true );
		setError( null );

		const result = await updateEmailPreferences( off );
		setBusy( false );

		if ( result.ok ) {
			setSaved( result.data );
			setToast( __( 'E-mail notifications saved.', 'merchboard' ) );
		} else {
			setSaved( previous );
			setError( result.error );
		}
	};

	return (
		<Card title={ title }>
			<p className="merchboard-field__help">
				{ __(
					'Choose which e-mails you want. E-mails about payouts, settlement documents, your discount code, security and your account are always sent. All notifications are also shown at the bell at the top of the dashboard.',
					'merchboard'
				) }
			</p>
			{ ! saved.hasUser && (
				<p className="merchboard-field__help">
					{ __(
						'Your creator profile has no login, so no e-mails are sent.',
						'merchboard'
					) }
				</p>
			) }
			{ error !== null && <ApiErrorState error={ error } /> }
			{ saved.hasUser && saved.items.length === 0 && (
				<p className="merchboard-field__help">
					{ __(
						'There are no e-mails you can turn off right now.',
						'merchboard'
					) }
				</p>
			) }
			{ saved.hasUser && saved.items.length > 0 && (
				<ul className="merchboard-email-choices">
					{ saved.items.map( ( item ) => (
						<li
							key={ item.key }
							className="merchboard-email-choices__item"
						>
							<input
								id={ `merchboard-email-${ item.key }` }
								type="checkbox"
								checked={ item.enabled }
								disabled={ busy }
								aria-describedby={ `merchboard-email-${ item.key }-description` }
								onChange={ ( event ) =>
									void toggle(
										item.key,
										event.target.checked
									)
								}
							/>
							<label
								className="merchboard-email-choices__name"
								htmlFor={ `merchboard-email-${ item.key }` }
							>
								{ item.label }
							</label>
							<p
								id={ `merchboard-email-${ item.key }-description` }
								className="merchboard-email-choices__description"
							>
								{ item.description }
							</p>
						</li>
					) ) }
				</ul>
			) }
			<Toast message={ toast } onDismiss={ dismissToast } />
		</Card>
	);
}
