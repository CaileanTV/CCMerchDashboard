import { useId, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { createPayout } from '../../api/payouts';
import type { ApiError } from '../../api/client';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Field } from '../../components/Field';
import { PAYOUT_METHODS } from '../../types/payouts';
import type {
	PayoutBalance,
	PayoutDetails,
	PayoutMethod,
} from '../../types/payouts';
import { moneyText } from '../sales/format';
import { methodLabel, refusalMessage } from './texts';

/**
 * A new request key: a UUID where the browser offers one (secure contexts),
 * otherwise 32 random hex characters; the server accepts both. The same key
 * is sent again when the creator retries after a failure, so a request that
 * did reach the server is never stored twice.
 */
export function newRequestKey(): string {
	if ( typeof crypto.randomUUID === 'function' ) {
		return crypto.randomUUID();
	}

	return Array.from(
		crypto.getRandomValues( new Uint8Array( 16 ) ),
		( byte ) => byte.toString( 16 ).padStart( 2, '0' )
	).join( '' );
}

interface Values {
	readonly currency: string;
	readonly all: boolean;
	readonly amount: string;
	readonly method: PayoutMethod;
	readonly registration: string;
	readonly account: string;
	readonly email: string;
}

export interface PayoutRequestFormProps {
	readonly balance: PayoutBalance;
	readonly locale: string;
	/** Called after a stored request, so balance and list are reloaded. */
	readonly onRequested: () => void;
}

/**
 * The request form. It only collects input and shows the server's answer:
 * the amount, balance, limits and details are all checked by the server
 * (ADR-055); a refusal is shown as a fixed text per reason. Payment details
 * are sent once and never kept: the fields are emptied after a stored
 * request, and nothing is written to browser storage.
 */
export function PayoutRequestForm( {
	balance,
	locale,
	onRequested,
}: PayoutRequestFormProps ) {
	const requestable = balance.currencies.filter(
		( entry ) => entry.status === 'complete'
	);
	// Bank and PayPal need a valid key; a gift card needs an available gift
	// card plugin (ADR-057). The server checks both again.
	const methods = PAYOUT_METHODS.filter( ( option ) =>
		option === 'gift_card' ? balance.giftCardUsable : balance.methodsUsable
	);
	const [ values, setValues ] = useState< Values >( {
		currency: requestable[ 0 ]?.currency ?? '',
		all: false,
		amount: '',
		method: methods[ 0 ] ?? 'bank',
		registration: '',
		account: '',
		email: '',
	} );
	const [ key, setKey ] = useState( newRequestKey );
	const [ busy, setBusy ] = useState( false );
	const [ refusal, setRefusal ] = useState< string | null >( null );
	const [ failure, setFailure ] = useState< ApiError | null >( null );
	const [ done, setDone ] = useState< string | null >( null );
	const id = useId();

	const set = ( change: Partial< Values > ) => {
		setValues( ( current ) => ( { ...current, ...change } ) );
		setDone( null );
	};

	if ( methods.length === 0 ) {
		return (
			<Card title={ __( 'Request a payout', 'merchboard' ) }>
				<Alert tone="info">
					{ __(
						'No payout method is available right now, so no payout can be requested. Please contact us.',
						'merchboard'
					) }
				</Alert>
			</Card>
		);
	}

	if ( requestable.length === 0 ) {
		return (
			<Card title={ __( 'Request a payout', 'merchboard' ) }>
				<p>
					{ __(
						'There is nothing to request yet. Your balance appears here once your sales have passed the holding period.',
						'merchboard'
					) }
				</p>
			</Card>
		);
	}

	const selected =
		requestable.find( ( entry ) => entry.currency === values.currency ) ??
		requestable[ 0 ]!;
	const method = methods.includes( values.method )
		? values.method
		: methods[ 0 ]!;
	let details: PayoutDetails = { method: 'gift_card' };

	if ( method === 'bank' ) {
		details = {
			method: 'bank',
			registration: values.registration.trim(),
			account: values.account.trim(),
		};
	} else if ( method === 'paypal' ) {
		details = { method: 'paypal', email: values.email.trim() };
	}

	const onSubmit = async ( event: FormEvent< HTMLFormElement > ) => {
		event.preventDefault();
		setRefusal( null );
		setFailure( null );
		setDone( null );
		setBusy( true );
		const result = await createPayout( {
			currency: selected.currency,
			amount: values.all
				? 'all'
				: values.amount.trim().replace( ',', '.' ),
			details,
			idempotencyKey: key,
		} );
		setBusy( false );

		if ( result.ok ) {
			setKey( newRequestKey() );
			setValues( ( current ) => ( {
				...current,
				amount: '',
				all: false,
				registration: '',
				account: '',
				email: '',
			} ) );
			setDone(
				__(
					'Your request has been sent. It is reserved until it is handled.',
					'merchboard'
				)
			);
			onRequested();
			return;
		}

		const message = refusalMessage( result.error );

		if ( message === null ) {
			// Maybe stored (e.g. a lost connection): keep the key, so a retry
			// returns the stored request instead of making a second one.
			setFailure( result.error );
		} else {
			// Refused: nothing was stored, so a changed request gets a new key.
			setKey( newRequestKey() );
			setRefusal( message );
		}
	};

	return (
		<Card title={ __( 'Request a payout', 'merchboard' ) }>
			<form className="merchboard-form" onSubmit={ onSubmit } noValidate>
				{ refusal !== null && <Alert tone="danger">{ refusal }</Alert> }
				{ failure !== null && <ApiErrorState error={ failure } /> }
				{ done !== null && <Alert tone="success">{ done }</Alert> }

				{ requestable.length > 1 && (
					<Field label={ __( 'Currency', 'merchboard' ) }>
						{ ( control ) => (
							<select
								{ ...control }
								className="merchboard-input"
								value={ selected.currency }
								onChange={ ( event ) =>
									set( { currency: event.target.value } )
								}
							>
								{ requestable.map( ( entry ) => (
									<option
										key={ entry.currency }
										value={ entry.currency }
									>
										{ entry.currency }
									</option>
								) ) }
							</select>
						) }
					</Field>
				) }

				<Field
					label={ __( 'Amount', 'merchboard' ) }
					{ ...( selected.available === null
						? {}
						: {
								help: `${ __(
									'Available:',
									'merchboard'
								) } ${ moneyText(
									selected.available,
									selected.currency,
									locale
								) }`,
							} ) }
				>
					{ ( control ) => (
						<input
							{ ...control }
							type="text"
							inputMode="decimal"
							autoComplete="off"
							className="merchboard-input"
							value={ values.all ? '' : values.amount }
							disabled={ values.all }
							required={ ! values.all }
							onChange={ ( event ) =>
								set( { amount: event.target.value } )
							}
						/>
					) }
				</Field>
				<label
					className="merchboard-payouts__check"
					htmlFor={ `${ id }-all` }
				>
					<input
						id={ `${ id }-all` }
						type="checkbox"
						checked={ values.all }
						onChange={ ( event ) =>
							set( { all: event.target.checked } )
						}
					/>{ ' ' }
					{ __( 'Request the full available amount', 'merchboard' ) }
				</label>

				<fieldset className="merchboard-payouts__methods">
					<legend className="merchboard-field__label">
						{ __( 'Payout method', 'merchboard' ) }
					</legend>
					{ methods.map( ( option ) => (
						<label
							key={ option }
							className="merchboard-payouts__check"
							htmlFor={ `${ id }-${ option }` }
						>
							<input
								id={ `${ id }-${ option }` }
								type="radio"
								name="merchboard-payout-method"
								value={ option }
								checked={ method === option }
								onChange={ () => set( { method: option } ) }
							/>{ ' ' }
							{ methodLabel( option ) }
						</label>
					) ) }
				</fieldset>

				{ method === 'bank' && (
					<>
						<Field
							label={ __( 'Registration number', 'merchboard' ) }
							help={ __( '4 digits', 'merchboard' ) }
						>
							{ ( control ) => (
								<input
									{ ...control }
									type="text"
									inputMode="numeric"
									autoComplete="off"
									maxLength={ 4 }
									className="merchboard-input"
									required
									value={ values.registration }
									onChange={ ( event ) =>
										set( {
											registration: event.target.value,
										} )
									}
								/>
							) }
						</Field>
						<Field
							label={ __( 'Account number', 'merchboard' ) }
							help={ __( '1–10 digits', 'merchboard' ) }
						>
							{ ( control ) => (
								<input
									{ ...control }
									type="text"
									inputMode="numeric"
									autoComplete="off"
									maxLength={ 14 }
									className="merchboard-input"
									required
									value={ values.account }
									onChange={ ( event ) =>
										set( { account: event.target.value } )
									}
								/>
							) }
						</Field>
					</>
				) }
				{ method === 'paypal' && (
					<Field label={ __( 'PayPal email address', 'merchboard' ) }>
						{ ( control ) => (
							<input
								{ ...control }
								type="email"
								autoComplete="off"
								className="merchboard-input"
								required
								value={ values.email }
								onChange={ ( event ) =>
									set( { email: event.target.value } )
								}
							/>
						) }
					</Field>
				) }

				<p className="merchboard-field__help">
					{ method === 'gift_card'
						? __(
								'You receive a gift card for the shop of the requested amount, without VAT. It appears here when we have created it. Treat its code like cash: anyone who has it can use it.',
								'merchboard'
							)
						: __(
								'Your payment details are encrypted, used only for this payout and deleted when it has been handled. Afterwards only a masked version is shown.',
								'merchboard'
							) }
				</p>

				<div className="merchboard-form__actions">
					<Button type="submit" isBusy={ busy }>
						{ busy
							? __( 'Sending…', 'merchboard' )
							: __( 'Request payout', 'merchboard' ) }
					</Button>
				</div>
			</form>
		</Card>
	);
}
