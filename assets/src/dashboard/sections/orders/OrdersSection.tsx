import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getOrders } from '../../api/orders';
import { useConfig } from '../../app/config-context';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Card } from '../../components/Card';
import { EmptyState } from '../../components/EmptyState';
import { Pagination } from '../../components/Pagination';
import { ProfitLegend, ProfitValue } from '../../components/ProfitValue';
import { Table } from '../../components/Table';
import type { TableColumn } from '../../components/Table';
import { usePagedApi } from '../../hooks/usePagedApi';
import type { OrderLine } from '../../types/orders';

/**
 * The date of the API (ISO 8601 UTC) in the site's language and the viewer's
 * time zone; the API value itself if the locale is not supported.
 *
 * @param iso    ISO 8601 date from the API.
 * @param locale WordPress locale, e.g. da_DK.
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
 * The two ledger markers as text badges (never colour alone). "Refund
 * recorded" does not say how much was refunded; "Not counted" does not say
 * why (ADR-039 P2).
 */
function Notes( { line }: { readonly line: OrderLine } ) {
	if ( ! line.refunded && line.counted ) {
		return (
			<span>
				<span aria-hidden="true">–</span>
				<span className="merchboard-visually-hidden">
					{ __( 'None', 'merchboard' ) }
				</span>
			</span>
		);
	}

	return (
		<span className="merchboard-orders__notes">
			{ line.refunded && (
				<span className="merchboard-badge">
					{ __( 'Refund recorded', 'merchboard' ) }
				</span>
			) }
			{ ! line.counted && (
				<span className="merchboard-badge">
					{ __( 'Not counted', 'merchboard' ) }
				</span>
			) }
		</span>
	);
}

/**
 * The line's profit (refunds, reversals and corrections included) and, when
 * the creator has more lines in the order, the order's total (ADR-050).
 */
function LineProfit( { line }: { readonly line: OrderLine } ) {
	return (
		<span className="merchboard-profit__list">
			<ProfitValue profit={ line.profit } />
			{ line.orderLines > 1 && (
				<span className="merchboard-profit__order">
					<span>{ __( 'Order total:', 'merchboard' ) } </span>
					<ProfitValue profit={ line.orderProfit } />
				</span>
			) }
		</span>
	);
}

const columns = ( locale: string ): readonly TableColumn< OrderLine >[] => [
	{
		key: 'date',
		header: __( 'Date', 'merchboard' ),
		render: ( line ) => (
			<time dateTime={ line.date }>
				{ dateText( line.date, locale ) }
			</time>
		),
	},
	{
		key: 'order',
		header: __( 'Order', 'merchboard' ),
		render: ( line ) => line.orderNumber,
	},
	{
		key: 'product',
		header: __( 'Product', 'merchboard' ),
		render: ( line ) =>
			line.productName === ''
				? __( 'Unnamed product', 'merchboard' )
				: line.productName,
	},
	{
		key: 'quantity',
		header: __( 'Quantity', 'merchboard' ),
		render: ( line ) => line.quantity,
		align: 'end',
	},
	{
		key: 'status',
		header: __( 'Status', 'merchboard' ),
		render: ( line ) => line.statusLabel,
	},
	{
		key: 'notes',
		header: __( 'Notes', 'merchboard' ),
		render: ( line ) => <Notes line={ line } />,
	},
	{
		key: 'profit',
		header: __( 'Profit', 'merchboard' ),
		render: ( line ) => <LineProfit line={ line } />,
		align: 'end',
	},
];

/**
 * Orders (ADR-039): the creator's order lines from `GET /me/orders`, read
 * only, a page at a time, newest first. The quantity is the line's ledger
 * balance as the API sends it, the status is the server's label. While a page
 * loads the pagination stays (disabled) with the last known number of pages,
 * so keyboard focus is not lost.
 */
export function OrdersSection() {
	const config = useConfig();
	const { state, page, setPage, retry } = usePagedApi( getOrders );
	const [ knownPages, setKnownPages ] = useState( 0 );

	useEffect( () => {
		if ( state.status === 'success' ) {
			setKnownPages( state.data.totalPages );
		}
	}, [ state ] );

	if ( state.status === 'error' ) {
		return <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	const title = __( 'Your order lines', 'merchboard' );
	const lines = state.status === 'success' ? state.data.items : [];

	return (
		<Card title={ title }>
			<Table
				caption={ title }
				captionHidden
				columns={ columns( config.locale ) }
				rows={ lines }
				rowKey={ ( line ) => line.lineId }
				loading={ state.status === 'loading' }
				loadingLabel={ __( 'Loading orders…', 'merchboard' ) }
				empty={
					page > 1 ? (
						<EmptyState
							title={ __(
								'There are no order lines on this page.',
								'merchboard'
							) }
						/>
					) : (
						<EmptyState
							title={ __(
								'You have no orders yet.',
								'merchboard'
							) }
							description={ __(
								'Orders with your products will appear here.',
								'merchboard'
							) }
						/>
					)
				}
			/>
			{ lines.length > 0 && (
				<ul className="merchboard-orders__legend">
					<li>
						{ __(
							'Refund recorded: at least one refund applies to this order line.',
							'merchboard'
						) }
					</li>
					<li>
						{ __(
							'Not counted: the order does not currently count as a sale.',
							'merchboard'
						) }
					</li>
				</ul>
			) }
			{ lines.length > 0 && <ProfitLegend /> }
			<Pagination
				page={ page }
				totalPages={
					state.status === 'success'
						? state.data.totalPages
						: knownPages
				}
				onChange={ setPage }
				label={ __( 'Order pages', 'merchboard' ) }
				disabled={ state.status === 'loading' }
			/>
		</Card>
	);
}
