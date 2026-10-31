import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	createDiscountCode,
	getDiscountCode,
	resetDiscountCode,
} from '../../api/discountCode';
import type { ApiError } from '../../api/client';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';

type State =
	| { readonly status: 'hidden' }
	| { readonly status: 'loading' }
	| { readonly status: 'shown'; readonly code: string | null }
	| { readonly status: 'error'; readonly error: ApiError };

type Reset =
	| { readonly status: 'idle' }
	| { readonly status: 'confirming' }
	| { readonly status: 'busy' }
	| { readonly status: 'done' }
	| { readonly status: 'created' }
	| { readonly status: 'refused'; readonly reason: string }
	| { readonly status: 'error'; readonly error: ApiError };

/**
 * Fixed text for a refused reset (`data.reason`); server text is never shown.
 *
 * @param reason Reason code from the server.
 */
function refusal( reason: string ): string {
	switch ( reason ) {
		case 'reset_limit':
			return __(
				'Your discount code has already been replaced 3 times in the last 24 hours. Try again later.',
				'merchboard'
			);
		case 'code_ended':
			return __(
				'Your discount code has been ended. Contact us if you need a new one.',
				'merchboard'
			);
		case 'busy':
			return __(
				'Your discount code is being changed right now. Try again in a moment.',
				'merchboard'
			);
		default:
			return __(
				'Your discount code was not reset. Nothing was changed; try again later.',
				'merchboard'
			);
	}
}

/**
 * The creator's own discount code (ADR-068), hidden until the creator
 * chooses to show it: only then is it requested from the server, and
 * "Hide" removes it from the page again. Orders that use the code do not
 * count in the creator's sales and profit; the card says so.
 *
 * "Reset" (ADR-078) asks for a confirmation on the page, then replaces the
 * code: the old one stops working at once. It works while the code is
 * hidden, so a code shown by mistake can be replaced without showing the new
 * one; the new code is shown only when the code was already shown.
 *
 * A creator who has never had a code gets their first one from either button.
 * A code an administrator ended stays ended.
 */
export function DiscountCodeCard() {
	const [ state, setState ] = useState< State >( { status: 'hidden' } );
	const [ reset, setReset ] = useState< Reset >( { status: 'idle' } );

	const show = async () => {
		setState( { status: 'loading' } );
		const result = await getDiscountCode();

		if ( ! result.ok ) {
			setState( { status: 'error', error: result.error } );
			return;
		}

		if ( result.data.code !== null ) {
			setState( { status: 'shown', code: result.data.code } );
			return;
		}

		const first = await createDiscountCode();

		if ( first.ok ) {
			setState( { status: 'shown', code: first.data.code } );
		} else if ( first.error.reason === 'code_ended' ) {
			setState( { status: 'shown', code: null } );
		} else {
			setState( { status: 'error', error: first.error } );
		}
	};

	const hide = () => setState( { status: 'hidden' } );

	const replace = async () => {
		setReset( { status: 'busy' } );
		const replaced = await resetDiscountCode();
		const first =
			! replaced.ok && replaced.error.reason === 'no_active_code';
		const result = first ? await createDiscountCode() : replaced;

		if ( result.ok ) {
			setReset( { status: first ? 'created' : 'done' } );
			setState( ( current ) =>
				current.status === 'shown'
					? { status: 'shown', code: result.data.code }
					: current
			);
			return;
		}

		const { error } = result;

		setReset(
			error.kind === 'invalid_request' && error.reason !== undefined
				? { status: 'refused', reason: error.reason }
				: { status: 'error', error }
		);
	};

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
					<p>{ refusal( 'code_ended' ) }</p>
				) : (
					<p className="merchboard-discount-code">
						<span className="merchboard-discount-code__label">
							{ __( 'Code:', 'merchboard' ) }
						</span>{ ' ' }
						<code>{ state.code }</code>
					</p>
				) ) }

			{ reset.status === 'done' && (
				<p role="status">
					{ state.status === 'shown'
						? __(
								'Your new discount code is ready. The old code no longer works.',
								'merchboard'
							)
						: __(
								'Your new discount code is ready. The old code no longer works. Show the new code when no one else can see your screen.',
								'merchboard'
							) }
				</p>
			) }

			{ reset.status === 'created' && (
				<p role="status">
					{ state.status === 'shown'
						? __( 'Your discount code is ready.', 'merchboard' )
						: __(
								'Your discount code is ready. Show the code when no one else can see your screen.',
								'merchboard'
							) }
				</p>
			) }

			{ reset.status === 'refused' && (
				<p role="alert">{ refusal( reset.reason ) }</p>
			) }

			{ reset.status === 'error' && (
				<ApiErrorState error={ reset.error } />
			) }

			<div className="merchboard-discount-code__actions">
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

				{ reset.status !== 'confirming' && reset.status !== 'busy' && (
					<Button
						variant="ghost"
						onClick={ () => setReset( { status: 'confirming' } ) }
					>
						{ __(
							'Reset and generate a new discount code',
							'merchboard'
						) }
					</Button>
				) }
			</div>

			{ ( reset.status === 'confirming' || reset.status === 'busy' ) && (
				<div className="merchboard-discount-code__confirm">
					<span>
						{ __(
							'The old code stops working at once.',
							'merchboard'
						) }
					</span>
					<Button
						variant="danger"
						size="sm"
						isBusy={ reset.status === 'busy' }
						onClick={ () => void replace() }
					>
						{ __( 'Reset discount code', 'merchboard' ) }
					</Button>
					<Button
						variant="ghost"
						size="sm"
						disabled={ reset.status === 'busy' }
						onClick={ () => setReset( { status: 'idle' } ) }
					>
						{ __( 'Cancel', 'merchboard' ) }
					</Button>
				</div>
			) }
		</Card>
	);
}
