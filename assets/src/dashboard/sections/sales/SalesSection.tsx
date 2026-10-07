import { useCallback, useEffect, useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { getSales, SALES_INTERVALS } from '../../api/sales';
import { useConfig } from '../../app/config-context';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { EmptyState } from '../../components/EmptyState';
import { Field } from '../../components/Field';
import { Skeleton } from '../../components/Skeleton';
import { Table } from '../../components/Table';
import type { TableColumn } from '../../components/Table';
import { useApi } from '../../hooks/useApi';
import type {
	SalesCurrencyReport,
	SalesInterval,
	SalesPoint,
	SalesQuery,
	SalesReport,
} from '../../types/sales';
import { SalesChart } from './SalesChart';
import { SalesFigures } from './SalesFigures';
import { countText, dayText, moneyText, periodText } from './format';
import {
	netSalesExplanation,
	notAvailableText,
	perCurrencyNote,
} from './texts';

/**
 * Label of an interval.
 *
 * @param interval Interval.
 */
function intervalLabel( interval: SalesInterval ): string {
	switch ( interval ) {
		case 'week':
			return __( 'Week', 'merchboard' );
		case 'month':
			return __( 'Month', 'merchboard' );
		default:
			return __( 'Day', 'merchboard' );
	}
}

interface Draft {
	readonly from: string;
	readonly to: string;
	readonly interval: SalesInterval;
}

function columns(
	currency: string,
	locale: string
): readonly TableColumn< SalesPoint >[] {
	return [
		{
			key: 'period',
			header: __( 'Period', 'merchboard' ),
			render: ( point ) => periodText( point.start, point.end, locale ),
		},
		{
			key: 'net-sales',
			header: __( 'Net sales (excl. VAT)', 'merchboard' ),
			render: ( point ) => moneyText( point.netSales, currency, locale ),
			align: 'end',
		},
		{
			key: 'units',
			header: __( 'Units', 'merchboard' ),
			render: ( point ) => countText( point.units, locale ),
			align: 'end',
		},
		{
			key: 'orders',
			header: __( 'Orders', 'merchboard' ),
			render: ( point ) => countText( point.orders, locale ),
			align: 'end',
		},
	];
}

/**
 * One currency: its totals, the chart and the table with the same series.
 *
 * @param props
 * @param props.entry   The currency's report.
 * @param props.report  The whole report (period and interval).
 * @param props.locale  WordPress locale.
 * @param props.several Whether the report has more than one currency.
 */
function CurrencyBlock( {
	entry,
	report,
	locale,
	several,
}: {
	readonly entry: SalesCurrencyReport;
	readonly report: SalesReport;
	readonly locale: string;
	readonly several: boolean;
} ) {
	return (
		<Card
			title={
				several ? entry.currency : __( 'Your sales', 'merchboard' )
			}
		>
			<SalesFigures entry={ entry } locale={ locale } labelLevel="h3" />
			<SalesChart
				series={ entry.series }
				currency={ entry.currency }
				interval={ report.interval }
				locale={ locale }
			/>
			<Table
				caption={ sprintf(
					/* translators: %s: currency code (e.g. DKK). */
					__(
						'Net sales, units and orders per period (%s)',
						'merchboard'
					),
					entry.currency
				) }
				columns={ columns( entry.currency, locale ) }
				rows={ entry.series }
				rowKey={ ( point ) => point.start }
				empty={ null }
			/>
		</Card>
	);
}

function Report( {
	report,
	locale,
}: {
	readonly report: SalesReport;
	readonly locale: string;
} ) {
	const several = report.currencies.length > 1;

	return (
		<div className="merchboard-sales__report">
			<p className="merchboard-sales__period">
				{ sprintf(
					/* translators: 1: first day, 2: last day (both included), 3: interval (Day, Week or Month). */
					__( 'Period: %1$s – %2$s · Interval: %3$s', 'merchboard' ),
					dayText( report.from, locale ),
					dayText( report.to, locale ),
					intervalLabel( report.interval )
				) }
			</p>
			<p className="merchboard-sales__explanation">
				{ netSalesExplanation() }
			</p>
			{ report.currencies.length === 0 && (
				<EmptyState
					title={ __( 'No net sales in this period.', 'merchboard' ) }
					description={ __(
						'Choose another period, or check back after your next sale.',
						'merchboard'
					) }
				/>
			) }
			{ several && (
				<p className="merchboard-sales__explanation">
					{ perCurrencyNote() }
				</p>
			) }
			{ report.currencies.map( ( entry ) => (
				<CurrencyBlock
					key={ entry.currency }
					entry={ entry }
					report={ report }
					locale={ locale }
					several={ several }
				/>
			) ) }
		</div>
	);
}

/**
 * Sales (ADR-040 pkt. 11, ADR-041): the creator's sales for a period from
 * `GET /me/sales`, per currency, with totals, an SVG column chart and a table
 * with the same series. The first request has no parameters (the server's
 * default period: the last 30 days by day); the form then sends `from`, `to`
 * and `interval`. Changing the interval keeps the chosen dates. Nothing is
 * computed here: every figure comes from the response.
 */
export function SalesSection() {
	const config = useConfig();
	const headingId = useId();
	const [ query, setQuery ] = useState< SalesQuery >( {} );
	const [ draft, setDraft ] = useState< Draft >( {
		from: '',
		to: '',
		interval: 'day',
	} );
	const [ rangeError, setRangeError ] = useState< string | null >( null );
	const load = useCallback(
		( signal: AbortSignal ) => getSales( query, signal ),
		[ query ]
	);
	const { state, retry } = useApi( load );

	// The form shows the period the server applied (defaults included).
	useEffect( () => {
		if ( state.status === 'success' ) {
			setDraft( {
				from: state.data.from,
				to: state.data.to,
				interval: state.data.interval,
			} );
		}
	}, [ state ] );

	function onSubmit( event: FormEvent< HTMLFormElement > ) {
		event.preventDefault();

		if ( draft.from !== '' && draft.to !== '' && draft.from > draft.to ) {
			setRangeError(
				__(
					'The end date must be on or after the start date.',
					'merchboard'
				)
			);
			return;
		}

		setRangeError( null );
		setQuery( {
			...( draft.from === '' ? {} : { from: draft.from } ),
			...( draft.to === '' ? {} : { to: draft.to } ),
			interval: draft.interval,
		} );
	}

	let content;

	if ( state.status === 'loading' ) {
		content = (
			<Skeleton
				label={ __( 'Loading sales…', 'merchboard' ) }
				lines={ 4 }
			/>
		);
	} else if ( state.status === 'success' ) {
		content = <Report report={ state.data } locale={ config.locale } />;
	} else if (
		state.error.kind === 'not_found' &&
		state.error.code === 'rest_no_route'
	) {
		content = <EmptyState title={ notAvailableText() } />;
	} else if ( state.error.kind === 'invalid_request' ) {
		content = (
			<Alert tone="warning">
				{ __(
					'This period cannot be shown. Choose at most 366 days, weeks or months and at most five years.',
					'merchboard'
				) }
			</Alert>
		);
	} else {
		content = <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	return (
		<div className="merchboard-sales">
			<form
				className="merchboard-sales__filters"
				onSubmit={ onSubmit }
				aria-labelledby={ headingId }
				noValidate
			>
				<h2 id={ headingId } className="merchboard-visually-hidden">
					{ __( 'Choose period', 'merchboard' ) }
				</h2>
				<Field label={ __( 'From', 'merchboard' ) }>
					{ ( control ) => (
						<input
							{ ...control }
							className="merchboard-input"
							type="date"
							name="from"
							value={ draft.from }
							onChange={ ( event ) =>
								setDraft( {
									...draft,
									from: event.target.value,
								} )
							}
						/>
					) }
				</Field>
				<Field
					label={ __( 'To', 'merchboard' ) }
					{ ...( rangeError === null ? {} : { error: rangeError } ) }
				>
					{ ( control ) => (
						<input
							{ ...control }
							className="merchboard-input"
							type="date"
							name="to"
							value={ draft.to }
							onChange={ ( event ) =>
								setDraft( { ...draft, to: event.target.value } )
							}
						/>
					) }
				</Field>
				<Field label={ __( 'Interval', 'merchboard' ) }>
					{ ( control ) => (
						<select
							{ ...control }
							className="merchboard-input"
							name="interval"
							value={ draft.interval }
							onChange={ ( event ) =>
								setDraft( {
									...draft,
									interval:
										SALES_INTERVALS.find(
											( interval ) =>
												interval === event.target.value
										) ?? 'day',
								} )
							}
						>
							{ SALES_INTERVALS.map( ( interval ) => (
								<option key={ interval } value={ interval }>
									{ intervalLabel( interval ) }
								</option>
							) ) }
						</select>
					) }
				</Field>
				<div className="merchboard-sales__submit">
					<Button
						type="submit"
						disabled={ state.status === 'loading' }
					>
						{ __( 'Show', 'merchboard' ) }
					</Button>
				</div>
			</form>
			{ content }
		</div>
	);
}
