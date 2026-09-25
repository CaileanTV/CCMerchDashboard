import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

export type AlertTone = 'info' | 'success' | 'warning' | 'danger';

export interface AlertProps {
	readonly tone?: AlertTone;
	readonly children: ReactNode;
}

function toneLabel( tone: AlertTone ): string {
	switch ( tone ) {
		case 'success':
			return __( 'Success:', 'merchboard' );
		case 'warning':
			return __( 'Warning:', 'merchboard' );
		case 'danger':
			return __( 'Error:', 'merchboard' );
		default:
			return __( 'Note:', 'merchboard' );
	}
}

/**
 * A message box. The tone is written out as a label, so it never depends on
 * colour alone; warnings and errors are announced immediately (role "alert").
 */
export function Alert( { tone = 'info', children }: AlertProps ) {
	const urgent = tone === 'warning' || tone === 'danger';

	return (
		<div
			className={ `merchboard-alert merchboard-alert--${ tone }` }
			role={ urgent ? 'alert' : 'status' }
		>
			<strong className="merchboard-alert__label">
				{ toneLabel( tone ) }
			</strong>{ ' ' }
			{ children }
		</div>
	);
}
