import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { EmptyState } from '../components/EmptyState';
import { AlertsChatbotSection } from '../sections/alerts/AlertsChatbotSection';
import { AlertsHistorySection } from '../sections/alerts/AlertsHistorySection';
import { AlertsSection } from '../sections/alerts/AlertsSection';
import { NewsSection } from '../sections/news/NewsSection';
import { OrdersSection } from '../sections/orders/OrdersSection';
import { OverviewSection } from '../sections/overview/OverviewSection';
import { PayoutsSection } from '../sections/payouts/PayoutsSection';
import { ProductsSection } from '../sections/products/ProductsSection';
import { ProfileSection } from '../sections/profile/ProfileSection';
import { SalesSection } from '../sections/sales/SalesSection';
import type { DashboardSection } from '../types/config';
import { Link } from './router';

export interface Page {
	readonly title: string;
	readonly content: ReactNode;
}

/** Section components known to this build, by section id. */
const SECTION_COMPONENTS: Readonly< Record< string, () => ReactNode > > = {
	overview: () => <OverviewSection />,
	news: () => <NewsSection />,
	sales: () => <SalesSection />,
	products: () => <ProductsSection />,
	orders: () => <OrdersSection />,
	payouts: () => <PayoutsSection />,
	alerts: () => <AlertsSection />,
	'alerts-history': () => <AlertsHistorySection />,
	'alerts-chatbot': () => <AlertsChatbotSection />,
	profile: () => <ProfileSection />,
};

/**
 * The page for a path: a section the server allows, or "not found".
 * Unknown paths never reveal anything; the server has already checked
 * access for the whole dashboard.
 *
 * @param path     Current path.
 * @param sections Sections from the server.
 */
export function resolvePage(
	path: string,
	sections: readonly DashboardSection[]
): Page {
	const section = sections.find( ( candidate ) => candidate.path === path );

	if ( section !== undefined ) {
		const render = SECTION_COMPONENTS[ section.id ];

		return {
			title:
				section.group === undefined
					? section.label
					: sprintf(
							/* translators: 1: menu group, e.g. "Alerts", 2: page in the group. */
							__( '%1$s: %2$s', 'merchboard' ),
							section.group.label,
							section.label
						),
			content:
				render === undefined ? (
					<EmptyState
						title={ __(
							'This section is not available yet.',
							'merchboard'
						) }
					/>
				) : (
					render()
				),
		};
	}

	return {
		title: __( 'Page not found', 'merchboard' ),
		content: (
			<EmptyState
				title={ __( 'This page does not exist.', 'merchboard' ) }
				action={
					<Link
						to=""
						className="merchboard-button merchboard-button--secondary merchboard-button--md"
					>
						{ __( 'Go to the start page', 'merchboard' ) }
					</Link>
				}
			/>
		),
	};
}
