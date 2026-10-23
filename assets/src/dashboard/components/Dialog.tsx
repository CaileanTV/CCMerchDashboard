import { createPortal, useEffect, useId, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export interface DialogProps {
	/** The dialog's heading (its accessible name). */
	readonly title: string;
	/** Called when the dialog is closed (button, Escape). */
	readonly onClose: () => void;
	/** Text of the close button. */
	readonly closeLabel?: string;
	readonly children: ReactNode;
}

/**
 * A modal dialog (step 25.11): `role="dialog"` with `aria-modal`, named by
 * its heading. Focus moves into it and stays there (Tab cycles), Escape and
 * the button close it, and focus returns to where it was. The rest of the
 * page is inert while it is open.
 *
 * @param props            Props.
 * @param props.title      Heading.
 * @param props.onClose    Close handler.
 * @param props.closeLabel Close button text.
 * @param props.children   Content.
 */
export function Dialog( {
	title,
	onClose,
	closeLabel = __( 'Close', 'merchboard' ),
	children,
}: DialogProps ) {
	const headingId = useId();
	const panel = useRef< HTMLDivElement >( null );
	const close = useRef( onClose );

	close.current = onClose;

	useEffect( () => {
		const element = panel.current;

		if ( element === null ) {
			return undefined;
		}

		const doc = element.ownerDocument;
		const active = doc.activeElement;
		const returnTo = active instanceof HTMLElement ? active : null;
		const host = element.closest( '.merchboard-dialog' );
		const others = Array.from( doc.body.children ).filter(
			( child ) => child !== host && ! child.hasAttribute( 'inert' )
		);

		const onKeyDown = ( event: KeyboardEvent ) => {
			if ( event.key === 'Escape' ) {
				event.stopPropagation();
				close.current();

				return;
			}

			if ( event.key !== 'Tab' ) {
				return;
			}

			const focusable = Array.from(
				element.querySelectorAll< HTMLElement >( FOCUSABLE )
			);
			const first = focusable[ 0 ];
			const last = focusable[ focusable.length - 1 ];

			if ( first === undefined || last === undefined ) {
				event.preventDefault();

				return;
			}

			if ( event.shiftKey && doc.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && doc.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		};

		others.forEach( ( child ) => child.setAttribute( 'inert', '' ) );
		element.addEventListener( 'keydown', onKeyDown );
		element.focus();

		return () => {
			element.removeEventListener( 'keydown', onKeyDown );
			others.forEach( ( child ) => child.removeAttribute( 'inert' ) );
			returnTo?.focus();
		};
	}, [] );

	return createPortal(
		<div className="merchboard-app merchboard-dialog">
			<div className="merchboard-dialog__backdrop" />
			<div
				ref={ panel }
				className="merchboard-dialog__panel"
				role="dialog"
				aria-modal="true"
				aria-labelledby={ headingId }
				tabIndex={ -1 }
			>
				<h2 id={ headingId } className="merchboard-dialog__title">
					{ title }
				</h2>
				<div className="merchboard-dialog__content">{ children }</div>
				<div className="merchboard-dialog__actions">
					<button
						type="button"
						className="merchboard-button merchboard-button--primary merchboard-button--md"
						onClick={ onClose }
					>
						{ closeLabel }
					</button>
				</div>
			</div>
		</div>,
		document.body
	);
}
