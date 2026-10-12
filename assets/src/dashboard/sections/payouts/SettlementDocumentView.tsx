import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { getSettlementDocument } from '../../api/payouts';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import type { Payout, SettlementDocument } from '../../types/payouts';
import { moneyText } from '../sales/format';
import { methodLabel } from './texts';

function dateText( iso: string, locale: string ): string {
	try {
		return new Intl.DateTimeFormat( locale.replace( '_', '-' ), {
			dateStyle: 'medium',
		} ).format( new Date( iso ) );
	} catch {
		return iso.slice( 0, 10 );
	}
}

function rowTypeLabel( type: string ): string {
	switch ( type ) {
		case 'sale':
			return __( 'Sale', 'merchboard' );
		case 'refund':
			return __( 'Refund', 'merchboard' );
		case 'reversal':
			return __( 'Reversal', 'merchboard' );
		default:
			return __( 'Correction', 'merchboard' );
	}
}

/**
 * The settlement document of a completed payout (ADR-060), loaded only when
 * the creator asks for it and shown as issued; nothing is recalculated. It
 * is always marked as preliminary (060-9) and can be printed or saved as PDF
 * with the browser.
 */
export function SettlementDocumentView( {
	payout,
	locale,
}: {
	readonly payout: Payout;
	readonly locale: string;
} ) {
	const [ document, setDocument ] = useState< SettlementDocument | null >(
		null
	);
	const [ busy, setBusy ] = useState( false );
	const [ failed, setFailed ] = useState( false );

	if ( payout.settlementDocument === null ) {
		return null;
	}

	const load = async () => {
		setBusy( true );
		setFailed( false );
		const result = await getSettlementDocument( payout.id );
		setBusy( false );

		if ( result.ok ) {
			setDocument( result.data );
			return;
		}

		setFailed( true );
	};

	if ( document === null ) {
		return (
			<span className="merchboard-payouts__document">
				<Button
					variant="secondary"
					size="sm"
					isBusy={ busy }
					onClick={ load }
				>
					{ sprintf(
						/* translators: %s: document number. */
						__( 'Settlement document %s', 'merchboard' ),
						payout.settlementDocument
					) }
				</Button>
				{ failed && (
					<span className="merchboard-payouts__reason" role="status">
						{ ' ' }
						{ __(
							'The settlement document cannot be shown right now. Please try again later.',
							'merchboard'
						) }
					</span>
				) }
			</span>
		);
	}

	const money = ( amount: string ) =>
		moneyText( amount, document.currency, locale );
	let vat: string = __( 'Unknown', 'merchboard' );

	if ( document.vatRegistered !== null ) {
		vat = document.vatRegistered
			? __( 'Yes', 'merchboard' )
			: __( 'No', 'merchboard' );
	}

	return (
		<article
			className="merchboard-settlement"
			aria-label={ sprintf(
				/* translators: %s: document number. */
				__( 'Settlement document %s', 'merchboard' ),
				document.number
			) }
		>
			<Alert tone="warning">
				{ __(
					'Preliminary, not approved by an auditor or SKAT.',
					'merchboard'
				) }
			</Alert>
			<h3 className="merchboard-settlement__title">
				{ sprintf(
					/* translators: %s: document number. */
					__( 'Settlement document %s', 'merchboard' ),
					document.number
				) }
			</h3>
			<p>
				{ sprintf(
					/* translators: 1: company name, 2: creator name. */
					__(
						'Self-billing: issued by %1$s on behalf of %2$s.',
						'merchboard'
					),
					document.companyName,
					document.creatorName
				) }
			</p>
			{ document.companyAddress.length > 0 && (
				<p className="merchboard-settlement__address">
					{ document.companyAddress.join( ', ' ) }
				</p>
			) }
			<dl className="merchboard-payouts__gift-card-facts">
				<dt>{ __( 'Issued', 'merchboard' ) }</dt>
				<dd>{ dateText( document.issuedAt, locale ) }</dd>
				<dt>{ __( 'Period', 'merchboard' ) }</dt>
				<dd>
					{ document.periodFrom === null
						? __( 'Whole history', 'merchboard' )
						: dateText( document.periodFrom, locale ) }
					{ ' – ' }
					{ dateText( document.periodTo, locale ) }
				</dd>
				<dt>{ __( 'VAT registered at issue', 'merchboard' ) }</dt>
				<dd>{ vat }</dd>
				<dt>{ __( 'Commission in the period', 'merchboard' ) }</dt>
				<dd>{ money( document.commission ) }</dd>
				<dt>{ __( 'Carried from earlier', 'merchboard' ) }</dt>
				<dd>{ money( document.openingBalance ) }</dd>
				<dt>{ __( 'Paid out', 'merchboard' ) }</dt>
				<dd>
					{ money( document.payout ) } (
					{ methodLabel( document.method ) }
					{ document.destination !== '' &&
						` ${ document.destination }` }
					)
				</dd>
				<dt>{ __( 'Carried forward', 'merchboard' ) }</dt>
				<dd>{ money( document.closingBalance ) }</dd>
			</dl>
			<table className="merchboard-table merchboard-settlement__rows">
				<caption className="merchboard-visually-hidden">
					{ __( 'Settled sales', 'merchboard' ) }
				</caption>
				<thead>
					<tr>
						<th scope="col">{ __( 'Order', 'merchboard' ) }</th>
						<th scope="col">{ __( 'Product', 'merchboard' ) }</th>
						<th scope="col">{ __( 'Type', 'merchboard' ) }</th>
						<th scope="col">{ __( 'Net', 'merchboard' ) }</th>
						<th scope="col">{ __( 'VAT', 'merchboard' ) }</th>
						<th scope="col">
							{ __( 'Commission', 'merchboard' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ document.rows.map( ( row, index ) => (
						<tr key={ `${ row.orderId }-${ index }` }>
							<td>#{ row.orderId }</td>
							<td>{ row.itemName }</td>
							<td>{ rowTypeLabel( row.type ) }</td>
							<td>{ money( row.net ) }</td>
							<td>{ money( row.tax ) }</td>
							<td>{ money( row.payout ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
			{ document.corrections.map( ( correction ) => (
				<p key={ correction.number }>
					{ sprintf(
						/* translators: 1: number of the correction document, 2: date, 3: reason. */
						__( 'Corrected by %1$s (%2$s): %3$s', 'merchboard' ),
						correction.number,
						dateText( correction.issuedAt, locale ),
						correction.reason
					) }
				</p>
			) ) }
			<p className="merchboard-settlement__actions">
				<Button
					variant="secondary"
					size="sm"
					onClick={ () => window.print() }
				>
					{ __( 'Print or save as PDF', 'merchboard' ) }
				</Button>{ ' ' }
				<Button
					variant="ghost"
					size="sm"
					onClick={ () => setDocument( null ) }
				>
					{ __( 'Close', 'merchboard' ) }
				</Button>
			</p>
		</article>
	);
}
