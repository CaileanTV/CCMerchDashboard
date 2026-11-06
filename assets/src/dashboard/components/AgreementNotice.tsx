/**
 * The agreement at the top of the dashboard (ADR-092): shown only to a
 * creator who signed up and has a version to accept. The creator reads the
 * text in a dialog, confirms being 18 or older and accepts. Everything else
 * in the dashboard keeps working; going live and payouts wait for it.
 */
import { useCallback, useId, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { acceptAgreement, getAgreement } from '../api/agreement';
import type { AgreementState } from '../api/agreement';
import { useApi } from '../hooks/useApi';
import { Alert } from './Alert';
import { Button } from './Button';
import { Dialog } from './Dialog';
import { SafeHtml } from './SafeHtml';

/**
 * The message of a refused acceptance.
 *
 * @param reason The server's reason.
 */
function refusal( reason: string | undefined ): string {
	switch ( reason ) {
		case 'agreement_outdated':
			return __(
				'A newer version was published meanwhile. Please read it and accept again.',
				'merchboard'
			);
		case 'impersonating':
			return __(
				'Only the creator can accept the agreement, not someone viewing the dashboard as them.',
				'merchboard'
			);
		default:
			return __(
				'The agreement could not be accepted. Please try again.',
				'merchboard'
			);
	}
}

export function AgreementNotice() {
	const { state, retry } = useApi( getAgreement );
	const checkbox = useId();
	const [ open, setOpen ] = useState( false );
	const [ adult, setAdult ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ accepted, setAccepted ] = useState< AgreementState | null >( null );

	const current =
		accepted ?? ( state.status === 'success' ? state.data : null );

	const accept = useCallback( async () => {
		if ( current === null ) {
			return;
		}

		setBusy( true );
		setError( null );
		const result = await acceptAgreement( current.version );
		setBusy( false );

		if ( result.ok ) {
			setAccepted( result.data );
			setOpen( false );

			return;
		}

		setError( refusal( result.error.reason ) );

		if ( result.error.reason === 'agreement_outdated' ) {
			setAdult( false );
			retry();
		}
	}, [ current, retry ] );

	if ( current === null || ! current.mustAccept || current.text === null ) {
		return null;
	}

	return (
		<div className="merchboard-agreement-notice">
			<Alert tone="warning">
				{ current.accepted > 0
					? __(
							'Our agreement was changed. Please read and accept the new version; until then you cannot request a payout or go live.',
							'merchboard'
						)
					: __(
							'Please read and accept our agreement. You can prepare everything meanwhile, but you can only go live and request payouts once it is accepted.',
							'merchboard'
						) }{ ' ' }
				<Button size="sm" onClick={ () => setOpen( true ) }>
					{ __( 'Read the agreement', 'merchboard' ) }
				</Button>
			</Alert>
			{ open && (
				<Dialog
					title={ __( 'Our agreement', 'merchboard' ) }
					onClose={ () => setOpen( false ) }
					actions={
						<Button
							onClick={ accept }
							isBusy={ busy }
							disabled={ ! adult }
						>
							{ __( 'Accept the agreement', 'merchboard' ) }
						</Button>
					}
				>
					<SafeHtml
						html={ current.text }
						className="merchboard-agreement-text"
					/>
					<p>
						<label htmlFor={ checkbox }>
							<input
								id={ checkbox }
								type="checkbox"
								checked={ adult }
								onChange={ ( event ) =>
									setAdult( event.currentTarget.checked )
								}
							/>{ ' ' }
							{ __(
								'I am 18 or older and accept the agreement.',
								'merchboard'
							) }
						</label>
					</p>
					{ error !== null && <Alert tone="danger">{ error }</Alert> }
				</Dialog>
			) }
		</div>
	);
}
