import { __, sprintf } from '@wordpress/i18n';

import type { SalesInterval, SalesPoint } from '../../types/sales';
import { chartGeometry, HEIGHT } from './chart-geometry';
import { moneyText, periodText } from './format';

/**
 * The chart's caption for an interval.
 *
 * @param interval Interval of the series.
 */
function captionText( interval: SalesInterval ): string {
	switch ( interval ) {
		case 'week':
			return __( 'Net sales per week', 'merchboard' );
		case 'month':
			return __( 'Net sales per month', 'merchboard' );
		default:
			return __( 'Net sales per day', 'merchboard' );
	}
}

export interface SalesChartProps {
	readonly series: readonly SalesPoint[];
	readonly currency: string;
	readonly interval: SalesInterval;
	readonly locale: string;
}

/**
 * Net sales per period as columns from a zero baseline (ADR-041: own SVG, no
 * chart library). Columns above the baseline are positive, below negative, so
 * the sign is shown by position, not by color alone. The SVG is decorative
 * (`aria-hidden`): the table next to it carries the same figures for
 * everyone. Each column has a native tooltip (`<title>`) for pointer users.
 * The SVG stretches to the container's width (`preserveAspectRatio="none"`)
 * and the axis labels are HTML, so text stays legible at every width.
 */
export function SalesChart( {
	series,
	currency,
	interval,
	locale,
}: SalesChartProps ) {
	const geometry = chartGeometry( series.map( ( point ) => point.netSales ) );
	const first = series[ 0 ];
	const last = series[ series.length - 1 ];
	const max =
		geometry.maxIndex === null ? undefined : series[ geometry.maxIndex ];
	const min =
		geometry.minIndex === null ? undefined : series[ geometry.minIndex ];
	const baseline = ( geometry.baseline / HEIGHT ) * 100;
	// The zero label only where it does not collide with the top or bottom label.
	const showZero =
		( max === undefined || baseline > 15 ) &&
		( min === undefined || baseline < 85 );

	return (
		<figure className="merchboard-sales-chart">
			<figcaption className="merchboard-sales-chart__caption">
				{ captionText( interval ) }
				<span className="merchboard-visually-hidden">
					{ ' ' }
					{ __(
						'(the table below has the same figures)',
						'merchboard'
					) }
				</span>
			</figcaption>
			<div className="merchboard-sales-chart__body">
				<div
					className="merchboard-sales-chart__axis"
					aria-hidden="true"
				>
					{ max !== undefined && (
						<span
							className="merchboard-sales-chart__tick merchboard-sales-chart__tick--top"
							data-tick="max"
						>
							{ moneyText( max.netSales, currency, locale ) }
						</span>
					) }
					{ showZero && (
						<span
							className="merchboard-sales-chart__tick"
							data-tick="zero"
							style={ { top: `${ baseline }%` } }
						>
							{ moneyText( '0.0000', currency, locale ) }
						</span>
					) }
					{ min !== undefined && (
						<span
							className="merchboard-sales-chart__tick merchboard-sales-chart__tick--bottom"
							data-tick="min"
						>
							{ moneyText( min.netSales, currency, locale ) }
						</span>
					) }
				</div>
				<svg
					className="merchboard-sales-chart__svg"
					viewBox={ `0 0 ${ geometry.width } ${ geometry.height }` }
					preserveAspectRatio="none"
					aria-hidden="true"
					focusable="false"
				>
					<line
						className="merchboard-sales-chart__baseline"
						x1={ 0 }
						x2={ geometry.width }
						y1={ geometry.baseline }
						y2={ geometry.baseline }
						vectorEffect="non-scaling-stroke"
					/>
					{ geometry.columns.map( ( column ) => {
						const point = series[ column.index ];

						return point === undefined ? null : (
							<rect
								key={ point.start }
								className={
									column.negative
										? 'merchboard-sales-chart__column merchboard-sales-chart__column--negative'
										: 'merchboard-sales-chart__column'
								}
								x={ column.x }
								y={ column.y }
								width={ column.width }
								height={ column.height }
							>
								<title>
									{ sprintf(
										/* translators: 1: period, 2: net sales with currency. */
										__( '%1$s: %2$s', 'merchboard' ),
										periodText(
											point.start,
											point.end,
											locale
										),
										moneyText(
											point.netSales,
											currency,
											locale
										)
									) }
								</title>
							</rect>
						);
					} ) }
				</svg>
				{ first !== undefined && last !== undefined && (
					<div
						className="merchboard-sales-chart__dates"
						aria-hidden="true"
					>
						<span>
							{ periodText( first.start, first.end, locale ) }
						</span>
						{ series.length > 1 && (
							<span>
								{ periodText( last.start, last.end, locale ) }
							</span>
						) }
					</div>
				) }
			</div>
		</figure>
	);
}
