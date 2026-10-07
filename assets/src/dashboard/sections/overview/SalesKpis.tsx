import { __, sprintf } from '@wordpress/i18n';

import { getDefaultSales } from '../../api/sales';
import { useConfig } from '../../app/config-context';
import { ApiErrorState } from '../../components/ApiErrorState';
import { EmptyState } from '../../components/EmptyState';
import { Skeleton } from '../../components/Skeleton';
import { useApi } from '../../hooks/useApi';
import type { SalesSummary } from '../../types/sales';
import { SalesFigures } from '../sales/SalesFigures';
import { dayText } from '../sales/format';
import {
	netSalesExplanation,
	notAvailableText,
	perCurrencyNote,
} from '../sales/texts';

function Summary( {
	summary,
	locale,
}: {
	readonly summary: SalesSummary;
	readonly locale: string;
} ) {
	const several = summary.currencies.length > 1;

	return (
		<>
			<p className="merchboard-overview__period">
				{ sprintf(
					/* translators: 1: first day, 2: last day (both included). */
					__( 'Last 30 days: %1$s – %2$s', 'merchboard' ),
					dayText( summary.from, locale ),
					dayText( summary.to, locale )
				) }
			</p>
			<p className="merchboard-overview__explanation">
				{ netSalesExplanation() }
			</p>
			{ summary.currencies.length === 0 && (
				<EmptyState
					title={ __(
						'No net sales in the last 30 days.',
						'merchboard'
					) }
					description={ __(
						'Sales of your products will appear here.',
						'merchboard'
					) }
				/>
			) }
			{ several && (
				<p className="merchboard-overview__explanation">
					{ perCurrencyNote() }
				</p>
			) }
			{ summary.currencies.map( ( entry ) =>
				several ? (
					<section
						key={ entry.currency }
						className="merchboard-kpi-currency"
						aria-labelledby={ `merchboard-kpi-${ entry.currency }` }
					>
						<h3
							id={ `merchboard-kpi-${ entry.currency }` }
							className="merchboard-kpi-currency__title"
						>
							{ entry.currency }
						</h3>
						<SalesFigures
							entry={ entry }
							locale={ locale }
							labelLevel="h4"
						/>
					</section>
				) : (
					<SalesFigures
						key={ entry.currency }
						entry={ entry }
						locale={ locale }
						labelLevel="h3"
					/>
				)
			) }
		</>
	);
}

/**
 * The Overview's key figures (ADR-040 pkt. 12, 14): net sales, units and
 * orders of the default period from `GET /me/sales`, one set per currency and
 * never summed across currencies. Without WooCommerce the route does not
 * exist (404 `rest_no_route`); that is not an error but "not available".
 */
export function SalesKpis() {
	const config = useConfig();
	const { state, retry } = useApi( getDefaultSales );

	if ( state.status === 'loading' ) {
		return (
			<Skeleton
				label={ __( 'Loading key figures…', 'merchboard' ) }
				lines={ 2 }
			/>
		);
	}

	if ( state.status === 'error' ) {
		if (
			state.error.kind === 'not_found' &&
			state.error.code === 'rest_no_route'
		) {
			return <EmptyState title={ notAvailableText() } />;
		}

		return <ApiErrorState error={ state.error } onRetry={ retry } />;
	}

	return <Summary summary={ state.data } locale={ config.locale } />;
}
