import { useCallback, useEffect, useId, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	getAlertHistory,
	getAlertPreferences,
	resendAlert,
	resendOrder,
	stopAlerts,
	updateAlertPreferences,
} from '../../api/alertHistory';
import type { ApiError, ApiResult, Paginated } from '../../api/client';
import { useConfig } from '../../app/config-context';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { EmptyState } from '../../components/EmptyState';
import { Pagination } from '../../components/Pagination';
import { Table } from '../../components/Table';
import type { TableColumn } from '../../components/Table';
import { Toast } from '../../components/Toast';
import { usePagedApi } from '../../hooks/usePagedApi';
import type { HistoryOrder } from '../../types/alerts';

/** Orders per page (as MerchAlert's history). */
export const HISTORY_PER_PAGE = 10;

/** The history refreshes itself this often while the page is shown (seconds). */
export const HISTORY_REFRESH = 60;

/**
 * A username as shown with "Anonymize usernames": its first character and
 * dots, so the history can be shown on stream.
 *
 * @param username Username; '' for none.
 */
export function anonymize( username: string ): string {
	const first = Array.from( username )[ 0 ];

	return first === undefined ? '' : `${ first }•••`;
}

/**
 * A time of the API (ISO 8601 UTC) in the site's language and the viewer's
 * time zone.
 *
 * @param iso    ISO 8601 time.
 * @param locale WordPress locale, e.g. da_DK.
 */
function timeText( iso: string, locale: string ): string {
	try {
		return new Intl.DateTimeFormat( locale.replace( '_', '-' ), {
			dateStyle: 'medium',
			timeStyle: 'short',
		} ).format( new Date( iso ) );
	} catch {
		return iso.slice( 0, 16 ).replace( 'T', ' ' );
	}
}

function sentMessage( sent: number ): string {
	return sprintf(
		/* translators: %d: number of alerts. */
		_n(
			'%d alert was sent to your overlay.',
			'%d alerts were sent to your overlay.',
			sent,
			'merchboard'
		),
		sent
	);
}

/**
 * "Stop all alerts": ends the alert the overlay shows (and its sound) and
 * drops every alert that waits, after a confirmation.
 *
 * @param props        Props.
 * @param props.notify Shows a short confirmation.
 */
function StopCard( {
	notify,
}: {
	readonly notify: ( message: string ) => void;
} ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< ApiError | null >( null );

	const stop = async () => {
		if (
			// eslint-disable-next-line no-alert -- Stopping all alerts needs an explicit confirmation.
			! window.confirm(
				__(
					'Stop all alerts now? The alert on stream disappears at once and every alert that waits is dropped.',
					'merchboard'
				)
			)
		) {
			return;
		}

		setBusy( true );
		setError( null );
		const result = await stopAlerts();
		setBusy( false );

		if ( result.ok ) {
			notify(
				__(
					'All alerts were stopped. New purchases are shown as usual.',
					'merchboard'
				)
			);
		} else {
			setError( result.error );
		}
	};

	return (
		<Card title={ __( 'Control', 'merchboard' ) }>
			<p>
				{ __(
					'Stop all alerts ends the alert on stream at once, also its sound, and drops every alert that waits, also purchases the overlay has not shown yet and resent alerts. New purchases after that are shown as usual.',
					'merchboard'
				) }
			</p>
			{ error !== null && <ApiErrorState error={ error } /> }
			<Button variant="danger" onClick={ stop } isBusy={ busy }>
				{ __( 'Stop all alerts', 'merchboard' ) }
			</Button>
		</Card>
	);
}

/**
 * "Alerts → History" (phase 25, step 7): the creator's orders with alerts,
 * 10 a page and newest first, each with its alerts, and resend of an alert
 * or a whole order. "Refresh" at the top right counts down to the next
 * automatic refresh (every minute while the page is shown) and loads the
 * page again at once when pressed; the orders shown stay until the new ones arrive, so
 * nothing flickers and open details stay open. "Anonymize usernames"
 * shortens the customers'
 * usernames on this page only; the choice is saved for the logged-in user
 * (`/me/alerts/preferences`, never in browser storage). "Stop all alerts"
 * clears the overlay at once.
 */
export function AlertsHistorySection() {
	const config = useConfig();
	const { state, page, setPage, retry } = usePagedApi(
		getAlertHistory,
		HISTORY_PER_PAGE
	);
	const [ knownPages, setKnownPages ] = useState( 0 );
	// The last loaded page, shown while the same page refreshes.
	const [ shown, setShown ] = useState< Paginated< HistoryOrder > | null >(
		null
	);
	const id = useId();
	const [ anonymous, setAnonymous ] = useState( false );
	const [ busy, setBusy ] = useState< string | null >( null );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );

	useEffect( () => {
		if ( state.status === 'success' ) {
			setKnownPages( state.data.totalPages );
			setShown( state.data );
		}
	}, [ state ] );

	// Seconds to the next automatic refresh; it counts while the tab is visible.
	const [ countdown, setCountdown ] = useState( HISTORY_REFRESH );

	useEffect( () => {
		const timer = window.setInterval( () => {
			if ( document.visibilityState !== 'hidden' ) {
				setCountdown( ( left ) => left - 1 );
			}
		}, 1000 );

		return () => window.clearInterval( timer );
	}, [] );

	useEffect( () => {
		if ( countdown <= 0 ) {
			setCountdown( HISTORY_REFRESH );
			retry();
		}
	}, [ countdown, retry ] );

	const refresh = () => {
		setCountdown( HISTORY_REFRESH );
		retry();
	};

	// The saved choice; until it arrives, usernames are shortened only if chosen.
	useEffect( () => {
		const controller = new AbortController();

		void getAlertPreferences( controller.signal ).then( ( result ) => {
			if ( result.ok ) {
				setAnonymous( result.data.anonymize );
			}
		} );

		return () => controller.abort();
	}, [] );

	const toggleAnonymous = async ( on: boolean ) => {
		setAnonymous( on );
		setError( null );
		const result = await updateAlertPreferences( on );

		if ( ! result.ok ) {
			setAnonymous( ! on );
			setError( result.error );
		}
	};

	const resend = async (
		key: string,
		send: () => Promise< ApiResult< number > >
	) => {
		setBusy( key );
		setError( null );
		const result = await send();
		setBusy( null );

		if ( result.ok ) {
			setToast( sentMessage( result.data ) );
		} else {
			setError( result.error );
		}
	};

	const name = ( order: HistoryOrder ) => {
		if ( order.username === '' ) {
			return __( 'No username', 'merchboard' );
		}

		return anonymous ? anonymize( order.username ) : order.username;
	};

	const columns: readonly TableColumn< HistoryOrder >[] = [
		{
			key: 'order',
			header: __( 'Order', 'merchboard' ),
			render: ( order ) => `#${ order.orderNumber }`,
		},
		{
			key: 'time',
			header: __( 'Time', 'merchboard' ),
			render: ( order ) => (
				<time dateTime={ order.createdAt }>
					{ timeText( order.createdAt, config.locale ) }
				</time>
			),
		},
		{
			key: 'username',
			header: __( 'Username', 'merchboard' ),
			render: name,
		},
		{
			key: 'alerts',
			header: __( 'Alerts', 'merchboard' ),
			render: ( order ) => (
				<details className="merchboard-history__details">
					<summary>
						{ sprintf(
							/* translators: %d: number of alerts. */
							_n(
								'%d alert',
								'%d alerts',
								order.alerts.length,
								'merchboard'
							),
							order.alerts.length
						) }
					</summary>
					<ul className="merchboard-history__alerts">
						{ order.alerts.map( ( alert ) => (
							<li key={ alert.id }>
								<span>
									{ sprintf(
										/* translators: 1: quantity, 2: product name. */
										__( '%1$d × %2$s', 'merchboard' ),
										alert.quantity,
										alert.product
									) }
									{ alert.variation !== '' &&
										` (${ alert.variation })` }
								</span>
								<Button
									variant="ghost"
									size="sm"
									isBusy={ busy === `alert-${ alert.id }` }
									disabled={ busy !== null }
									onClick={ () =>
										void resend(
											`alert-${ alert.id }`,
											() => resendAlert( alert.id )
										)
									}
								>
									{ __( 'Resend alert', 'merchboard' ) }
								</Button>
							</li>
						) ) }
					</ul>
				</details>
			),
		},
		{
			key: 'actions',
			header: __( 'Resend', 'merchboard' ),
			align: 'end',
			render: ( order ) => (
				<Button
					variant="secondary"
					size="sm"
					isBusy={ busy === `order-${ order.orderId }` }
					disabled={ busy !== null }
					onClick={ () =>
						void resend( `order-${ order.orderId }`, () =>
							resendOrder( order.orderId )
						)
					}
					aria-label={ sprintf(
						/* translators: %s: order number. */
						__( 'Resend order #%s', 'merchboard' ),
						order.orderNumber
					) }
				>
					{ __( 'Resend order', 'merchboard' ) }
				</Button>
			),
		},
	];

	const title = __( 'Your alerts', 'merchboard' );
	const refreshing =
		state.status === 'loading' && shown !== null && shown.page === page;
	let orders: readonly HistoryOrder[] = [];

	if ( state.status === 'success' ) {
		orders = state.data.items;
	} else if ( refreshing ) {
		orders = shown.items;
	}

	return (
		<div className="merchboard-history">
			<StopCard notify={ setToast } />
			<Card
				title={ title }
				actions={
					<Button
						variant="secondary"
						size="sm"
						onClick={ refresh }
						isBusy={ state.status === 'loading' }
						aria-label={ __( 'Refresh', 'merchboard' ) }
						title={ __(
							'The list also refreshes itself every minute.',
							'merchboard'
						) }
					>
						{ sprintf(
							/* translators: %d: seconds to the next automatic refresh. */
							__( 'Refresh (%d)', 'merchboard' ),
							countdown
						) }
					</Button>
				}
			>
				<label
					className="merchboard-alerts__check"
					htmlFor={ `${ id }-anonymize` }
				>
					<input
						id={ `${ id }-anonymize` }
						type="checkbox"
						checked={ anonymous }
						onChange={ ( event ) =>
							void toggleAnonymous( event.target.checked )
						}
					/>{ ' ' }
					{ __( 'Anonymize usernames', 'merchboard' ) }
				</label>
				<p className="merchboard-field__help">
					{ __(
						'Shortens the usernames on this page, e.g. when you show it on stream. Resent alerts show the real username, as the original alert did.',
						'merchboard'
					) }
				</p>
				{ error !== null && <ApiErrorState error={ error } /> }
				{ state.status === 'error' ? (
					<ApiErrorState error={ state.error } onRetry={ retry } />
				) : (
					<Table
						caption={ title }
						captionHidden
						columns={ columns }
						rows={ orders }
						rowKey={ ( order ) => order.orderId }
						loading={ state.status === 'loading' && ! refreshing }
						loadingLabel={ __( 'Loading alerts…', 'merchboard' ) }
						empty={
							page > 1 ? (
								<EmptyState
									title={ __(
										'There are no alerts on this page.',
										'merchboard'
									) }
								/>
							) : (
								<EmptyState
									title={ __(
										'You have no alerts yet.',
										'merchboard'
									) }
									description={ __(
										'Paid orders of your products show up here once alerts are switched on.',
										'merchboard'
									) }
								/>
							)
						}
					/>
				) }
				<Pagination
					page={ page }
					totalPages={
						state.status === 'success'
							? state.data.totalPages
							: knownPages
					}
					onChange={ setPage }
					label={ __( 'Alert pages', 'merchboard' ) }
					disabled={ state.status === 'loading' }
				/>
			</Card>
			<Toast message={ toast } onDismiss={ dismissToast } />
		</div>
	);
}
