import { __ } from '@wordpress/i18n';

import { useConfig } from '../app/config-context';
import { moneyText } from '../sections/sales/format';
import type { Profit } from '../types/profit';

export interface ProfitValueProps {
	/** The profit, or null when there is nothing to show (`emptyText`). */
	readonly profit: Profit | null;
	/** Text for a missing profit, e.g. "No sales". */
	readonly emptyText?: string;
}

/**
 * One profit (ADR-050) as text: a complete profit as money in the site's
 * locale (also 0 or negative), an incomplete or unknown profit as a text
 * badge without any amount, so missing history never looks like 0 kr.
 */
export function ProfitValue( { profit, emptyText }: ProfitValueProps ) {
	const config = useConfig();

	if ( profit === null ) {
		return (
			<span className="merchboard-profit merchboard-profit--none">
				{ emptyText ?? __( 'Not available', 'merchboard' ) }
			</span>
		);
	}

	if ( profit.status === 'complete' && profit.amount !== null ) {
		return (
			<span className="merchboard-profit">
				{ moneyText( profit.amount, profit.currency, config.locale ) }
			</span>
		);
	}

	return (
		<span className="merchboard-profit merchboard-badge">
			{ profit.status === 'incomplete'
				? __( 'Incomplete', 'merchboard' )
				: __( 'Unknown', 'merchboard' ) }
		</span>
	);
}

/**
 * Explanation of the profit badges, shown under a table or figure.
 */
export function ProfitLegend() {
	return (
		<ul className="merchboard-profit__legend">
			<li>
				{ __(
					'Profit is your share of what customers paid, including VAT, with the markup that applied when each order line was created. It is not your payout.',
					'merchboard'
				) }
			</li>
			<li>
				{ __(
					'Incomplete: some sales lack the historical data needed to calculate profit, so no total is shown.',
					'merchboard'
				) }
			</li>
			<li>
				{ __(
					'Unknown: the sales lack the historical data needed to calculate profit.',
					'merchboard'
				) }
			</li>
		</ul>
	);
}
