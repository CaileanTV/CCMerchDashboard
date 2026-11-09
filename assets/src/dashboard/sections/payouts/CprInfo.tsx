import { __ } from '@wordpress/i18n';

/**
 * Why the CPR number is needed and how it is kept (ADR-104), with the
 * recommendation to register a sole proprietorship (PMV), which is free.
 */
export function CprInfo() {
	return (
		<div className="merchboard-cpr-info">
			<p>
				<strong>
					{ __( 'Why do we need your CPR number?', 'merchboard' ) }
				</strong>{ ' ' }
				{ __(
					'When you are paid as a private person without a CVR number, we must report the payout to the Danish Tax Agency (eIndkomst) under section 4 of the Tax Reporting Act (skatteindberetningsloven), and the report needs your CPR number.',
					'merchboard'
				) }
			</p>
			<p>
				<strong>{ __( 'Your data are safe:', 'merchboard' ) }</strong>{ ' ' }
				{ __(
					'Your CPR number is stored encrypted and used only to report your payouts. It is never shown on your settlement documents.',
					'merchboard'
				) }
			</p>
			<p>
				<strong>{ __( 'Our recommendation:', 'merchboard' ) }</strong>{ ' ' }
				{ __(
					'Register a sole proprietorship (personligt ejet mindre virksomhed, PMV). It is free, and you then use its CVR number instead of your CPR number.',
					'merchboard'
				) }
			</p>
		</div>
	);
}
