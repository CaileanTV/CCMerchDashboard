import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { ProfitValue } from '../../components/ProfitValue';
import type { SalesCurrencyTotals } from '../../types/sales';
import { countText, moneyText } from './format';
import { missingVatNote, salesLabel, shownSales } from './texts';

export interface SalesFiguresProps {
	readonly entry: SalesCurrencyTotals;
	readonly locale: string;
	/** Heading level of the labels, under the surrounding heading. */
	readonly labelLevel: 'h3' | 'h4';
	/** Also show the period's profit (ADR-050); the Sales section and the Overview do. */
	readonly withProfit?: boolean;
}

/**
 * The key figures of one currency (ADR-040 pkt. 14, ADR-053): sales incl.
 * VAT (excl. VAT, labelled and explained, when a row lacks its stored VAT),
 * units and orders, exactly as the API sends them, and with `withProfit` the
 * creator's profit as a separate figure. Used by the Overview and the Sales
 * section, both with the profit.
 */
export function SalesFigures( {
	entry,
	locale,
	labelLevel,
	withProfit = false,
}: SalesFiguresProps ) {
	const Label = labelLevel;
	const figures: { id: string; label: string; value: ReactNode }[] = [
		{
			id: 'net-sales',
			label: salesLabel( entry.vatIncluded ),
			value: moneyText(
				shownSales( entry.totals, entry.vatIncluded ),
				entry.currency,
				locale
			),
		},
		{
			id: 'units',
			label: __( 'Units', 'merchboard' ),
			value: countText( entry.totals.units, locale ),
		},
		{
			id: 'orders',
			label: __( 'Orders', 'merchboard' ),
			value: countText( entry.totals.orders, locale ),
		},
	];

	if ( withProfit ) {
		// Separate from the sales figures: the creator's realized profit,
		// never an amount when incomplete or unknown.
		figures.push( {
			id: 'profit',
			label: __( 'Profit', 'merchboard' ),
			value: <ProfitValue profit={ entry.profit } />,
		} );
	}

	return (
		<>
			{ ! entry.vatIncluded && (
				<p className="merchboard-sales__vat-note">
					{ missingVatNote() }
				</p>
			) }
			<ul className="merchboard-kpi-grid">
				{ figures.map( ( figure ) => (
					<li
						key={ figure.id }
						className={ `merchboard-card merchboard-kpi merchboard-kpi--${ figure.id }` }
					>
						<Label className="merchboard-kpi__label">
							{ figure.label }
						</Label>
						<p className="merchboard-kpi__value">
							{ figure.value }
						</p>
					</li>
				) ) }
			</ul>
		</>
	);
}
