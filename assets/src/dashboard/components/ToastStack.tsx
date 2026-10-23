import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/** One message of the stack. */
export interface StackedToast {
	readonly id: number;
	readonly text: string;
	/** Closing: shown fading out until it is removed. */
	readonly leaving: boolean;
}

/** Most messages shown at once; a new one closes the oldest. */
const MAX = 3;

/** Milliseconds a closing message stays to fade out (matches the CSS). */
export const LEAVE_MS = 200;

/**
 * Short confirmations of a page section, stacked above each other in one
 * live region, so they never overlap. Every message is shown, also the same
 * one again (e.g. "Settings saved." after each automatic save), so each save
 * is confirmed. When MAX are shown, a new one visibly closes the oldest
 * (fades out) while the new one comes in (the project owner's wish
 * 2026-10-23), so it is clear that the newest save is confirmed too.
 *
 * @param duration Milliseconds a message is shown.
 * @return The messages, a function that shows one and one that dismisses one.
 */
export function useToastStack( duration = 6000 ): {
	readonly toasts: readonly StackedToast[];
	readonly notify: ( text: string ) => void;
	readonly dismiss: ( id: number ) => void;
	readonly duration: number;
} {
	const [ toasts, setToasts ] = useState< StackedToast[] >( [] );
	const next = useRef( 0 );

	const notify = useCallback( ( text: string ) => {
		next.current += 1;
		const id = next.current;

		setToasts( ( current ) => {
			const shown = current.filter( ( toast ) => ! toast.leaving );
			const oldest = shown.length >= MAX ? shown[ 0 ]?.id : undefined;

			return [
				...current.map( ( toast ) =>
					toast.id === oldest ? { ...toast, leaving: true } : toast
				),
				{ id, text, leaving: false },
			];
		} );
	}, [] );

	const dismiss = useCallback(
		( id: number ) =>
			setToasts( ( current ) =>
				current.map( ( toast ) =>
					toast.id === id ? { ...toast, leaving: true } : toast
				)
			),
		[]
	);

	// Closing messages are removed once they have faded out.
	useEffect( () => {
		if ( ! toasts.some( ( toast ) => toast.leaving ) ) {
			return undefined;
		}

		const timer = window.setTimeout(
			() =>
				setToasts( ( current ) =>
					current.filter( ( toast ) => ! toast.leaving )
				),
			LEAVE_MS
		);

		return () => window.clearTimeout( timer );
	}, [ toasts ] );

	return { toasts, notify, dismiss, duration };
}

function StackItem( {
	toast,
	onDismiss,
	duration,
}: {
	readonly toast: StackedToast;
	readonly onDismiss: ( id: number ) => void;
	readonly duration: number;
} ) {
	useEffect( () => {
		if ( toast.leaving ) {
			return undefined;
		}

		const timer = window.setTimeout(
			() => onDismiss( toast.id ),
			duration
		);

		return () => window.clearTimeout( timer );
	}, [ toast.id, toast.leaving, onDismiss, duration ] );

	return (
		<div
			className={
				toast.leaving
					? 'merchboard-toast merchboard-toast--leaving'
					: 'merchboard-toast'
			}
			aria-hidden={ toast.leaving ? true : undefined }
		>
			<span className="merchboard-toast__message">{ toast.text }</span>
			<button
				type="button"
				className="merchboard-toast__dismiss"
				onClick={ () => onDismiss( toast.id ) }
				disabled={ toast.leaving }
			>
				{ __( 'Dismiss', 'merchboard' ) }
			</button>
		</div>
	);
}

/**
 * The stacked messages (see useToastStack()).
 *
 * @param props           Props.
 * @param props.toasts    Messages, oldest first.
 * @param props.onDismiss Dismisses one.
 * @param props.duration  Milliseconds a message is shown.
 */
export function ToastStack( {
	toasts,
	onDismiss,
	duration = 6000,
}: {
	readonly toasts: readonly StackedToast[];
	readonly onDismiss: ( id: number ) => void;
	readonly duration?: number;
} ) {
	return (
		<div
			className="merchboard-toast-region merchboard-toast-region--stack"
			role="status"
			aria-live="polite"
		>
			{ toasts.map( ( toast ) => (
				<StackItem
					key={ toast.id }
					toast={ toast }
					onDismiss={ onDismiss }
					duration={ duration }
				/>
			) ) }
		</div>
	);
}
