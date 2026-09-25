import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export interface ToastProps {
	/** Message; null renders the empty live region. */
	readonly message: string | null;
	readonly onDismiss: () => void;
	/** Milliseconds until the toast hides itself. */
	readonly duration?: number;
}

/**
 * A short confirmation. The live region is always in the DOM, so screen
 * readers announce the message when it appears; it can be dismissed and
 * hides itself after a while.
 */
export function Toast( { message, onDismiss, duration = 6000 }: ToastProps ) {
	useEffect( () => {
		if ( message === null ) {
			return undefined;
		}

		const timer = window.setTimeout( onDismiss, duration );

		return () => window.clearTimeout( timer );
	}, [ message, onDismiss, duration ] );

	return (
		<div
			className="merchboard-toast-region"
			role="status"
			aria-live="polite"
		>
			{ message !== null && (
				<div className="merchboard-toast">
					<span className="merchboard-toast__message">
						{ message }
					</span>
					<button
						type="button"
						className="merchboard-toast__dismiss"
						onClick={ onDismiss }
					>
						{ __( 'Dismiss', 'merchboard' ) }
					</button>
				</div>
			) }
		</div>
	);
}
