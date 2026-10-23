import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/** One message of the stack. */
export interface StackedToast {
	readonly id: number;
	readonly text: string;
}

/** Most messages shown at once; older ones go first. */
const MAX = 3;

/**
 * Short confirmations of a page section, stacked above each other in one
 * live region, so they never overlap. The same message again (e.g. "Settings
 * saved." after each automatic save) is not shown twice: it moves to the
 * bottom and its time starts again.
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

		setToasts( ( current ) =>
			[
				...current.filter( ( toast ) => toast.text !== text ),
				{ id, text },
			].slice( -MAX )
		);
	}, [] );

	const dismiss = useCallback(
		( id: number ) =>
			setToasts( ( current ) =>
				current.filter( ( toast ) => toast.id !== id )
			),
		[]
	);

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
		const timer = window.setTimeout(
			() => onDismiss( toast.id ),
			duration
		);

		return () => window.clearTimeout( timer );
	}, [ toast.id, onDismiss, duration ] );

	return (
		<div className="merchboard-toast">
			<span className="merchboard-toast__message">{ toast.text }</span>
			<button
				type="button"
				className="merchboard-toast__dismiss"
				onClick={ () => onDismiss( toast.id ) }
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
