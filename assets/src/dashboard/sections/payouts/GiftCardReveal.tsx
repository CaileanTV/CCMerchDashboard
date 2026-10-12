import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { getPayoutGiftCard } from '../../api/payouts';
import { Button } from '../../components/Button';
import type { Payout, PayoutGiftCard } from '../../types/payouts';
import { moneyText } from '../sales/format';

function cardStatusLabel( status: PayoutGiftCard[ 'status' ] ): string {
	switch ( status ) {
		case 'active':
			return __( 'Active', 'merchboard' );
		case 'disabled':
			return __( 'Disabled', 'merchboard' );
		case 'expired':
			return __( 'Expired', 'merchboard' );
		default:
			return __( 'Not usable', 'merchboard' );
	}
}

/**
 * The gift card of a completed gift card payout (ADR-057), loaded only when
 * the creator asks for it and read live from the gift card plugin. The code
 * is masked until "Show code", lives only in this component's state (never
 * in browser storage) and is dropped with "Hide gift card".
 */
export function GiftCardReveal( {
	payout,
	locale,
}: {
	readonly payout: Payout;
	readonly locale: string;
} ) {
	const [ card, setCard ] = useState< PayoutGiftCard | null >( null );
	const [ visible, setVisible ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ message, setMessage ] = useState< string | null >( null );

	if ( ! payout.giftCard ) {
		return null;
	}

	const load = async () => {
		setBusy( true );
		setMessage( null );
		const result = await getPayoutGiftCard( payout.id );
		setBusy( false );

		if ( result.ok ) {
			setCard( result.data );
			setVisible( false );
			return;
		}

		setMessage(
			__(
				'The gift card cannot be shown right now. Please try again later.',
				'merchboard'
			)
		);
	};

	const copy = async () => {
		if ( card === null ) {
			return;
		}

		try {
			await navigator.clipboard.writeText( card.code );
			setMessage( __( 'The code was copied.', 'merchboard' ) );
		} catch {
			setMessage(
				__(
					'The code could not be copied. Show it and copy it by hand.',
					'merchboard'
				)
			);
		}
	};

	if ( card === null ) {
		return (
			<span className="merchboard-payouts__gift-card">
				<Button
					variant="secondary"
					size="sm"
					isBusy={ busy }
					onClick={ load }
				>
					{ __( 'Show gift card', 'merchboard' ) }
				</Button>
				{ message !== null && (
					<span className="merchboard-payouts__reason" role="status">
						{ ' ' }
						{ message }
					</span>
				) }
			</span>
		);
	}

	return (
		<div className="merchboard-payouts__gift-card">
			<dl className="merchboard-payouts__gift-card-facts">
				<dt>{ __( 'Code', 'merchboard' ) }</dt>
				<dd>
					<code>{ visible ? card.code : '••••••••' }</code>
				</dd>
				<dt>{ __( 'Value', 'merchboard' ) }</dt>
				<dd>{ moneyText( card.amount, card.currency, locale ) }</dd>
				<dt>{ __( 'Balance now', 'merchboard' ) }</dt>
				<dd>{ moneyText( card.balance, card.currency, locale ) }</dd>
				<dt>{ __( 'Status', 'merchboard' ) }</dt>
				<dd>{ cardStatusLabel( card.status ) }</dd>
				{ card.expiresAt !== null && (
					<>
						<dt>{ __( 'Expires', 'merchboard' ) }</dt>
						<dd>
							<time dateTime={ card.expiresAt }>
								{ card.expiresAt.slice( 0, 10 ) }
							</time>
						</dd>
					</>
				) }
			</dl>
			<span className="merchboard-payouts__confirm">
				<Button
					variant="secondary"
					size="sm"
					onClick={ () => setVisible( ! visible ) }
				>
					{ visible
						? __( 'Hide code', 'merchboard' )
						: __( 'Show code', 'merchboard' ) }
				</Button>{ ' ' }
				<Button variant="secondary" size="sm" onClick={ copy }>
					{ __( 'Copy code', 'merchboard' ) }
				</Button>{ ' ' }
				<Button
					variant="ghost"
					size="sm"
					onClick={ () => {
						setCard( null );
						setVisible( false );
						setMessage( null );
					} }
				>
					{ __( 'Hide gift card', 'merchboard' ) }
				</Button>
			</span>
			{ message !== null && (
				<p className="merchboard-payouts__reason" role="status">
					{ message }
				</p>
			) }
			<p className="merchboard-field__help">
				{ sprintf(
					/* translators: %s: payout id. */
					__(
						'Gift card of payout #%s. Anyone who has the code can use it; keep it to yourself.',
						'merchboard'
					),
					String( payout.id )
				) }
			</p>
		</div>
	);
}
