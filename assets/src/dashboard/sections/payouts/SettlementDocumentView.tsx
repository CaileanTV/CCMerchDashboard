import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	downloadSettlementPdf,
	getSettlementDocument,
} from '../../api/payouts';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import type {
	Payout,
	SettlementCorrectionAmounts,
	SettlementCorrectionValues,
	SettlementDocument,
} from '../../types/payouts';
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

function percentText( rateBps: number, locale: string ): string {
	try {
		return new Intl.NumberFormat( locale.replace( '_', '-' ), {
			maximumFractionDigits: 2,
		} ).format( rateBps / 100 );
	} catch {
		return String( rateBps / 100 );
	}
}

/**
 * A decimal amount string with the opposite sign ("0.0000" stays as it is).
 *
 * @param amount Amount, e.g. "28.4100".
 */
function negated( amount: string ): string {
	if ( /^-?0\.0000$/.test( amount ) ) {
		return '0.0000';
	}

	return amount.startsWith( '-' ) ? amount.slice( 1 ) : `-${ amount }`;
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
 * The amounts of a correction document (rettelsesbilag, ADR-062 K10 and
 * K15) as issued: before, corrected and the difference, with the VAT split
 * when VAT applies, and its PDF. Nothing is recalculated.
 *
 * @param props             Props.
 * @param props.amounts     The correction's amounts.
 * @param props.money       Formats an amount with the document's currency.
 * @param props.locale      Locale.
 * @param props.downloading Whether a PDF is being downloaded.
 * @param props.onDownload  Downloads the correction's PDF.
 */
function CorrectionAmountsView( {
	amounts,
	money,
	locale,
	downloading,
	onDownload,
}: {
	readonly amounts: SettlementCorrectionAmounts;
	readonly money: ( amount: string ) => string;
	readonly locale: string;
	readonly downloading: boolean;
	readonly onDownload: () => void;
} ) {
	const zero = ( amount: string ) => /^-?0\.0000$/.test( amount );
	const lines: [ keyof SettlementCorrectionValues, string ][] = [
		[ 'commission', __( 'Commission in the period', 'merchboard' ) ],
	];

	if ( ! zero( amounts.before.incl ) || ! zero( amounts.after.incl ) ) {
		lines.push(
			[ 'excl', __( 'Commission excl. VAT', 'merchboard' ) ],
			[
				'vat',
				sprintf(
					/* translators: %s: VAT rate in percent, e.g. 25. */
					__( 'VAT (%s %%)', 'merchboard' ),
					percentText( amounts.rateBps, locale )
				),
			],
			[ 'incl', __( 'Commission incl. VAT', 'merchboard' ) ]
		);
	}

	if ( ! zero( amounts.before.untaxed ) || ! zero( amounts.after.untaxed ) ) {
		lines.push( [
			'untaxed',
			__(
				'Commission without VAT (not VAT registered when sold)',
				'merchboard'
			),
		] );
	}

	return (
		<>
			<table className="merchboard-table merchboard-settlement__correction-amounts">
				<thead>
					<tr>
						<th scope="col">{ __( 'Amount', 'merchboard' ) }</th>
						<th scope="col">{ __( 'Before', 'merchboard' ) }</th>
						<th scope="col">{ __( 'Corrected', 'merchboard' ) }</th>
						<th scope="col">
							{ __( 'Difference', 'merchboard' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ lines.map( ( [ key, label ] ) => (
						<tr key={ key }>
							<th scope="row">{ label }</th>
							<td>{ money( amounts.before[ key ] ) }</td>
							<td>{ money( amounts.after[ key ] ) }</td>
							<td>{ money( amounts.difference[ key ] ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
			<p className="merchboard-payouts__reason">
				{ __(
					'The correction document does not change the original document or the completed payout. The difference is not settled automatically.',
					'merchboard'
				) }{ ' ' }
				<Button
					variant="secondary"
					size="sm"
					isBusy={ downloading }
					onClick={ onDownload }
				>
					{ __( 'Download PDF', 'merchboard' ) }
				</Button>
			</p>
		</>
	);
}

/**
 * Saves a file in the browser under a name. The link is in the document
 * while it is clicked, and the blob URL is revoked only after the browser
 * has had time to start the download.
 *
 * @param file File.
 * @param name File name.
 */
function saveFile( file: Blob, name: string ): void {
	const url = window.URL.createObjectURL( file );
	const link = window.document.createElement( 'a' );
	link.href = url;
	link.download = name;
	link.hidden = true;
	window.document.body.appendChild( link );
	link.click();
	link.remove();
	window.setTimeout( () => window.URL.revokeObjectURL( url ), 1000 );
}

/**
 * The settlement document of a completed payout (ADR-060), loaded only when
 * the creator asks for it and shown as issued; nothing is recalculated.
 * Documents of template version 1 keep their preliminary mark (060-9); later
 * ones carry the note on tax and reporting. The PDF is made by the server
 * from the stored document; the page can also be printed.
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
	const [ downloading, setDownloading ] = useState( false );
	const [ downloadFailed, setDownloadFailed ] = useState( false );

	if ( payout.settlementDocument === null ) {
		return null;
	}

	const download = async ( number: string, correction = '' ) => {
		setDownloading( true );
		setDownloadFailed( false );
		const result = await downloadSettlementPdf( payout.id, correction );
		setDownloading( false );

		if ( result.ok ) {
			saveFile( result.data, `${ number }.pdf` );
			return;
		}

		setDownloadFailed( true );
	};

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
	const master = document.masterData;
	const known = ( value: string ) =>
		value === '' ? __( 'Not registered', 'merchboard' ) : value;
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
			{ document.preliminary && (
				<Alert tone="warning">
					{ __(
						'Preliminary, not approved by an auditor or SKAT.',
						'merchboard'
					) }
				</Alert>
			) }
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
					master !== null && master.creatorLegalName !== ''
						? master.creatorLegalName
						: document.creatorName
				) }
			</p>
			{ document.companyAddress.length > 0 && (
				<p className="merchboard-settlement__address">
					{ document.companyAddress.join( ', ' ) }
				</p>
			) }
			<dl className="merchboard-payouts__gift-card-facts">
				{ master !== null && (
					<>
						<dt>{ __( 'Issuer CVR no.', 'merchboard' ) }</dt>
						<dd>{ known( master.companyCvr ) }</dd>
						<dt>{ __( 'Legal name', 'merchboard' ) }</dt>
						<dd>{ known( master.creatorLegalName ) }</dd>
						<dt>{ __( 'Address', 'merchboard' ) }</dt>
						<dd>{ known( master.creatorAddress.join( ', ' ) ) }</dd>
						<dt>{ __( 'CVR/SE no.', 'merchboard' ) }</dt>
						<dd>{ known( master.creatorCvr ) }</dd>
					</>
				) }
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
				{ document.commissionVat !== null && (
					<>
						<dt>{ __( 'Commission excl. VAT', 'merchboard' ) }</dt>
						<dd>{ money( document.commissionVat.excl ) }</dd>
						<dt>
							{ sprintf(
								/* translators: %s: VAT rate in percent, e.g. 25. */
								__( 'VAT (%s %%)', 'merchboard' ),
								percentText(
									document.commissionVat.rateBps,
									locale
								)
							) }
						</dt>
						<dd>{ money( document.commissionVat.vat ) }</dd>
						<dt>{ __( 'Commission incl. VAT', 'merchboard' ) }</dt>
						<dd>{ money( document.commissionVat.incl ) }</dd>
						{ document.commissionVat.untaxed !== '0.0000' && (
							<>
								<dt>
									{ __(
										'Commission without VAT (not VAT registered when sold)',
										'merchboard'
									) }
								</dt>
								<dd>
									{ money( document.commissionVat.untaxed ) }
								</dd>
							</>
						) }
					</>
				) }
				{ document.vatDeduction !== null && (
					<>
						<dt>
							{ __(
								'Commission of sales while not VAT registered, incl. VAT',
								'merchboard'
							) }
						</dt>
						<dd>{ money( document.vatDeduction.gross ) }</dd>
						<dt>
							{ __(
								'VAT deducted (not VAT registered when sold)',
								'merchboard'
							) }
						</dt>
						<dd>
							{ money(
								negated( document.vatDeduction.deduction )
							) }
						</dd>
						<dt>
							{ __(
								'Commission of sales while not VAT registered, paid out',
								'merchboard'
							) }
						</dt>
						<dd>{ money( document.vatDeduction.net ) }</dd>
					</>
				) }
				{ document.adjustment !== '0.0000' && (
					<>
						<dt>
							{ sprintf(
								/* translators: %s: references of the recalculations, e.g. R2-000001. */
								__( 'Adjustments (%s)', 'merchboard' ),
								document.adjustmentReferences.join( ', ' )
							) }
						</dt>
						<dd>{ money( document.adjustment ) }</dd>
					</>
				) }
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
						<th scope="col">
							{ __( 'VAT on the sale', 'merchboard' ) }
						</th>
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
				<div
					key={ correction.number }
					className="merchboard-settlement__correction"
				>
					<p>
						{ sprintf(
							/* translators: 1: number of the correction document, 2: date, 3: reason. */
							__(
								'Corrected by %1$s (%2$s): %3$s',
								'merchboard'
							),
							correction.number,
							dateText( correction.issuedAt, locale ),
							correction.reason
						) }
					</p>
					{ correction.amounts !== null && (
						<CorrectionAmountsView
							amounts={ correction.amounts }
							money={ money }
							locale={ locale }
							downloading={ downloading }
							onDownload={ () =>
								download(
									correction.number,
									correction.amounts?.suffix ?? ''
								)
							}
						/>
					) }
					{ correction.masterData?.changed === true && (
						<dl className="merchboard-payouts__gift-card-facts merchboard-settlement__correction-master-data">
							<dt>{ __( 'Legal name', 'merchboard' ) }</dt>
							<dd>
								{ known(
									correction.masterData.creatorLegalName
								) }
							</dd>
							<dt>{ __( 'Address', 'merchboard' ) }</dt>
							<dd>
								{ known(
									correction.masterData.creatorAddress.join(
										', '
									)
								) }
							</dd>
							<dt>{ __( 'CVR/SE no.', 'merchboard' ) }</dt>
							<dd>
								{ known( correction.masterData.creatorCvr ) }
							</dd>
							<dt>{ __( 'Issued by', 'merchboard' ) }</dt>
							<dd>
								{ known( correction.masterData.companyName ) }
							</dd>
							<dt>{ __( 'Issuer address', 'merchboard' ) }</dt>
							<dd>
								{ known(
									correction.masterData.companyAddress.join(
										', '
									)
								) }
							</dd>
							<dt>{ __( 'Issuer CVR no.', 'merchboard' ) }</dt>
							<dd>
								{ known( correction.masterData.companyCvr ) }
							</dd>
						</dl>
					) }
				</div>
			) ) }
			{ ! document.preliminary && (
				<section className="merchboard-settlement__tax-note">
					<h4>{ __( 'Tax and reporting', 'merchboard' ) }</h4>
					<p>
						{ __(
							'You are responsible for finding out about and meeting your own tax and reporting obligations to the Danish Tax Agency. Depending on your situation, this may include whether the amount must be included in your taxable income and whether you have VAT obligations. This document shows the payout and its basis according to the information registered in the system. It is not tax advice and has not been approved by an auditor or the Danish Tax Agency.',
							'merchboard'
						) }
					</p>
				</section>
			) }
			<p className="merchboard-settlement__actions">
				<Button
					variant="primary"
					size="sm"
					isBusy={ downloading }
					onClick={ () => download( document.number ) }
				>
					{ __( 'Download PDF', 'merchboard' ) }
				</Button>{ ' ' }
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
				{ downloadFailed && (
					<span className="merchboard-payouts__reason" role="status">
						{ ' ' }
						{ __(
							'The PDF cannot be downloaded right now. Please try again later.',
							'merchboard'
						) }
					</span>
				) }
			</p>
		</article>
	);
}
