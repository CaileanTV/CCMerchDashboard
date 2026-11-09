import { useCallback, useEffect, useId, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import {
	BILLING_DETAILS_CHANGED,
	BILLING_FIELDS,
	getBillingDetails,
	lookupCvr,
	submitBillingDetails,
} from '../../api/billing';
import type {
	BillingDetailsState,
	BillingField,
	BillingValues,
} from '../../api/billing';
import type { ApiError } from '../../api/client';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Field } from '../../components/Field';
import { Skeleton } from '../../components/Skeleton';
import { Toast } from '../../components/Toast';
import { useApi } from '../../hooks/useApi';
import { CprInfo } from './CprInfo';

/** Fields the creator must fill in (as the server checks). */
const REQUIRED: readonly BillingField[] = [
	'legal_name',
	'address_1',
	'postcode',
	'city',
	'country',
];

/** Longest value per field (as the server checks). */
const MAX: Readonly< Record< BillingField, number > > = {
	legal_name: 191,
	address_1: 191,
	address_2: 191,
	postcode: 20,
	city: 100,
	country: 100,
	cvr: 8,
};

/**
 * The label and help text of a field.
 *
 * @param field Field.
 */
function label( field: BillingField ): { label: string; help?: string } {
	switch ( field ) {
		case 'legal_name':
			return {
				label: __( 'Name or company name', 'merchboard' ),
				help: __(
					'Your full legal name, or your company name if you are paid as a company.',
					'merchboard'
				),
			};
		case 'address_1':
			return { label: __( 'Address', 'merchboard' ) };
		case 'address_2':
			return { label: __( 'Address, line 2 (optional)', 'merchboard' ) };
		case 'postcode':
			return { label: __( 'Postcode', 'merchboard' ) };
		case 'city':
			return { label: __( 'City', 'merchboard' ) };
		case 'country':
			return { label: __( 'Country', 'merchboard' ) };
		case 'cvr':
			return {
				label: __( 'CVR number', 'merchboard' ),
				help: __( 'Eight digits (a CVR or SE number).', 'merchboard' ),
			};
	}
}

/**
 * The message of a field error code.
 *
 * @param code Error code from the server or the form.
 */
function fieldError( code: string ): string {
	switch ( code ) {
		case 'required':
			return __( 'Please fill in this field.', 'merchboard' );
		case 'too_long':
			return __( 'This is too long.', 'merchboard' );
		case 'invalid_cvr':
			return __( 'A CVR number is eight digits.', 'merchboard' );
		case 'invalid_cpr':
			return __(
				'Please enter a valid CPR number (10 digits, written DDMMYY-XXXX).',
				'merchboard'
			);
		case 'unavailable':
			return __(
				'The CPR number cannot be saved right now. Please contact us.',
				'merchboard'
			);
		default:
			return __( 'Please check this field.', 'merchboard' );
	}
}

/**
 * The form's starting values: the saved master data, else empty with
 * Denmark as the country.
 *
 * @param state The server's state.
 */
function initial( state: BillingDetailsState ): BillingValues {
	const values = state.confirmed;

	// Registered before phase 42 (no type) with a CVR number: a business, so the number is kept.
	if ( values !== null && values.type === '' && values.cvr !== '' ) {
		return { ...values, type: 'business' };
	}

	return (
		values ?? {
			legal_name: '',
			address_1: '',
			address_2: '',
			postcode: '',
			city: '',
			country: __( 'Denmark', 'merchboard' ),
			cvr: '',
			type: '',
		}
	);
}

/**
 * Checks the form like the server does, so most mistakes show at once.
 *
 * @param values   Values.
 * @param cpr      The typed CPR number ('' for none).
 * @param cprSaved Whether a CPR number is stored already.
 */
function validate(
	values: BillingValues,
	cpr: string,
	cprSaved: boolean
): Record< string, string > {
	const errors: Record< string, string > = {};

	if ( values.type !== 'business' ) {
		const digits = cpr.replace( /[\s\-\u2010-\u2015]+/g, '' );

		if ( digits === '' && ! cprSaved ) {
			errors.cpr = 'required';
		} else if ( digits !== '' && ! /^\d{10}$/.test( digits ) ) {
			errors.cpr = 'invalid_cpr';
		}
	}

	for ( const field of BILLING_FIELDS ) {
		const value = values[ field ].trim();

		if ( field === 'cvr' ) {
			const digits = value.replace( /\s+/g, '' );

			if ( values.type !== 'business' ) {
				continue;
			}

			if ( digits === '' ) {
				errors[ field ] = 'required';
			} else if ( ! /^\d{8}$/.test( digits ) ) {
				errors[ field ] = 'invalid_cvr';
			}
		} else if ( value.length > MAX[ field ] ) {
			errors[ field ] = 'too_long';
		} else if ( value === '' && REQUIRED.includes( field ) ) {
			errors[ field ] = 'required';
		}
	}

	return errors;
}

/**
 * Where the master data stand, in words.
 *
 * @param props       Props.
 * @param props.state The server's state.
 */
function StatusMessage( { state }: { readonly state: BillingDetailsState } ) {
	if ( state.confirmed !== null ) {
		return (
			<Alert tone="success">
				{ __(
					'Your master data are saved. If something changes, correct it here.',
					'merchboard'
				) }
			</Alert>
		);
	}

	return (
		<Alert tone={ state.requiredForPayout ? 'warning' : 'info' }>
			{ __(
				'Please fill in your master data. We need them for your settlement documents and before your first payout.',
				'merchboard'
			) }
		</Alert>
	);
}

/**
 * "Master data for settlement documents" (ADR-104): the creator's legal
 * name, address and CVR number, or a private person's CPR number, saved
 * with `POST /me/billing-details` and in force at once. The CPR number is
 * stored encrypted and never shown back; it is typed only to add or change
 * it. The first payout needs master data. Every rule is the server's; the
 * form only checks the same things first.
 */
export function BillingDetailsCard() {
	const { state, retry } = useApi( getBillingDetails );
	const [ current, setCurrent ] = useState< BillingDetailsState | null >(
		null
	);
	const [ values, setValues ] = useState< BillingValues | null >( null );
	const [ cpr, setCpr ] = useState( '' );
	const cprId = useId();
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ failure, setFailure ] = useState< ApiError | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );
	const businessId = useId();
	const [ looking, setLooking ] = useState( false );
	const [ lookupError, setLookupError ] = useState< string | null >( null );

	useEffect( () => {
		if ( state.status === 'success' ) {
			setCurrent( state.data );
			setValues( initial( state.data ) );
		}
	}, [ state ] );

	const title = __( 'Master data for settlement documents', 'merchboard' );

	if ( state.status === 'error' ) {
		return (
			<Card title={ title }>
				<ApiErrorState error={ state.error } onRetry={ retry } />
			</Card>
		);
	}

	if ( current === null || values === null ) {
		return (
			<Card title={ title }>
				<Skeleton
					label={ __( 'Loading your master data…', 'merchboard' ) }
					lines={ 3 }
				/>
			</Card>
		);
	}

	const change = ( field: BillingField, value: string ) => {
		setValues( { ...values, [ field ]: value } );
		setErrors( ( previous ) => {
			const next = { ...previous };
			delete next[ field ];

			return next;
		} );
	};

	const fillFromCvr = async () => {
		const cvr = values.cvr.replace( /\s+/g, '' );
		setLookupError( null );

		if ( ! /^\d{8}$/.test( cvr ) ) {
			setErrors( ( previous ) => ( {
				...previous,
				cvr: 'invalid_cvr',
			} ) );

			return;
		}

		setLooking( true );
		const result = await lookupCvr( cvr );
		setLooking( false );

		if ( result.ok ) {
			// Typed meanwhile stays; empty answers never erase what is there.
			setValues( ( shown ) => {
				const next = { ...( shown ?? values ), cvr };

				for ( const [ key, value ] of Object.entries( result.data ) ) {
					if ( value !== '' ) {
						next[ key as keyof typeof result.data ] = value;
					}
				}

				return next;
			} );
			setErrors( {} );
			setToast(
				__(
					'Filled in from the CVR register. Please check the details before you save them.',
					'merchboard'
				)
			);

			return;
		}

		setLookupError( cvrLookupError( result.error.reason ) );
	};

	const submit = async ( event: FormEvent< HTMLFormElement > ) => {
		event.preventDefault();
		const found = validate( values, cpr, current.cprSaved );
		setErrors( found );
		setFailure( null );

		if ( Object.keys( found ).length > 0 ) {
			return;
		}

		setBusy( true );
		const business = values.type === 'business';
		const result = await submitBillingDetails(
			{
				...values,
				type: business ? 'business' : 'private',
				cvr: business ? values.cvr : '',
			},
			business ? '' : cpr.trim()
		);
		setBusy( false );

		if ( result.ok ) {
			setCurrent( result.data );
			setValues( initial( result.data ) );
			setCpr( '' );
			setToast( __( 'Master data saved.', 'merchboard' ) );
			window.dispatchEvent( new Event( BILLING_DETAILS_CHANGED ) );

			return;
		}

		if ( Object.keys( result.error.fields ).length > 0 ) {
			setErrors( { ...result.error.fields } );
		} else {
			setFailure( result.error );
		}
	};

	return (
		<Card title={ title }>
			<StatusMessage state={ current } />
			<form
				className="merchboard-billing-form"
				noValidate
				onSubmit={ submit }
			>
				<label
					className="merchboard-payouts__check"
					htmlFor={ businessId }
				>
					<input
						id={ businessId }
						type="checkbox"
						checked={ values.type === 'business' }
						onChange={ ( event ) =>
							setValues( {
								...values,
								type: event.target.checked
									? 'business'
									: 'private',
							} )
						}
					/>{ ' ' }
					{ __(
						'I am a registered business with a CVR number',
						'merchboard'
					) }
				</label>
				{ values.type !== 'business' && (
					<>
						<p className="merchboard-field__help">
							{ __(
								'Without a CVR number you are paid as a private person, and we need your CPR number.',
								'merchboard'
							) }
						</p>
						<CprInfo />
						<div className="merchboard-field">
							<label
								className="merchboard-field__label"
								htmlFor={ cprId }
							>
								{ __( 'CPR number', 'merchboard' ) }
							</label>
							<input
								id={ cprId }
								className="merchboard-input"
								type="text"
								name="cpr"
								value={ cpr }
								maxLength={ 14 }
								inputMode="numeric"
								autoComplete="off"
								required={ ! current.cprSaved }
								aria-describedby={ `${ cprId }-help` }
								aria-invalid={
									errors.cpr === undefined ? undefined : true
								}
								onChange={ ( event ) => {
									setCpr( event.target.value );
									setErrors( ( previous ) => {
										const next = { ...previous };
										delete next.cpr;

										return next;
									} );
								} }
							/>
							<p
								id={ `${ cprId }-help` }
								className="merchboard-field__help"
							>
								{ current.cprSaved
									? __(
											'Your CPR number is stored encrypted and is not shown. Type it only to change it.',
											'merchboard'
										)
									: __(
											'10 digits, written DDMMYY-XXXX.',
											'merchboard'
										) }
							</p>
							{ errors.cpr !== undefined && (
								<p className="merchboard-field__error">
									{ fieldError( errors.cpr ) }
								</p>
							) }
						</div>
					</>
				) }
				{ values.type === 'business' && (
					<div className="merchboard-billing-form__cvr">
						<Field
							label={ label( 'cvr' ).label }
							help={ label( 'cvr' ).help ?? '' }
							{ ...( errors.cvr === undefined
								? {}
								: { error: fieldError( errors.cvr ) } ) }
						>
							{ ( control ) => (
								<input
									{ ...control }
									className="merchboard-input"
									type="text"
									name="cvr"
									value={ values.cvr }
									maxLength={ 20 }
									inputMode="numeric"
									autoComplete="off"
									required
									onChange={ ( event ) =>
										change( 'cvr', event.target.value )
									}
								/>
							) }
						</Field>
						{ current.cvrLookup && (
							<Button
								type="button"
								variant="secondary"
								isBusy={ looking }
								onClick={ () => void fillFromCvr() }
							>
								{ __(
									'Fill in from the CVR number',
									'merchboard'
								) }
							</Button>
						) }
						{ lookupError !== null && (
							<Alert tone="warning">{ lookupError }</Alert>
						) }
					</div>
				) }
				{ BILLING_FIELDS.filter( ( field ) => field !== 'cvr' ).map(
					( field ) => {
						const text = label( field );

						return (
							<Field
								key={ field }
								label={ text.label }
								{ ...( text.help === undefined
									? {}
									: { help: text.help } ) }
								{ ...( errors[ field ] === undefined
									? {}
									: {
											error: fieldError(
												errors[ field ]
											),
										} ) }
							>
								{ ( control ) => (
									<input
										{ ...control }
										className="merchboard-input"
										type="text"
										name={ field }
										value={ values[ field ] }
										maxLength={ MAX[ field ] }
										autoComplete={ autoComplete( field ) }
										required={ REQUIRED.includes( field ) }
										onChange={ ( event ) =>
											change( field, event.target.value )
										}
									/>
								) }
							</Field>
						);
					}
				) }
				{ failure !== null && <ApiErrorState error={ failure } /> }
				<Button type="submit" isBusy={ busy }>
					{ __( 'Save master data', 'merchboard' ) }
				</Button>
			</form>
			<Toast message={ toast } onDismiss={ dismissToast } />
		</Card>
	);
}

/**
 * The message of a failed CVR lookup.
 *
 * @param reason The server's reason.
 */
function cvrLookupError( reason: string | undefined ): string {
	switch ( reason ) {
		case 'cvr_not_found':
			return __(
				'No company was found with this CVR number. Please check it, or fill in the details yourself.',
				'merchboard'
			);
		case 'invalid_cvr':
			return __( 'A CVR number is eight digits.', 'merchboard' );
		case 'cvr_lookup_limit':
			return __(
				'Too many lookups today. Please fill in the details yourself or try again tomorrow.',
				'merchboard'
			);
		default:
			return __(
				'The CVR register could not be reached right now. Please fill in the details yourself.',
				'merchboard'
			);
	}
}

/**
 * The browser's autofill hint of a field.
 *
 * @param field Field.
 */
function autoComplete( field: BillingField ): string {
	switch ( field ) {
		case 'legal_name':
			return 'name';
		case 'address_1':
			return 'address-line1';
		case 'address_2':
			return 'address-line2';
		case 'postcode':
			return 'postal-code';
		case 'city':
			return 'address-level2';
		case 'country':
			return 'country-name';
		case 'cvr':
			return 'off';
	}
}
