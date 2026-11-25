import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import type { ApiError } from '../../api/client';
import { getThankYouNote, saveThankYouNote } from '../../api/thankYouNote';
import type {
	ThankYouNoteState,
	ThankYouNoteValues,
} from '../../api/thankYouNote';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Skeleton } from '../../components/Skeleton';
import { Toast } from '../../components/Toast';
import { useApi } from '../../hooks/useApi';

/**
 * The text of a field error code.
 *
 * @param code     Code from the server.
 * @param maxText  The longest text.
 * @param maxLines The most lines.
 */
function fieldError( code: string, maxText: number, maxLines: number ): string {
	switch ( code ) {
		case 'too_long':
			return sprintf(
				/* translators: %d: the most characters. */
				__( 'Use at most %d characters.', 'merchboard' ),
				maxText
			);
		case 'too_many_lines':
			return sprintf(
				/* translators: %d: the most lines. */
				__( 'Use at most %d lines.', 'merchboard' ),
				maxLines
			);
		case 'has_link':
			return __(
				'Leave out links: the note is printed on paper.',
				'merchboard'
			);
		case 'required':
			return __( 'Write the note, or switch it off.', 'merchboard' );
		default:
			return __( 'Check this field.', 'merchboard' );
	}
}

/**
 * The thank-you note on Profile (phase 58): a short text the creator's
 * customers find on the packing slip of an order with the creator's
 * products. One note per creator; it is used as it is, without approval.
 */
export function ThankYouNoteCard() {
	const { state, retry } = useApi( getThankYouNote );
	const [ current, setCurrent ] = useState< ThankYouNoteState | null >(
		null
	);
	const [ values, setValues ] = useState< ThankYouNoteValues | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );

	useEffect( () => {
		if ( state.status === 'success' ) {
			setCurrent( state.data );
			setValues( state.data );
		}
	}, [ state ] );

	if ( state.status === 'error' && state.error.status === 404 ) {
		// The shop's features are off (no WooCommerce): there are no parcels.
		return null;
	}

	const title = __( 'Thank-you note on the packing slip', 'merchboard' );

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
					label={ __( 'Loading the thank-you note…', 'merchboard' ) }
					lines={ 2 }
				/>
			</Card>
		);
	}

	const errors = error?.fields ?? {};

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setBusy( true );
		setError( null );
		const result = await saveThankYouNote( values );
		setBusy( false );

		if ( result.ok ) {
			setCurrent( result.data );
			setValues( result.data );
			setToast( __( 'The thank-you note was saved.', 'merchboard' ) );
		} else {
			setError( result.error );
		}
	};

	return (
		<Card title={ title }>
			<p className="merchboard-field__help">
				{ __(
					'A short greeting from you, printed on the packing slip that goes in the parcel to customers who buy your products. If an order has products from several creators, each greeting is printed.',
					'merchboard'
				) }
			</p>
			{ error !== null && Object.keys( errors ).length === 0 && (
				<ApiErrorState error={ error } />
			) }
			<form
				className="merchboard-community-form merchboard-thank-you-note"
				noValidate
				onSubmit={ ( event ) => void submit( event ) }
			>
				<label
					className="merchboard-payouts__check"
					htmlFor="merchboard-thank-you-note-enabled"
				>
					<input
						id="merchboard-thank-you-note-enabled"
						type="checkbox"
						checked={ values.enabled }
						onChange={ ( event ) =>
							setValues( {
								...values,
								enabled: event.target.checked,
							} )
						}
					/>{ ' ' }
					{ __( 'Print my thank-you note', 'merchboard' ) }
				</label>
				<div className="merchboard-field">
					<label
						className="merchboard-field__label"
						htmlFor="merchboard-thank-you-note-text"
					>
						{ __( 'Note', 'merchboard' ) }
					</label>
					<textarea
						id="merchboard-thank-you-note-text"
						className="merchboard-input merchboard-input--textarea"
						rows={ 4 }
						value={ values.text }
						maxLength={ current.maxText }
						placeholder={ __(
							'Thank you for supporting me!',
							'merchboard'
						) }
						onChange={ ( event ) =>
							setValues( { ...values, text: event.target.value } )
						}
					/>
					<p className="merchboard-field__help">
						{ sprintf(
							/* translators: 1: characters used, 2: the most characters. */
							__( '%1$d of %2$d characters', 'merchboard' ),
							values.text.length,
							current.maxText
						) }
					</p>
					{ errors.text !== undefined && (
						<p className="merchboard-field__error">
							{ fieldError(
								errors.text,
								current.maxText,
								current.maxLines
							) }
						</p>
					) }
				</div>
				<div className="merchboard-community-form__actions">
					<Button type="submit" isBusy={ busy }>
						{ __( 'Save', 'merchboard' ) }
					</Button>
				</div>
			</form>
			<Toast message={ toast } onDismiss={ dismissToast } />
		</Card>
	);
}
