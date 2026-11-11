import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { connectChatbot } from '../../api/chatbot';
import type { ApiError } from '../../api/client';
import {
	getGifting,
	pauseGifting,
	saveGifting,
	startGifts,
} from '../../api/gifting';
import type {
	GiftRow,
	GiftStatus,
	GiftingState,
	GiftingValues,
} from '../../api/gifting';
import { Alert } from '../../components/Alert';
import { ApiErrorState } from '../../components/ApiErrorState';
import { Button } from '../../components/Button';
import { Card } from '../../components/Card';
import { Skeleton } from '../../components/Skeleton';
import { Table } from '../../components/Table';
import { Toast } from '../../components/Toast';
import { useConfig } from '../../app/config-context';
import { useApi } from '../../hooks/useApi';

/**
 * The text of a field error code.
 *
 * @param code Code from the server.
 */
function fieldError( code: string ): string {
	switch ( code ) {
		case 'not_connected':
			return __(
				'Connect your Twitch channel before you switch gifting on.',
				'merchboard'
			);
		case 'invalid':
			return __( 'Choose between 20 and 180 seconds.', 'merchboard' );
		default:
			return __( 'Check this field.', 'merchboard' );
	}
}

/**
 * The label of a gift's status.
 *
 * @param status Status.
 */
function statusText( status: GiftStatus ): string {
	switch ( status ) {
		case 'waiting':
			return __( 'Waiting for a draw', 'merchboard' );
		case 'entering':
			return __( 'Open for !enter', 'merchboard' );
		case 'won':
			return __( 'Won, waiting for the winner', 'merchboard' );
		case 'claimed':
			return __( 'Claimed by the winner', 'merchboard' );
		case 'returned':
			return __( 'Given back to the buyer', 'merchboard' );
		case 'cancelled':
			return __( 'Cancelled', 'merchboard' );
	}
}

/**
 * A UTC time of the API in the site's language and the viewer's time zone.
 *
 * @param utc    `YYYY-MM-DD HH:MM:SS` in UTC.
 * @param locale WordPress locale, e.g. da_DK.
 */
function timeText( utc: string, locale: string ): string {
	try {
		return new Intl.DateTimeFormat( locale.replace( '_', '-' ), {
			dateStyle: 'medium',
			timeStyle: 'short',
		} ).format( new Date( `${ utc.replace( ' ', 'T' ) }Z` ) );
	} catch {
		return utc.slice( 0, 16 );
	}
}

/**
 * Whether the creator is live, or counts as live in the test mode.
 *
 * @param state Gifting state.
 */
function liveText( state: GiftingState ): string {
	if ( state.test ) {
		return __( 'Test mode: you count as live.', 'merchboard' );
	}

	return state.live === true
		? __( 'You are live now.', 'merchboard' )
		: __( 'You are not live.', 'merchboard' );
}

/**
 * Community Features → Twitch gifting (phase 44, ADR-107): viewers buy a
 * gift on the creator's product page while the creator is live; the bot
 * draws a winner among those who type `!enter` in the chat, and the winner
 * claims the gift with a Twitch login. The creator switches gifting on or
 * off, pauses it (also `!gift pause` in the chat), chooses the window and
 * the language of the bot's texts, and starts gifts that wait.
 */
export function TwitchGiftingSection() {
	const { state, retry } = useApi( getGifting );
	const locale = useConfig().locale;
	const [ current, setCurrent ] = useState< GiftingState | null >( null );
	const [ values, setValues ] = useState< GiftingValues | null >( null );
	const [ busy, setBusy ] = useState< 'save' | 'pause' | 'start' | null >(
		null
	);
	const [ connecting, setConnecting ] = useState( false );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ toast, setToast ] = useState< string | null >( null );
	const dismissToast = useCallback( () => setToast( null ), [] );

	useEffect( () => {
		if ( state.status === 'success' ) {
			setCurrent( state.data );
			setValues( state.data );
		}
	}, [ state ] );

	const title = __( 'Twitch gifting', 'merchboard' );

	if (
		state.status === 'loading' ||
		( state.status === 'success' && values === null )
	) {
		return (
			<Card title={ title }>
				<Skeleton
					label={ __( 'Loading gifting…', 'merchboard' ) }
					lines={ 3 }
				/>
			</Card>
		);
	}

	if ( state.status === 'error' || values === null || current === null ) {
		return (
			<Card title={ title }>
				{ state.status === 'error' && (
					<ApiErrorState error={ state.error } onRetry={ retry } />
				) }
			</Card>
		);
	}

	const errors = error?.fields ?? {};
	const waiting = current.gifts.filter(
		( gift ) => gift.status === 'waiting'
	).length;

	const connect = async () => {
		setConnecting( true );
		setError( null );
		const answer = await connectChatbot( 'twitch' );

		if ( answer.ok ) {
			window.location.assign( answer.data );
			return;
		}

		setConnecting( false );
		setError( answer.error );
	};

	const submit = async ( event: FormEvent ) => {
		event.preventDefault();
		setBusy( 'save' );
		setError( null );
		const result = await saveGifting( values );
		setBusy( null );

		if ( result.ok ) {
			setCurrent( result.data );
			setValues( result.data );
			setToast( __( 'Twitch gifting saved.', 'merchboard' ) );
		} else {
			setError( result.error );
		}
	};

	const pause = async () => {
		setBusy( 'pause' );
		setError( null );
		const result = await pauseGifting( ! current.paused );
		setBusy( null );

		if ( result.ok ) {
			setCurrent( result.data );
			setToast(
				result.data.paused
					? __(
							'Gifting is paused. Your chat was told.',
							'merchboard'
						)
					: __(
							'Gifting runs again. Your chat was told.',
							'merchboard'
						)
			);
		} else {
			setError( result.error );
		}
	};

	const start = async () => {
		setBusy( 'start' );
		setError( null );
		const result = await startGifts();
		setBusy( null );

		if ( result.ok ) {
			setCurrent( result.data );
			setToast(
				result.data.started === true
					? __(
							'The next gift is announced in your chat now.',
							'merchboard'
						)
					: __(
							'No gift could start. You must be live on Twitch, and a gift must be waiting.',
							'merchboard'
						)
			);
		} else {
			setError( result.error );
		}
	};

	const columns = [
		{
			key: 'created',
			header: __( 'Bought', 'merchboard' ),
			render: ( gift: GiftRow ) => timeText( gift.createdAt, locale ),
		},
		{
			key: 'product',
			header: __( 'Product', 'merchboard' ),
			render: ( gift: GiftRow ) =>
				gift.product === '' ? '–' : gift.product,
		},
		{
			key: 'buyer',
			header: __( 'From', 'merchboard' ),
			render: ( gift: GiftRow ) =>
				gift.buyer ?? __( 'Anonymous', 'merchboard' ),
		},
		{
			key: 'status',
			header: __( 'Status', 'merchboard' ),
			render: ( gift: GiftRow ) =>
				gift.status === 'won' && gift.claimDeadline !== null
					? sprintf(
							/* translators: 1: status, 2: deadline. */
							__( '%1$s (until %2$s)', 'merchboard' ),
							statusText( gift.status ),
							timeText( gift.claimDeadline, locale )
						)
					: statusText( gift.status ),
		},
		{
			key: 'winner',
			header: __( 'Winner', 'merchboard' ),
			render: ( gift: GiftRow ) => gift.winner ?? '–',
		},
		{
			key: 'draws',
			header: __( 'Draws', 'merchboard' ),
			render: ( gift: GiftRow ) => String( gift.draws ),
			align: 'end' as const,
		},
	];

	return (
		<>
			<Card title={ title }>
				<p className="merchboard-field__help">
					{ __(
						'While you are live on Twitch, viewers can buy one of your products as a gift for the chat. The bot announces the gift, everyone who types !enter takes part, and a random viewer wins. The winner logs in with Twitch in the shop, chooses size and colour and gets the product with up to 50 kr. of the shipping paid. The gift counts as a normal sale for you.',
						'merchboard'
					) }
				</p>
				{ current.channel === null ? (
					<Alert tone="warning">
						{ current.twitch
							? __(
									'Connect your Twitch channel, so the bot can run the gifts in your chat.',
									'merchboard'
								)
							: __(
									'Twitch is not set up in the shop yet. Please contact us.',
									'merchboard'
								) }
						{ current.twitch && (
							<p>
								<Button
									type="button"
									variant="secondary"
									isBusy={ connecting }
									onClick={ () => void connect() }
								>
									{ __( 'Connect Twitch', 'merchboard' ) }
								</Button>
							</p>
						) }
					</Alert>
				) : (
					<p className="merchboard-field__help">
						{ sprintf(
							/* translators: %s: the creator's Twitch channel. */
							__( 'Twitch channel: %s', 'merchboard' ),
							current.channel
						) }
						{ ' · ' }
						{ liveText( current ) }
					</p>
				) }
				{ current.test && (
					<Alert tone="warning">
						{ __(
							'An administrator has turned on the test mode: you count as live on Twitch, so viewers can buy gifts and gifts are drawn in your chat even when you are not streaming.',
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
						htmlFor="merchboard-gifting-enabled"
					>
						<input
							id="merchboard-gifting-enabled"
							type="checkbox"
							checked={ values.enabled }
							onChange={ ( event ) =>
								setValues( {
									...values,
									enabled: event.target.checked,
								} )
							}
						/>{ ' ' }
						{ __( 'Twitch gifting on', 'merchboard' ) }
					</label>
					{ current.enabled && ! values.enabled && (
						<p className="merchboard-field__help">
							{ __(
								'When you switch gifting off, gifts that have not been won yet go back to their buyers with a gift code.',
								'merchboard'
							) }
						</p>
					) }
					{ errors.enabled !== undefined && (
						<p className="merchboard-field__error">
							{ fieldError( errors.enabled ) }
						</p>
					) }
					<div className="merchboard-field">
						<label
							className="merchboard-field__label"
							htmlFor="merchboard-gifting-window"
						>
							{ __(
								'Seconds the chat can type !enter',
								'merchboard'
							) }
						</label>
						<input
							id="merchboard-gifting-window"
							className="merchboard-input"
							type="number"
							min={ current.windowMin }
							max={ current.windowMax }
							step={ 1 }
							value={ values.window }
							onChange={ ( event ) =>
								setValues( {
									...values,
									window: Math.round(
										Number( event.target.value ) || 0
									),
								} )
							}
						/>
						{ errors.window !== undefined && (
							<p className="merchboard-field__error">
								{ fieldError( errors.window ) }
							</p>
						) }
					</div>
					<fieldset className="merchboard-field">
						<legend className="merchboard-field__label">
							{ __(
								"Language of the bot's texts",
								'merchboard'
							) }
						</legend>
						<label htmlFor="merchboard-gifting-da">
							<input
								id="merchboard-gifting-da"
								type="radio"
								name="merchboard-gifting-language"
								checked={ values.language === 'da' }
								onChange={ () =>
									setValues( { ...values, language: 'da' } )
								}
							/>{ ' ' }
							{ __( 'Danish', 'merchboard' ) }
						</label>{ ' ' }
						<label htmlFor="merchboard-gifting-en">
							<input
								id="merchboard-gifting-en"
								type="radio"
								name="merchboard-gifting-language"
								checked={ values.language === 'en' }
								onChange={ () =>
									setValues( { ...values, language: 'en' } )
								}
							/>{ ' ' }
							{ __( 'English', 'merchboard' ) }
						</label>
						{ errors.language !== undefined && (
							<p className="merchboard-field__error">
								{ fieldError( errors.language ) }
							</p>
						) }
					</fieldset>
					<label
						className="merchboard-payouts__check"
						htmlFor="merchboard-gifting-confirm"
					>
						<input
							id="merchboard-gifting-confirm"
							type="checkbox"
							checked={ values.confirm }
							onChange={ ( event ) =>
								setValues( {
									...values,
									confirm: event.target.checked,
								} )
							}
						/>{ ' ' }
						{ __( 'Confirm entrants in the chat', 'merchboard' ) }
					</label>
					<p className="merchboard-field__help">
						{ __(
							'The bot confirms who is in the draw, also those who type !enter again, in one message at most every 10 seconds.',
							'merchboard'
						) }
					</p>
					<div className="merchboard-community-form__actions">
						<Button type="submit" isBusy={ busy === 'save' }>
							{ __( 'Save', 'merchboard' ) }
						</Button>
					</div>
				</form>
			</Card>
			{ current.enabled && (
				<Card title={ __( 'In the stream', 'merchboard' ) }>
					{ current.paused && (
						<Alert tone="warning">
							{ __(
								'Gifting is paused: no gifts can be bought, and no draws start.',
								'merchboard'
							) }
						</Alert>
					) }
					<p className="merchboard-field__help">
						{ __(
							'A gift bought while you are live starts right away. Gifts bought after the stream, and gifts that must be drawn again, wait until you start them, here or with !gift start in the chat. You and your moderators can also type !gift pause and !gift resume.',
							'merchboard'
						) }
					</p>
					<p className="merchboard-field__help">
						{ sprintf(
							/* translators: %d: number of gifts waiting for a draw. */
							__( 'Gifts waiting: %d', 'merchboard' ),
							waiting
						) }
					</p>
					<div className="merchboard-community-form__actions">
						<Button
							type="button"
							variant="secondary"
							isBusy={ busy === 'pause' }
							onClick={ () => void pause() }
						>
							{ current.paused
								? __( 'Resume gifting', 'merchboard' )
								: __( 'Pause gifting', 'merchboard' ) }
						</Button>
						<Button
							type="button"
							isBusy={ busy === 'start' }
							disabled={ current.paused || waiting === 0 }
							onClick={ () => void start() }
						>
							{ __( 'Start waiting gifts', 'merchboard' ) }
						</Button>
					</div>
				</Card>
			) }
			<Card title={ __( 'Gifts', 'merchboard' ) }>
				<Table
					caption={ __( 'Your latest gifts', 'merchboard' ) }
					captionHidden
					columns={ columns }
					rows={ current.gifts }
					rowKey={ ( gift ) => gift.id }
					empty={
						<p className="merchboard-field__help">
							{ __( 'No gifts yet.', 'merchboard' ) }
						</p>
					}
				/>
			</Card>
			<Toast message={ toast } onDismiss={ dismissToast } />
		</>
	);
}
