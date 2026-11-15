import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import type { ApiError } from '../../api/client';
import { getMerchCommand, saveMerchCommand } from '../../api/merchCommand';
import type {
	MerchCommandState,
	MerchCommandValues,
} from '../../api/merchCommand';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Skeleton } from '../../components/Skeleton';
import { useApi } from '../../hooks/useApi';

/**
 * The text of a field error code.
 *
 * @param code Code from the server.
 */
function fieldError( code: string ): string {
	switch ( code ) {
		case 'too_long':
			return __( 'Use at most 200 characters.', 'merchboard' );
		case 'is_command':
			return __( 'The answer must not start with !merch.', 'merchboard' );
		case 'invalid':
			return __( 'Choose between 10 and 300 seconds.', 'merchboard' );
		default:
			return __( 'Check this field.', 'merchboard' );
	}
}

/**
 * The !merch command on Alerts → Chatbot (ADR-113 pkt. 5): when anyone
 * writes !merch in the creator's Twitch or Kick chat, the bot answers with
 * the creator's text and the link to the creator's collection.
 *
 * @param props        Props.
 * @param props.onSave Called with a message after a save.
 */
export function MerchCommandCard( {
	onSave,
}: {
	readonly onSave: ( message: string ) => void;
} ) {
	const { state, retry } = useApi( getMerchCommand );
	const [ current, setCurrent ] = useState< MerchCommandState | null >(
		null
	);
	const [ values, setValues ] = useState< MerchCommandValues | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< ApiError | null >( null );

	useEffect( () => {
		if ( state.status === 'success' ) {
			setCurrent( state.data );
			setValues( state.data );
		}
	}, [ state ] );

	const title = __( 'The !merch command', 'merchboard' );

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
					label={ __( 'Loading the !merch command…', 'merchboard' ) }
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
		const result = await saveMerchCommand( values );
		setBusy( false );

		if ( result.ok ) {
			setCurrent( result.data );
			setValues( result.data );
			onSave( __( 'The !merch command was saved.', 'merchboard' ) );
		} else {
			setError( result.error );
		}
	};

	return (
		<Card title={ title }>
			<p className="merchboard-field__help">
				{ __(
					'When anyone writes !merch in your Twitch or Kick chat, the bot answers with your text and the link to your collection in the shop. Write $Link where the link should be; without it, the link comes last.',
					'merchboard'
				) }
			</p>
			{ current.channels.length === 0 && (
				<Alert tone="info">
					{ __(
						'Add the bot to your Twitch or Kick channel above; the command works in the channels the bot is in.',
						'merchboard'
					) }
				</Alert>
			) }
			{ error !== null && Object.keys( errors ).length === 0 && (
				<ApiErrorState error={ error } />
			) }
			<form
				className="merchboard-community-form"
				noValidate
				onSubmit={ ( event ) => void submit( event ) }
			>
				<label
					className="merchboard-payouts__check"
					htmlFor="merchboard-merch-command-enabled"
				>
					<input
						id="merchboard-merch-command-enabled"
						type="checkbox"
						checked={ values.enabled }
						onChange={ ( event ) =>
							setValues( {
								...values,
								enabled: event.target.checked,
							} )
						}
					/>{ ' ' }
					{ __( 'Answer !merch in my chat', 'merchboard' ) }
				</label>
				<div className="merchboard-field">
					<label
						className="merchboard-field__label"
						htmlFor="merchboard-merch-command-text"
					>
						{ __( 'Answer', 'merchboard' ) }
					</label>
					<input
						id="merchboard-merch-command-text"
						className="merchboard-input"
						type="text"
						value={ values.text }
						maxLength={ current.maxText }
						placeholder={ current.defaultText }
						onChange={ ( event ) =>
							setValues( { ...values, text: event.target.value } )
						}
					/>
					{ errors.text !== undefined && (
						<p className="merchboard-field__error">
							{ fieldError( errors.text ) }
						</p>
					) }
				</div>
				<div className="merchboard-field">
					<label
						className="merchboard-field__label"
						htmlFor="merchboard-merch-command-cooldown"
					>
						{ __( 'At most once every … seconds', 'merchboard' ) }
					</label>
					<input
						id="merchboard-merch-command-cooldown"
						className="merchboard-input"
						type="number"
						min={ current.cooldownLimits[ 0 ] }
						max={ current.cooldownLimits[ 1 ] }
						value={ values.cooldown }
						onChange={ ( event ) =>
							setValues( {
								...values,
								cooldown:
									Number.parseInt( event.target.value, 10 ) ||
									0,
							} )
						}
					/>
					{ errors.cooldown !== undefined && (
						<p className="merchboard-field__error">
							{ fieldError( errors.cooldown ) }
						</p>
					) }
				</div>
				<p className="merchboard-field__help">
					{ __( 'The chat sees:', 'merchboard' ) }{ ' ' }
					<q>{ current.example }</q>
				</p>
				{ current.channels.length > 0 && (
					<p className="merchboard-field__help">
						{ sprintf(
							/* translators: %s: the platforms, e.g. "twitch, kick". */
							__( 'Works in: %s', 'merchboard' ),
							current.channels
								.map( ( channel ) =>
									channel === 'kick' ? 'Kick' : 'Twitch'
								)
								.join( ', ' )
						) }
					</p>
				) }
				<div className="merchboard-community-form__actions">
					<Button type="submit" isBusy={ busy }>
						{ __( 'Save', 'merchboard' ) }
					</Button>
				</div>
			</form>
		</Card>
	);
}
