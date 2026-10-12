import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { cancelPayout, getPayoutBalance, getPayouts } from '../../api/payouts';
import { useConfig } from '../../app/config-context';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { EmptyState } from '../../components/EmptyState';
import { Pagination } from '../../components/Pagination';
import { Skeleton } from '../../components/Skeleton';
import { Table } from '../../components/Table';
import type { TableColumn } from '../../components/Table';
import { useApi } from '../../hooks/useApi';
import { usePagedApi } from '../../hooks/usePagedApi';
import type {
	Payout,
	PayoutBalance,
	PayoutCurrencyBalance,
} from '../../types/payouts';
import { moneyText } from '../sales/format';
import { GiftCardReveal } from './GiftCardReveal';
import { PayoutRequestForm } from './PayoutRequestForm';
import { SettlementDocumentView } from './SettlementDocumentView';
import { methodLabel, refusalMessage, statusLabel } from './texts';

/**
 * A moment from the API (ISO 8601 UTC) as a date in the site's language and
 * the viewer's time zone; the API value itself if the locale is not supported.
 *
 * @param iso    ISO 8601 date.
 * @param locale WordPress locale.
 */
function dateText( iso: string, locale: string ): string {
	try {
		return new Intl.DateTimeFormat( locale.replace( '_', '-' ), {
			dateStyle: 'medium',
		} ).format( new Date( iso ) );
	} catch {
		return iso.slice( 0, 10 );
	}
}

/**
 * The figures of one currency. An unknown balance shows "Unknown" with an
 * explanation, never an amount (ADR-054): 0 would be wrong.
 */
function CurrencyBalance( {
	entry,
	locale,
}: {
	readonly entry: PayoutCurrencyBalance;
	readonly locale: string;
} ) {
	const money = ( amount: string | null ) =>
		amount === null
			? __( 'Unknown', 'merchboard' )
			: moneyText( amount, entry.currency, locale );
	const figures: { id: string; label: string; value: string }[] = [
		{
			id: 'available',
			label: __( 'Available', 'merchboard' ),
			value: money( entry.available ),
		},
		{
			id: 'reserved',
			label: __( 'Requested, pending', 'merchboard' ),
			value: money( entry.reserved ),
		},
		{
			id: 'completed',
			label: __( 'Paid out', 'merchboard' ),
			value: money( entry.completed ),
		},
		{
			id: 'earned',
			label: __( 'Earned after the holding period', 'merchboard' ),
			value: money( entry.earned ),
		},
	];

	if ( entry.outstanding !== null && entry.outstanding !== '0.0000' ) {
		figures.push( {
			id: 'outstanding',
			label: __( 'Outstanding', 'merchboard' ),
			value: money( entry.outstanding ),
		} );
	}

	return (
		<section
			className="merchboard-kpi-currency"
			aria-labelledby={ `merchboard-payout-${ entry.currency }` }
		>
			<h3
				id={ `merchboard-payout-${ entry.currency }` }
				className="merchboard-kpi-currency__title"
			>
				{ entry.currency }
			</h3>
			{ entry.status === 'unknown' && (
				<Alert tone="warning">
					{ sprintf(
						/* translators: %d: number of sales. */
						__(
							'Your balance is unknown: %d sales lack the data needed to calculate your payout. No payout can be requested until this is resolved.',
							'merchboard'
						),
						entry.unknownRows
					) }
				</Alert>
			) }
			<ul className="merchboard-kpi-grid">
				{ figures.map( ( figure ) => (
					<li
						key={ figure.id }
						className={ `merchboard-card merchboard-kpi merchboard-kpi--${ figure.id }` }
					>
						<h4 className="merchboard-kpi__label">
							{ figure.label }
						</h4>
						<p className="merchboard-kpi__value">
							{ figure.value }
						</p>
					</li>
				) ) }
			</ul>
		</section>
	);
}

function BalanceCard( {
	balance,
	locale,
}: {
	readonly balance: PayoutBalance;
	readonly locale: string;
} ) {
	return (
		<Card title={ __( 'Your balance', 'merchboard' ) }>
			<ul className="merchboard-payouts__facts">
				<li>
					{ sprintf(
						/* translators: %d: number of days. */
						__(
							'Sales count after a holding period of %d days. Refunds count at once.',
							'merchboard'
						),
						balance.holdingDays
					) }
				</li>
				<li>
					{ balance.payoutStart === null
						? __( 'All your sales count.', 'merchboard' )
						: sprintf(
								/* translators: %s: date. */
								__( 'Sales from %s count.', 'merchboard' ),
								dateText( balance.payoutStart, locale )
							) }
				</li>
				<li>
					{ balance.methodsUsable
						? __(
								'Payout methods: bank transfer and PayPal.',
								'merchboard'
							)
						: __(
								'Bank transfer and PayPal are not available right now.',
								'merchboard'
							) }
				</li>
				{ balance.giftCardUsable && (
					<li>
						{ __(
							'You can also choose a gift card for the shop.',
							'merchboard'
						) }
					</li>
				) }
			</ul>
			{ balance.currencies.length === 0 ? (
				<p>
					{ __(
						'You have no earnings to pay out yet.',
						'merchboard'
					) }
				</p>
			) : (
				balance.currencies.map( ( entry ) => (
					<CurrencyBalance
						key={ entry.currency }
						entry={ entry }
						locale={ locale }
					/>
				) )
			) }
		</Card>
	);
}

/**
 * Cancel of a pending request in two steps (no browser dialog): "Cancel
 * request", then "Confirm cancellation" or "Keep".
 */
function CancelAction( {
	payout,
	onCancelled,
	onRefused,
}: {
	readonly payout: Payout;
	readonly onCancelled: () => void;
	readonly onRefused: ( message: string ) => void;
} ) {
	const [ confirming, setConfirming ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	if ( payout.status !== 'pending' ) {
		return null;
	}

	if ( ! confirming ) {
		return (
			<Button
				variant="secondary"
				size="sm"
				onClick={ () => setConfirming( true ) }
			>
				{ __( 'Cancel request', 'merchboard' ) }
			</Button>
		);
	}

	const confirm = async () => {
		setBusy( true );
		const result = await cancelPayout( payout.id );
		setBusy( false );
		setConfirming( false );

		if ( result.ok ) {
			onCancelled();
			return;
		}

		onRefused(
			result.error.kind === 'not_found'
				? __( 'This request could not be found.', 'merchboard' )
				: ( refusalMessage( result.error ) ??
						__(
							'The request could not be cancelled. Please try again in a moment.',
							'merchboard'
						) )
		);
	};

	return (
		<span className="merchboard-payouts__confirm">
			<Button
				variant="danger"
				size="sm"
				isBusy={ busy }
				onClick={ confirm }
			>
				{ __( 'Confirm cancellation', 'merchboard' ) }
			</Button>{ ' ' }
			<Button
				variant="ghost"
				size="sm"
				disabled={ busy }
				onClick={ () => setConfirming( false ) }
			>
				{ __( 'Keep', 'merchboard' ) }
			</Button>
		</span>
	);
}

const columns = (
	locale: string,
	onCancelled: () => void,
	onRefused: ( message: string ) => void
): readonly TableColumn< Payout >[] => [
	{
		key: 'requested',
		header: __( 'Requested', 'merchboard' ),
		render: ( payout ) => (
			<time dateTime={ payout.requestedAt }>
				{ dateText( payout.requestedAt, locale ) }
			</time>
		),
	},
	{
		key: 'amount',
		header: __( 'Amount', 'merchboard' ),
		render: ( payout ) =>
			moneyText( payout.amount, payout.currency, locale ),
		align: 'end',
	},
	{
		key: 'method',
		header: __( 'Method', 'merchboard' ),
		render: ( payout ) => (
			<span>
				{ methodLabel( payout.method ) }
				{ payout.destinationHint !== '' && (
					<span className="merchboard-payouts__hint">
						{ ' ' }
						{ payout.destinationHint }
					</span>
				) }
			</span>
		),
	},
	{
		key: 'status',
		header: __( 'Status', 'merchboard' ),
		render: ( payout ) => (
			<span>
				<span className="merchboard-badge">
					{ statusLabel( payout.status ) }
				</span>
				{ payout.rejectionReason !== null && (
					<span className="merchboard-payouts__reason">
						{ ' ' }
						{ payout.rejectionReason }
					</span>
				) }
			</span>
		),
	},
	{
		key: 'decided',
		header: __( 'Handled', 'merchboard' ),
		render: ( payout ) =>
			payout.decidedAt === null ? (
				<span>
					<span aria-hidden="true">–</span>
					<span className="merchboard-visually-hidden">
						{ __( 'Not yet', 'merchboard' ) }
					</span>
				</span>
			) : (
				<time dateTime={ payout.decidedAt }>
					{ dateText( payout.decidedAt, locale ) }
				</time>
			),
	},
	{
		key: 'actions',
		header: __( 'Actions', 'merchboard' ),
		render: ( payout ) => (
			<>
				<CancelAction
					payout={ payout }
					onCancelled={ onCancelled }
					onRefused={ onRefused }
				/>
				<GiftCardReveal payout={ payout } locale={ locale } />
				<SettlementDocumentView payout={ payout } locale={ locale } />
			</>
		),
	},
];

/**
 * Payouts (phase 23, ADR-054–056): the creator's balance per currency from
 * `GET /me/payouts/balance`, the request form (`POST /me/payouts`) and the
 * creator's own requests from `GET /me/payouts`, with cancellation of a
 * pending one. Every rule is the server's; after a request or cancellation
 * both the balance and the list are loaded again. Only masked payment
 * details are ever shown.
 */
export function PayoutsSection() {
	const config = useConfig();
	const balance = useApi( getPayoutBalance );
	const list = usePagedApi( getPayouts );
	const [ knownPages, setKnownPages ] = useState( 0 );
	const [ notice, setNotice ] = useState< {
		readonly tone: 'success' | 'danger';
		readonly text: string;
	} | null >( null );
	const reloadBalance = balance.retry;
	const reloadList = list.retry;
	const reload = useCallback( () => {
		reloadBalance();
		reloadList();
	}, [ reloadBalance, reloadList ] );
	const onCancelled = useCallback( () => {
		setNotice( {
			tone: 'success',
			text: __( 'The request has been cancelled.', 'merchboard' ),
		} );
		reload();
	}, [ reload ] );
	const onRefused = useCallback(
		( text: string ) => setNotice( { tone: 'danger', text } ),
		[]
	);

	useEffect( () => {
		if ( list.state.status === 'success' ) {
			setKnownPages( list.state.data.totalPages );
		}
	}, [ list.state ] );

	// The last loaded balance stays on screen while it is loaded again after
	// a request or cancellation, so the form (and its confirmation) remains.
	const [ shown, setShown ] = useState< PayoutBalance | null >( null );

	useEffect( () => {
		if ( balance.state.status === 'success' ) {
			setShown( balance.state.data );
		}
	}, [ balance.state ] );

	const title = __( 'Your payout requests', 'merchboard' );
	const payouts =
		list.state.status === 'success' ? list.state.data.items : [];

	return (
		<>
			{ balance.state.status === 'loading' && shown === null && (
				<Skeleton
					label={ __( 'Loading your balance…', 'merchboard' ) }
					lines={ 3 }
				/>
			) }
			{ balance.state.status === 'error' && (
				<ApiErrorState
					error={ balance.state.error }
					onRetry={ balance.retry }
				/>
			) }
			{ balance.state.status !== 'error' && shown !== null && (
				<>
					<BalanceCard balance={ shown } locale={ config.locale } />
					<PayoutRequestForm
						balance={ shown }
						locale={ config.locale }
						onRequested={ reload }
					/>
				</>
			) }

			{ list.state.status === 'error' ? (
				<ApiErrorState
					error={ list.state.error }
					onRetry={ list.retry }
				/>
			) : (
				<Card title={ title }>
					{ notice !== null && (
						<Alert tone={ notice.tone }>{ notice.text }</Alert>
					) }
					<Table
						caption={ title }
						captionHidden
						columns={ columns(
							config.locale,
							onCancelled,
							onRefused
						) }
						rows={ payouts }
						rowKey={ ( payout ) => payout.id }
						loading={ list.state.status === 'loading' }
						loadingLabel={ __(
							'Loading payout requests…',
							'merchboard'
						) }
						empty={
							list.page > 1 ? (
								<EmptyState
									title={ __(
										'There are no payout requests on this page.',
										'merchboard'
									) }
								/>
							) : (
								<EmptyState
									title={ __(
										'You have no payout requests yet.',
										'merchboard'
									) }
								/>
							)
						}
					/>
					<Pagination
						page={ list.page }
						totalPages={
							list.state.status === 'success'
								? list.state.data.totalPages
								: knownPages
						}
						onChange={ list.setPage }
						label={ __( 'Payout request pages', 'merchboard' ) }
						disabled={ list.state.status === 'loading' }
					/>
				</Card>
			) }
		</>
	);
}
