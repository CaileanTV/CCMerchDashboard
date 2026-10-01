import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { Button } from './Button';

export interface PaginationProps {
	/** Current page, from 1. */
	readonly page: number;
	/** `X-WP-TotalPages`; 0 when there is nothing to list. */
	readonly totalPages: number;
	readonly onChange: ( page: number ) => void;
	/** Accessible name of the navigation, e.g. "Products pages". */
	readonly label?: string;
	/** Disables both buttons, e.g. while a page loads. */
	readonly disabled?: boolean;
}

/**
 * Previous/next navigation with "Page X of Y" (no row of page numbers). It is
 * hidden when there is only one page or none, unless the current page lies
 * after the last one (a list that shrank between requests): then "Previous"
 * leads back to the last page. Native buttons give keyboard support; when the
 * focused button becomes disabled (first or last page reached), focus moves
 * to the page status, which is also a polite live region.
 */
export function Pagination( {
	page,
	totalPages,
	onChange,
	label,
	disabled = false,
}: PaginationProps ) {
	const status = useRef< HTMLParagraphElement >( null );
	const nav = useRef< HTMLElement >( null );
	const hasPrevious = page > 1;
	const hasNext = page < totalPages;

	useEffect( () => {
		const active = nav.current?.ownerDocument.activeElement;

		if (
			active instanceof HTMLButtonElement &&
			active.disabled &&
			nav.current?.contains( active ) === true
		) {
			status.current?.focus();
		}
	}, [ page, totalPages, disabled ] );

	if ( totalPages <= 1 && page <= 1 ) {
		return null;
	}

	return (
		<nav
			ref={ nav }
			className="merchboard-pagination"
			aria-label={ label ?? __( 'Pagination', 'merchboard' ) }
		>
			<Button
				variant="secondary"
				size="sm"
				disabled={ disabled || ! hasPrevious }
				onClick={ () =>
					onChange( Math.min( page - 1, Math.max( totalPages, 1 ) ) )
				}
			>
				{ __( 'Previous', 'merchboard' ) }
			</Button>
			<p
				ref={ status }
				className="merchboard-pagination__status"
				tabIndex={ -1 }
				aria-live="polite"
			>
				{ sprintf(
					/* translators: 1: current page number, 2: number of pages. */
					__( 'Page %1$d of %2$d', 'merchboard' ),
					page,
					totalPages
				) }
			</p>
			<Button
				variant="secondary"
				size="sm"
				disabled={ disabled || ! hasNext }
				onClick={ () => onChange( page + 1 ) }
			>
				{ __( 'Next', 'merchboard' ) }
			</Button>
		</nav>
	);
}
