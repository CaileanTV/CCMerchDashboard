import { __ } from '@wordpress/i18n';

import { Card } from '../../components/Card';
import { EmptyState } from '../../components/EmptyState';

/**
 * Phase 13 placeholder for the start page. Creator info and figures follow
 * with the REST API (phase 14) and the real overview (phase 15).
 */
export function OverviewSection() {
	return (
		<Card title={ __( 'Welcome', 'merchboard' ) }>
			<EmptyState
				title={ __( 'Nothing to show yet.', 'merchboard' ) }
				description={ __(
					'Your profile, products and sales will appear here as they become available.',
					'merchboard'
				) }
			/>
		</Card>
	);
}
