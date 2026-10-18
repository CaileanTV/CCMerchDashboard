import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getDiscountCode } from '../../api/discountCode';
import type { ApiError } from '../../api/client';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';

type State =
	| { readonly status: 'hidden' }
	| { readonly status: 'loading' }
	| { readonly status: 'shown'; readonly code: string | null }
	| { readonly status: 'error'; readonly error: ApiError };

/**
 * The creator's own discount code (ADR-068), hidden until the creator
 * chooses to show it: only then is it requested from the server, and
 * "Hide" removes it from the page again. Orders that use the code do not
 * count in the creator's sales and profit; the card says so.
 */
export function DiscountCodeCard() {
	const [ state, setState ] = useState< State >( { status: 'hidden' } );

	const show = async () => {
		setState( { status: 'loading' } );
		const result = await getDiscountCode();

		setState(
			result.ok
				? { status: 'shown', code: result.data.code }
				: { status: 'error', error: result.error }
		);
	};

	const hide = () => setState( { status: 'hidden' } );

	return (
		<Card title={ __( 'Your discount code', 'merchboard' ) }>
			<p>
				{ __(
					'With your own discount code you can buy your own merchandise without paying for your profit. Orders bought with your own discount code do not count in your sales and your profit.',
					'merchboard'
				) }
			</p>

			{ state.status === 'error' && (
				<ApiErrorState error={ state.error } onRetry={ show } />
			) }

			{ state.status === 'shown' &&
				( state.code === null ? (
					<p>
						{ __( 'You have no discount code yet.', 'merchboard' ) }
					</p>
				) : (
					<p className="merchboard-discount-code">
						<span className="merchboard-discount-code__label">
							{ __( 'Code:', 'merchboard' ) }
						</span>{ ' ' }
						<code>{ state.code }</code>
					</p>
				) ) }

			{ state.status === 'shown' ? (
				<Button variant="secondary" onClick={ hide }>
					{ __( 'Hide discount code', 'merchboard' ) }
				</Button>
			) : (
				state.status !== 'error' && (
					<Button
						variant="secondary"
						onClick={ show }
						isBusy={ state.status === 'loading' }
					>
						{ __( 'Show discount code', 'merchboard' ) }
					</Button>
				)
			) }
		</Card>
	);
}
