/**
 * The master data at the top of the dashboard (ADR-104): shown while the
 * creator's first payout still needs master data that are missing or must be
 * corrected, with a link to Payouts where they are filled in. Hidden when the
 * route does not exist (no WooCommerce) or once they are sent.
 */
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { BILLING_DETAILS_CHANGED, getBillingDetails } from '../api/billing';
import { Link } from '../app/router';
import { useApi } from '../hooks/useApi';
import { Alert } from './Alert';

export function BillingDetailsNotice() {
	const { state, retry } = useApi( getBillingDetails );

	useEffect( () => {
		window.addEventListener( BILLING_DETAILS_CHANGED, retry );

		return () =>
			window.removeEventListener( BILLING_DETAILS_CHANGED, retry );
	}, [ retry ] );

	if ( state.status !== 'success' || ! state.data.requiredForPayout ) {
		return null;
	}

	const corrections = state.data.status === 'changes_requested';

	if ( state.data.status !== 'missing' && ! corrections ) {
		return null;
	}

	return (
		<div className="merchboard-billing-notice">
			<Alert tone="warning">
				{ corrections
					? __(
							'Please correct your master data for settlement documents. Your first payout waits until they are confirmed.',
							'merchboard'
						)
					: __(
							'Please fill in your master data for settlement documents. We need them before your first payout.',
							'merchboard'
						) }{ ' ' }
				<Link to="payouts">
					{ __( 'Go to Payouts', 'merchboard' ) }
				</Link>
			</Alert>
		</div>
	);
}
