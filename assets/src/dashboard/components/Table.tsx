import { useId } from '@wordpress/element';
import type { ReactNode } from 'react';

import { Skeleton } from './Skeleton';

export interface TableColumn< Row > {
	/** Stable key of the column. */
	readonly key: string;
	/** Column header, plain text. */
	readonly header: string;
	/** Cell content; render values as text (never HTML). */
	readonly render: ( row: Row ) => ReactNode;
	/** Right-aligned numbers. */
	readonly align?: 'start' | 'end';
}

export interface TableProps< Row > {
	/** Table caption: the accessible name of the table and its scroll region. */
	readonly caption: string;
	/** Hides the caption visually (it stays available to screen readers). */
	readonly captionHidden?: boolean;
	readonly columns: readonly TableColumn< Row >[];
	readonly rows: readonly Row[];
	readonly rowKey: ( row: Row ) => string | number;
	/** Shown instead of the table when there are no rows. */
	readonly empty: ReactNode;
	/** Shows a loading placeholder instead of the table. */
	readonly loading?: boolean;
	/** Screen reader text of the loading placeholder. */
	readonly loadingLabel?: string;
}

/**
 * A semantic data table (`<caption>`, `<th scope="col">`) in a region that
 * scrolls horizontally on small screens; the region is focusable so keyboard
 * users can scroll it. Loading shows the Skeleton, no rows the given empty
 * state. Generic: the columns come from the section.
 */
export function Table< Row >( {
	caption,
	captionHidden = false,
	columns,
	rows,
	rowKey,
	empty,
	loading = false,
	loadingLabel,
}: TableProps< Row > ) {
	const captionId = useId();

	if ( loading ) {
		return <Skeleton label={ loadingLabel ?? caption } lines={ 4 } />;
	}

	if ( rows.length === 0 ) {
		return <>{ empty }</>;
	}

	return (
		<div
			className="merchboard-table"
			role="region"
			aria-labelledby={ captionId }
			tabIndex={ 0 }
		>
			<table className="merchboard-table__table">
				<caption
					id={ captionId }
					className={
						captionHidden
							? 'merchboard-visually-hidden'
							: 'merchboard-table__caption'
					}
				>
					{ caption }
				</caption>
				<thead>
					<tr>
						{ columns.map( ( column ) => (
							<th
								key={ column.key }
								scope="col"
								className={ cellClass( column ) }
							>
								{ column.header }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( row ) => (
						<tr key={ rowKey( row ) }>
							{ columns.map( ( column ) => (
								<td
									key={ column.key }
									className={ cellClass( column ) }
								>
									{ column.render( row ) }
								</td>
							) ) }
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

function cellClass< Row >( column: TableColumn< Row > ): string | undefined {
	return column.align === 'end' ? 'merchboard-table__cell--end' : undefined;
}
