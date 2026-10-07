import { __ } from '@wordpress/i18n';

import type { SalesCurrencyTotals } from '../../types/sales';
import { countText, moneyText } from './format';

export interface SalesFiguresProps {
	readonly entry: SalesCurrencyTotals;
	readonly locale: string;
	/** Heading level of the labels, under the surrounding heading. */
	readonly labelLevel: 'h3' | 'h4';
}

/**
 * The key figures of one currency (ADR-040 pkt. 14): net sales (excl. VAT),
 * units and orders, exactly as the API sends them. Used by the Overview and
 * the Sales section.
 */
export function SalesFigures( {
	entry,
	locale,
	labelLevel,
}: SalesFiguresProps ) {
	const Label = labelLevel;
	const figures = [
		{
			id: 'net-sales',
			label: __( 'Net sales (excl. VAT)', 'merchboard' ),
			value: moneyText( entry.totals.netSales, entry.currency, locale ),
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

	return (
		<ul className="merchboard-kpi-grid">
			{ figures.map( ( figure ) => (
				<li
					key={ figure.id }
					className={ `merchboard-card merchboard-kpi merchboard-kpi--${ figure.id }` }
				>
					<Label className="merchboard-kpi__label">
						{ figure.label }
					</Label>
					<p className="merchboard-kpi__value">{ figure.value }</p>
				</li>
			) ) }
		</ul>
	);
}
