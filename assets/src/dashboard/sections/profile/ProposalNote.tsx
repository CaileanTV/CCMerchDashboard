import { __, sprintf } from '@wordpress/i18n';

import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import type { Profile, ProposalField } from '../../types/creator';

export interface ProposalNoteProps {
	readonly profile: Profile;
	readonly field: ProposalField;
	/** A readable label of the field (for the button). */
	readonly label: string;
	/** Shows the proposed text (text fields). */
	readonly showValue?: boolean;
	readonly busy: boolean;
	readonly onWithdraw: ( field: ProposalField ) => void;
}

/**
 * The state of a field's proposal (ADR-077): waiting for an administrator,
 * with "Withdraw", or rejected, with the administrator's note.
 *
 * @param props Props.
 */
export function ProposalNote( props: ProposalNoteProps ) {
	const {
		profile,
		field,
		label,
		showValue = false,
		busy,
		onWithdraw,
	} = props;
	const pending = profile.pending[ field ];
	const rejected = profile.rejected[ field ];

	if ( pending !== undefined ) {
		let text: string = __( 'Waiting for approval.', 'merchboard' );

		if ( pending.remove ) {
			text = __(
				'Waiting for approval: remove the current one.',
				'merchboard'
			);
		} else if ( showValue ) {
			text = sprintf(
				/* translators: %s: the proposed text. */
				__( 'Waiting for approval: “%s”', 'merchboard' ),
				pending.value
			);
		}

		return (
			<div className="merchboard-proposal-note">
				<Alert tone="info">{ text }</Alert>
				<Button
					variant="ghost"
					size="sm"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => onWithdraw( field ) }
					aria-label={ sprintf(
						/* translators: %s: field, e.g. "Description". */
						__( 'Withdraw the proposal for %s', 'merchboard' ),
						label
					) }
				>
					{ __( 'Withdraw', 'merchboard' ) }
				</Button>
			</div>
		);
	}

	if ( rejected !== undefined ) {
		return (
			<Alert tone="warning">
				{ rejected.reason === null || rejected.reason === ''
					? __( 'Your last proposal was not approved.', 'merchboard' )
					: sprintf(
							/* translators: %s: the administrator's note. */
							__(
								'Your last proposal was not approved: %s',
								'merchboard'
							),
							rejected.reason
						) }
			</Alert>
		);
	}

	return null;
}
